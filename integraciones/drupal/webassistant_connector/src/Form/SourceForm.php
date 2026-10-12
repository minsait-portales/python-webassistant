<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Normalizer\FieldRoles;
use Drupal\webassistant_connector\Normalizer\NormalizerManager;
use Drupal\webassistant_connector\Plugin\WebAssistant\Normalizer\ContentEntityNormalizer;

/**
 * Add/edit form of WebAssistant sources (design section 8.1).
 *
 * Form IDs: webassistant_source_add_form / webassistant_source_edit_form;
 * base form ID: webassistant_source_form (submodules alter it with
 * hook_form_webassistant_source_form_alter(), which also runs for the delete
 * and preview forms: check $form_state->getFormObject()->getOperation()).
 * Submodules store their settings with #entity_builders (third-party
 * settings): unknown form values are never copied to the source.
 *
 * Discovers fields with EntityFieldManager (DR-04.2), lists compatible types
 * only (FieldRoles), warns about sensitive fields (email, telephone,
 * password, IP, user references) and validates keys and patterns. The field
 * table and the bundles are rebuilt with AJAX when the entity type changes
 * (only new sources can change it). New sources start disabled so their
 * documents can be previewed first (amendment A9), and enabled sources may
 * not overlap another enabled source of the same knowledge base (A10).
 */
final class SourceForm extends EntityForm {

  use AutowireTrait;

  /**
   * Pattern of source machine names (never "_attachment", amendment A14).
   */
  public const ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

  /**
   * Pattern of metadata keys.
   */
  public const METADATA_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

  /**
   * Entity types that are never offered (internal or personal data).
   */
  private const EXCLUDED_ENTITY_TYPES = [
    'content_moderation_state',
    'file',
    'path_alias',
    'shortcut',
    'user',
    'workspace',
    'workspace_association',
  ];

  /**
   * HTML ID of the AJAX wrapper of the bundles and normalizer.
   */
  private const SCOPE_WRAPPER = 'webassistant-source-scope';

  /**
   * HTML ID of the AJAX wrapper of the field role table.
   */
  private const FIELDS_WRAPPER = 'webassistant-source-fields';

  /**
   * Roles that read the reference depth and target bundles.
   */
  private const REFERENCE_ROLES = [
    SourceInterface::ROLE_MAIN_CONTENT,
    SourceInterface::ROLE_CONTEXT,
    SourceInterface::ROLE_METADATA,
    SourceInterface::ROLE_FILTER,
  ];

  /**
   * Roles that read the metadata key.
   */
  private const KEY_ROLES = [
    SourceInterface::ROLE_METADATA,
    SourceInterface::ROLE_FILTER,
  ];

  /**
   * Roles whose values are sent to the platform (sensitivity warning).
   */
  private const EXPOSING_ROLES = [
    SourceInterface::ROLE_TITLE,
    SourceInterface::ROLE_MAIN_CONTENT,
    SourceInterface::ROLE_CONTEXT,
    SourceInterface::ROLE_METADATA,
    SourceInterface::ROLE_FILTER,
    SourceInterface::ROLE_URL,
    SourceInterface::ROLE_IMAGE,
  ];

  /**
   * Constructs a SourceForm.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizerManager $normalizerManager
   *   The normalizer plugin manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected NormalizerManager $normalizerManager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $source = $this->getSource();
    $entityTypeOptions = $this->entityTypeOptions();
    $entityTypeId = $this->selectedEntityTypeId($form_state, $entityTypeOptions);
    $sameType = $entityTypeId === $source->getTargetEntityTypeId();

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $source->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $source->id(),
      '#maxlength' => 64,
      '#machine_name' => [
        'exists' => [$this, 'exists'],
        'source' => ['label'],
      ],
      '#disabled' => !$source->isNew(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $source->isNew() ? FALSE : $source->status(),
      '#description' => $this->t('Only enabled sources send documents to the platform. New sources start disabled: preview their documents first, then enable them.'),
    ];

    $form['scope'] = [
      '#type' => 'details',
      '#title' => $this->t('Content to index'),
      '#open' => TRUE,
    ];
    if (!isset($entityTypeOptions[$entityTypeId])) {
      // The stored entity type no longer exists or is not offered: keep it
      // visible so the administrator understands why the source is broken.
      $entityTypeOptions[$entityTypeId] = $entityTypeId;
    }
    $form['scope']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $entityTypeOptions,
      '#default_value' => $entityTypeId,
      '#required' => TRUE,
      '#disabled' => !$source->isNew(),
      '#description' => $source->isNew()
        ? $this->t('Content entity type whose items become documents.')
        : $this->t('The entity type of a source cannot be changed: add another source instead.'),
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'event' => 'change',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Loading fields...')],
      ],
      '#limit_validation_errors' => [['entity_type']],
      '#webassistant_source_refresh' => TRUE,
    ];
    $form['scope']['dependent'] = [
      '#type' => 'container',
      '#attributes' => ['id' => self::SCOPE_WRAPPER],
    ];
    $bundles = $this->bundleOptions($entityTypeId);
    if ($bundles !== []) {
      $form['scope']['dependent']['bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Bundles'),
        '#options' => $bundles,
        '#default_value' => $sameType ? array_values(array_intersect($source->getBundles(), array_keys($bundles))) : [],
        '#description' => $this->t('Leave empty to index every bundle, including the ones created later.'),
      ];
    }
    $normalizers = $this->normalizerManager->getOptions($entityTypeId);
    $normalizer = $source->getNormalizerId();
    $form['scope']['dependent']['normalizer'] = [
      '#type' => 'select',
      '#title' => $this->t('Normalizer'),
      '#options' => $normalizers,
      '#default_value' => isset($normalizers[$normalizer]) ? $normalizer : (isset($normalizers['content_entity']) ? 'content_entity' : array_key_first($normalizers)),
      '#required' => TRUE,
      '#description' => $this->t('Converts each translation into canonical documents.'),
    ];
    $form['scope']['langcodes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Languages'),
      '#options' => $this->languageOptions(),
      '#default_value' => $source->getLangcodes(),
      '#description' => $this->t('Leave empty to index every translation.'),
    ];

    $form['destination'] = [
      '#type' => 'details',
      '#title' => $this->t('Destination'),
      '#open' => TRUE,
    ];
    $form['destination']['knowledge_base'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Knowledge base'),
      '#default_value' => $source->getKnowledgeBase(),
      '#maxlength' => 63,
      '#size' => 32,
      '#required' => TRUE,
      '#description' => $this->t('Key of the knowledge base of the platform: a lowercase letter followed by 1 to 62 lowercase letters, digits, hyphens or underscores.'),
    ];
    $form['destination']['document_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Document type'),
      '#default_value' => $source->getDocumentType(),
      '#maxlength' => 41,
      '#size' => 32,
      '#required' => TRUE,
      '#description' => $this->t('For example page, guide, faq or product: a lowercase letter followed by 1 to 40 lowercase letters, digits or underscores.'),
    ];
    $form['destination']['site_scoped'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Limit the documents to this site'),
      '#default_value' => $source->isSiteScoped(),
      '#description' => $this->t('Sends the site ID of the connector settings: other sites of the tenant will not find these documents.'),
    ];
    $form['destination']['mapping_version'] = [
      '#type' => 'number',
      '#title' => $this->t('Mapping version'),
      '#default_value' => $source->getMappingVersion(),
      '#min' => 1,
      '#max' => 9999,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Part of the document version. Increase it to force the platform to process every document again.'),
    ];

    $form['access'] = [
      '#type' => 'details',
      '#title' => $this->t('Access'),
      '#open' => TRUE,
    ];
    $form['access']['acl_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Who can find the documents'),
      '#options' => [
        SourceInterface::ACL_ACCESS => $this->t('Computed from Drupal access'),
        SourceInterface::ACL_PUBLIC => $this->t('Everybody'),
        SourceInterface::ACL_AUTHENTICATED => $this->t('Authenticated users'),
        SourceInterface::ACL_FIELD => $this->t('Principals stored in a field'),
      ],
      SourceInterface::ACL_ACCESS => [
        '#description' => $this->t('Everybody if anonymous users can view the item, else authenticated users if they can, else the roles that can view it. Items nobody can view are not indexed.'),
      ],
      SourceInterface::ACL_PUBLIC => [
        '#description' => $this->t('Ignores Drupal access: use it only for content that is public.'),
      ],
      SourceInterface::ACL_FIELD => [
        '#description' => $this->t('Map a field with the "Access" role: role references, or texts such as public, authenticated, role:editor or group:12. Items with an empty value are not indexed.'),
      ],
      '#default_value' => $source->getAclMode(),
      '#required' => TRUE,
    ];

    $form['mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Field roles'),
      '#open' => TRUE,
      '#attributes' => ['id' => self::FIELDS_WRAPPER],
      '#description' => $this->t('Choose what each field contributes. <em>Title</em>: the document title (first non-empty; default the entity label). <em>Main content</em> and <em>Context</em>: the document text, in this order. <em>Metadata</em> and <em>Filter</em>: values under the metadata key (filters keep the stored keys of lists). <em>URL</em>: replaces the canonical URL. <em>Image</em>: metadata.image_url. <em>Date</em>: the update date. <em>Access</em>: principals for the "field" access mode. <em>Exclusion</em>: a true value removes the item from the index. Only fields every reader of the document can view are sent.'),
    ];
    $form['mapping']['field_roles'] = $this->buildFieldTable($entityTypeId, $sameType ? $source->getFields() : []);

    return $form;
  }

  /**
   * AJAX callback: replaces the bundles, normalizer and field roles.
   *
   * @param array<string, mixed> $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response.
   */
  public function ajaxRefresh(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . self::SCOPE_WRAPPER, $form['scope']['dependent']));
    $response->addCommand(new ReplaceCommand('#' . self::FIELDS_WRAPPER, $form['mapping']));
    return $response;
  }

  /**
   * Machine name "exists" callback.
   *
   * @param string $id
   *   The candidate ID.
   *
   * @return bool
   *   TRUE if a source with this ID exists.
   */
  public function exists(string $id): bool {
    return $this->entityTypeManager->getStorage('webassistant_source')->load($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#webassistant_source_refresh'])) {
      return;
    }
    $source = $this->getSource();

    if ($source->isNew() && !preg_match(self::ID_PATTERN, (string) $form_state->getValue('id'))) {
      $form_state->setErrorByName('id', $this->t('The machine name must start with a lowercase letter and contain only lowercase letters, digits and underscores.'));
    }

    $entityTypeId = (string) $form_state->getValue('entity_type');
    $entityTypes = $this->entityTypeOptions();
    if (!isset($entityTypes[$entityTypeId])) {
      $form_state->setErrorByName('entity_type', $this->t('Choose a content entity type that can be indexed.'));
      return;
    }

    $bundles = self::checkedValues($form_state->getValue('bundles'));
    $bundleOptions = $this->bundleOptions($entityTypeId);
    if (array_diff($bundles, array_keys($bundleOptions)) !== []) {
      $form_state->setErrorByName('bundles', $this->t('Some bundles do not exist.'));
    }
    $langcodes = self::checkedValues($form_state->getValue('langcodes'));
    if (array_diff($langcodes, array_keys($this->languageOptions())) !== []) {
      $form_state->setErrorByName('langcodes', $this->t('Some languages do not exist.'));
    }

    $knowledgeBase = trim((string) $form_state->getValue('knowledge_base'));
    if (!preg_match(CanonicalDocument::KNOWLEDGE_BASE_PATTERN, $knowledgeBase)) {
      $form_state->setErrorByName('knowledge_base', $this->t('The knowledge base must start with a lowercase letter followed by 1 to 62 lowercase letters, digits, hyphens or underscores.'));
    }
    $documentType = trim((string) $form_state->getValue('document_type'));
    if (!preg_match(CanonicalDocument::TYPE_PATTERN, $documentType)) {
      $form_state->setErrorByName('document_type', $this->t('The document type must start with a lowercase letter followed by 1 to 40 lowercase letters, digits or underscores.'));
    }

    $normalizers = $this->normalizerManager->getOptions($entityTypeId);
    if (!isset($normalizers[(string) $form_state->getValue('normalizer')])) {
      $form_state->setErrorByName('normalizer', $this->t('The normalizer does not support this entity type.'));
    }
    $aclMode = (string) $form_state->getValue('acl_mode');
    if (!in_array($aclMode, SourceInterface::ACL_MODES, TRUE)) {
      $form_state->setErrorByName('acl_mode', $this->t('Choose how access is computed.'));
    }
    $version = $form_state->getValue('mapping_version');
    if (!is_numeric($version) || (int) $version < 1 || (int) $version != $version) {
      $form_state->setErrorByName('mapping_version', $this->t('The mapping version must be a positive integer.'));
    }

    $mappings = $this->extractMappings($form_state, $entityTypeId, TRUE);
    $roles = array_column($mappings, 'role');
    if ($aclMode === SourceInterface::ACL_FIELD && !in_array(SourceInterface::ROLE_ACL, $roles, TRUE)) {
      $form_state->setErrorByName('acl_mode', $this->t('The "field" access mode needs a field with the Access role.'));
    }

    if ($form_state->getValue('status')) {
      $this->validateOverlap($form_state, $entityTypeId, $bundles, $langcodes, $knowledgeBase);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Copies only the properties of the source: submodules use
   * #entity_builders for their third-party settings.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof SourceInterface);
    if ($form_state->hasValue('label')) {
      $entity->set('label', trim((string) $form_state->getValue('label')));
    }
    if ($entity->isNew() && $form_state->hasValue('id')) {
      $entity->set('id', (string) $form_state->getValue('id'));
    }
    if ($form_state->hasValue('status')) {
      $entity->setStatus((bool) $form_state->getValue('status'));
    }
    $entityTypeId = $entity->getTargetEntityTypeId();
    if ($entity->isNew() && $form_state->hasValue('entity_type')) {
      $entityTypeId = (string) $form_state->getValue('entity_type');
      $entity->set('entity_type', $entityTypeId);
    }
    if ($form_state->hasValue('bundles') || $form_state->hasValue('entity_type')) {
      $bundles = array_intersect(self::checkedValues($form_state->getValue('bundles')), array_keys($this->bundleOptions($entityTypeId)));
      $entity->set('bundles', array_values($bundles));
    }
    if ($form_state->hasValue('langcodes')) {
      $entity->set('langcodes', self::checkedValues($form_state->getValue('langcodes')));
    }
    foreach (['knowledge_base', 'document_type', 'normalizer', 'acl_mode'] as $key) {
      if ($form_state->hasValue($key)) {
        $entity->set($key, trim((string) $form_state->getValue($key)));
      }
    }
    if ($form_state->hasValue('site_scoped')) {
      $entity->set('site_scoped', (bool) $form_state->getValue('site_scoped'));
    }
    if ($form_state->hasValue('mapping_version')) {
      $entity->set('mapping_version', max(1, (int) $form_state->getValue('mapping_version')));
    }
    if ($form_state->hasValue('field_roles')) {
      $entity->set('fields', $this->extractMappings($form_state, $entityTypeId, FALSE));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $source = $this->getSource();
    $status = $source->save();
    $arguments = ['%label' => $source->label()];
    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created the source %label.', $arguments));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved the source %label.', $arguments));
    }

    $sensitive = $this->sensitiveMappedFields($source);
    if ($sensitive !== []) {
      $this->messenger()->addWarning($this->t('These fields may contain personal data and their values will be sent to the platform: %fields.', [
        '%fields' => implode(', ', $sensitive),
      ]));
    }
    $roles = array_column($source->getFields(), 'role');
    $textRoles = [SourceInterface::ROLE_MAIN_CONTENT, SourceInterface::ROLE_CONTEXT];
    if ($source->getNormalizerId() === 'content_entity' && array_intersect($textRoles, $roles) === []) {
      $this->messenger()->addWarning($this->t('No field has the Main content or Context role: the documents will only contain the title.'));
    }

    if ($status === SAVED_NEW && !$source->status()) {
      $this->messenger()->addStatus($this->t('The source is disabled. Preview its documents, then enable it to start the synchronization.'));
      $form_state->setRedirectUrl($source->toUrl('preview-form'));
    }
    else {
      $form_state->setRedirectUrl($source->toUrl('collection'));
    }
    return $status;
  }

  /**
   * Returns the source being edited.
   */
  private function getSource(): SourceInterface {
    $source = $this->getEntity();
    assert($source instanceof SourceInterface);
    return $source;
  }

  /**
   * Returns the content entity types that can be indexed.
   *
   * @return array<string, string>
   *   Labels keyed by entity type ID, sorted by label: fieldable content
   *   entity types with at least one normalizer.
   */
  private function entityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface
        || !$definition->entityClassImplements(FieldableEntityInterface::class)
        || in_array($id, self::EXCLUDED_ENTITY_TYPES, TRUE)
        || $this->normalizerManager->getOptions($id) === []) {
        continue;
      }
      $options[$id] = (string) $definition->getLabel();
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns the entity type shown in the form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, string> $options
   *   The entity types offered.
   *
   * @return string
   *   The value chosen in this form (new sources only), else the stored one
   *   (if offered), else the first offered.
   */
  private function selectedEntityTypeId(FormStateInterface $form_state, array $options): string {
    $source = $this->getSource();
    if ($source->isNew()) {
      $value = $form_state->getValue('entity_type');
      if (!is_string($value) || !isset($options[$value])) {
        // Values are limited during the AJAX refresh: fall back to the raw
        // input, accepted only when it is one of the options.
        $input = $form_state->getUserInput()['entity_type'] ?? NULL;
        $value = is_string($input) && isset($options[$input]) ? $input : NULL;
      }
      if ($value !== NULL) {
        return $value;
      }
      $stored = $source->getTargetEntityTypeId();
      return isset($options[$stored]) ? $stored : (string) array_key_first($options);
    }
    return $source->getTargetEntityTypeId();
  }

  /**
   * Returns the bundles of an entity type.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return array<string, string>
   *   Labels keyed by bundle ID; empty for entity types without bundles.
   */
  private function bundleOptions(string $entityTypeId): array {
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if ($definition === NULL || $definition->getBundleEntityType() === NULL) {
      return [];
    }
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo($entityTypeId) as $bundle => $info) {
      $options[(string) $bundle] = (string) ($info['label'] ?? $bundle);
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Returns the languages that can be selected.
   *
   * @return array<string, string>
   *   Names keyed by language code, including "Not specified" and "Not
   *   applicable".
   */
  private function languageOptions(): array {
    $options = [];
    foreach ($this->languageManager->getLanguages(LanguageInterface::STATE_ALL) as $langcode => $language) {
      $options[(string) $langcode] = $language->getName();
    }
    return $options;
  }

  /**
   * Returns the fields that can take a role, with the bundles that have them.
   *
   * Entity keys (except the label), revision metadata, computed fields and
   * fields without any compatible role are not listed. Not memoized: the
   * entity field manager caches the definitions, and the form object is
   * serialized in the form cache.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return array<string, array{definition: \Drupal\Core\Field\FieldDefinitionInterface, bundles: list<string>}>
   *   Fields keyed by name.
   */
  private function discoverFields(string $entityTypeId): array {
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$definition instanceof ContentEntityTypeInterface || !$definition->entityClassImplements(FieldableEntityInterface::class)) {
      return [];
    }
    $excluded = array_values(array_filter([
      $definition->getKey('id'),
      $definition->getKey('uuid'),
      $definition->getKey('revision'),
      $definition->getKey('bundle'),
      $definition->getKey('langcode'),
      $definition->getKey('default_langcode'),
      $definition->getKey('revision_translation_affected'),
      $definition->getKey('published'),
      ...array_values($definition->getRevisionMetadataKeys()),
    ]));
    $fields = [];
    foreach (array_keys($this->bundleInfo->getBundleInfo($entityTypeId)) as $bundle) {
      foreach ($this->entityFieldManager->getFieldDefinitions($entityTypeId, (string) $bundle) as $name => $field) {
        $name = (string) $name;
        if (in_array($name, $excluded, TRUE)
          || str_starts_with($name, 'content_translation_')
          || $field->isComputed()
          || FieldRoles::rolesFor($field) === []) {
          continue;
        }
        $fields[$name] ??= ['definition' => $field, 'bundles' => []];
        $fields[$name]['bundles'][] = (string) $bundle;
      }
    }
    return $fields;
  }

  /**
   * Builds the field role table.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param list<array{field: string, role: string, key?: string, depth?: int, target_bundles?: list<string>}> $mappings
   *   The stored mappings (empty for another entity type).
   *
   * @return array<string, mixed>
   *   The table element.
   */
  private function buildFieldTable(string $entityTypeId, array $mappings): array {
    $fields = $this->discoverFields($entityTypeId);
    $allBundles = array_keys($this->bundleOptions($entityTypeId));
    $bundleLabels = $this->bundleOptions($entityTypeId);

    // Stored mappings grouped by field, in their configured order.
    $byField = [];
    foreach ($mappings as $mapping) {
      $byField[$mapping['field']][] = $mapping;
    }
    $order = array_keys(array_intersect_key($byField, $fields));
    $rest = array_diff(array_keys($fields), $order);
    usort($rest, static fn (string $a, string $b): int => strnatcasecmp((string) $fields[$a]['definition']->getLabel(), (string) $fields[$b]['definition']->getLabel()));
    $order = array_merge($order, $rest);

    $table = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Field'),
        $this->t('Roles'),
        $this->t('Metadata key'),
        $this->t('Reference depth'),
        $this->t('Referenced bundles'),
        $this->t('Notes'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('This entity type has no fields that can be mapped.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'webassistant-source-field-weight',
        ],
      ],
    ];
    $delta = max(10, count($order));
    foreach ($order as $weight => $name) {
      $definition = $fields[$name]['definition'];
      $stored = $byField[$name] ?? [];
      $roles = FieldRoles::rolesFor($definition);
      $reference = FieldRoles::isReference($definition);
      $first = $stored[0] ?? [];

      $row = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $weight,
      ];
      $row['field'] = [
        '#type' => 'item',
        '#markup' => $this->t('@label <small>(@name, @type)</small>', [
          '@label' => $definition->getLabel(),
          '@name' => $name,
          '@type' => $definition->getType(),
        ]),
      ];
      $row['roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Roles of @field', ['@field' => $definition->getLabel()]),
        '#title_display' => 'invisible',
        '#options' => array_intersect_key($this->roleLabels(), array_flip($roles)),
        '#default_value' => array_values(array_intersect(array_column($stored, 'role'), $roles)),
      ];
      $keyRoles = array_values(array_intersect(self::KEY_ROLES, $roles));
      $conditions = [];
      foreach ($keyRoles as $role) {
        $conditions[] = [':input[name="field_roles[' . $name . '][roles][' . $role . ']"]' => ['checked' => TRUE]];
      }
      $states = match (count($conditions)) {
        0 => [],
        1 => $conditions[0],
        default => [$conditions[0], 'or', $conditions[1]],
      };
      $row['key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Metadata key of @field', ['@field' => $definition->getLabel()]),
        '#title_display' => 'invisible',
        '#size' => 16,
        '#maxlength' => 64,
        '#default_value' => $this->firstValue($stored, 'key') ?? '',
        '#placeholder' => $name,
        '#access' => $keyRoles !== [],
        '#states' => $states !== [] ? ['visible' => $states] : [],
      ];
      $row['depth'] = [
        '#type' => 'select',
        '#title' => $this->t('Reference depth of @field', ['@field' => $definition->getLabel()]),
        '#title_display' => 'invisible',
        '#options' => [
          1 => $this->t('Labels'),
          2 => $this->t('Labels and content'),
        ],
        '#default_value' => max(1, min(2, (int) ($first['depth'] ?? 1))),
        '#access' => $reference,
      ];
      $targetBundles = $reference ? $this->targetBundleOptions($definition) : [];
      $row['target_bundles'] = [
        '#type' => 'select',
        '#title' => $this->t('Referenced bundles of @field', ['@field' => $definition->getLabel()]),
        '#title_display' => 'invisible',
        '#options' => $targetBundles,
        '#multiple' => TRUE,
        '#size' => min(4, max(2, count($targetBundles))),
        '#default_value' => array_values(array_intersect((array) ($first['target_bundles'] ?? []), array_keys($targetBundles))),
        '#access' => $targetBundles !== [],
        '#description' => $this->t('None selected: all.'),
      ];
      $notes = [];
      if (FieldRoles::isSensitive($definition)) {
        $notes[] = $this->t('May contain personal data.');
      }
      if ($allBundles !== [] && count($fields[$name]['bundles']) < count($allBundles)) {
        $notes[] = $this->t('Only in: @bundles.', [
          '@bundles' => implode(', ', array_map(static fn (string $bundle): string => $bundleLabels[$bundle] ?? $bundle, $fields[$name]['bundles'])),
        ]);
      }
      if (count($stored) > 1 && count(array_unique(array_map(static fn (array $mapping): string => (string) ($mapping['key'] ?? ''), $stored))) > 1) {
        $notes[] = $this->t('The roles of this field had different settings: the first ones are kept.');
      }
      $row['notes'] = [
        '#type' => 'item',
        '#markup' => implode(' ', array_map('strval', $notes)),
      ];
      $row['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight of @field', ['@field' => $definition->getLabel()]),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        '#delta' => $delta,
        '#attributes' => ['class' => ['webassistant-source-field-weight']],
      ];
      $table[$name] = $row;
    }
    return $table;
  }

  /**
   * Returns the labels of the field roles.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by role, in SourceInterface::ROLES order.
   */
  private function roleLabels(): array {
    return [
      SourceInterface::ROLE_TITLE => $this->t('Title'),
      SourceInterface::ROLE_MAIN_CONTENT => $this->t('Main content'),
      SourceInterface::ROLE_CONTEXT => $this->t('Context'),
      SourceInterface::ROLE_METADATA => $this->t('Metadata'),
      SourceInterface::ROLE_FILTER => $this->t('Filter'),
      SourceInterface::ROLE_URL => $this->t('URL'),
      SourceInterface::ROLE_IMAGE => $this->t('Image'),
      SourceInterface::ROLE_DATE => $this->t('Date'),
      SourceInterface::ROLE_ACL => $this->t('Access'),
      SourceInterface::ROLE_EXCLUSION => $this->t('Exclusion'),
    ];
  }

  /**
   * Returns the bundles a reference field may be restricted to.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The reference field.
   *
   * @return array<string, string>
   *   Labels keyed by bundle (the bundles allowed by the field settings, or
   *   every bundle of the target type); empty when the target type has no
   *   bundles.
   */
  private function targetBundleOptions(FieldDefinitionInterface $definition): array {
    $targetType = (string) $definition->getSetting('target_type');
    $target = $targetType !== '' ? $this->entityTypeManager->getDefinition($targetType, FALSE) : NULL;
    if ($target === NULL || $target->getBundleEntityType() === NULL) {
      return [];
    }
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo($targetType) as $bundle => $info) {
      $options[(string) $bundle] = (string) ($info['label'] ?? $bundle);
    }
    $handlerSettings = $definition->getSetting('handler_settings');
    $allowed = is_array($handlerSettings) && !empty($handlerSettings['target_bundles']) && is_array($handlerSettings['target_bundles'])
      ? array_map('strval', array_keys($handlerSettings['target_bundles']))
      : [];
    if ($allowed !== []) {
      $options = array_intersect_key($options, array_flip($allowed));
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Converts the field role table into the stored mappings.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $entityTypeId
   *   The entity type ID.
   * @param bool $validate
   *   TRUE to set form errors for invalid rows.
   *
   * @return list<array{field: string, role: string, key?: string, depth?: int, target_bundles?: list<string>}>
   *   The mappings ordered by row weight, roles in SourceInterface::ROLES
   *   order within a field.
   */
  private function extractMappings(FormStateInterface $form_state, string $entityTypeId, bool $validate): array {
    $rows = $form_state->getValue('field_roles');
    if (!is_array($rows)) {
      return [];
    }
    $fields = $this->discoverFields($entityTypeId);
    $usedKeys = [];
    $collected = [];
    $index = 0;
    foreach ($rows as $name => $row) {
      $name = (string) $name;
      if (!is_array($row) || !isset($fields[$name])) {
        continue;
      }
      $definition = $fields[$name]['definition'];
      $compatible = FieldRoles::rolesFor($definition);
      $checked = self::checkedValues($row['roles'] ?? []);
      $invalid = array_diff($checked, $compatible);
      if ($validate && $invalid !== []) {
        $form_state->setErrorByName('field_roles][' . $name . '][roles', $this->t('The field %field cannot take the role %role.', [
          '%field' => $definition->getLabel(),
          '%role' => implode(', ', $invalid),
        ]));
      }
      $roles = array_values(array_intersect(SourceInterface::ROLES, $checked, $compatible));
      if ($roles === []) {
        continue;
      }

      $key = trim((string) ($row['key'] ?? ''));
      $keyRoles = array_intersect(self::KEY_ROLES, $roles);
      if ($validate && $keyRoles !== []) {
        $element = 'field_roles][' . $name . '][key';
        $effective = $key !== '' ? $key : $name;
        if (count($keyRoles) > 1) {
          $form_state->setErrorByName($element, $this->t('The field %field cannot be both Metadata and Filter: both use the same metadata key.', ['%field' => $definition->getLabel()]));
        }
        elseif (!preg_match(self::METADATA_KEY_PATTERN, $effective)) {
          $form_state->setErrorByName($element, $this->t('The metadata key of %field must start with a lowercase letter and contain only lowercase letters, digits and underscores (up to 64 characters).', ['%field' => $definition->getLabel()]));
        }
        elseif (in_array($effective, ContentEntityNormalizer::RESERVED_METADATA_KEYS, TRUE)) {
          $form_state->setErrorByName($element, $this->t('The metadata key %key is reserved.', ['%key' => $effective]));
        }
        elseif (isset($usedKeys[$effective])) {
          $form_state->setErrorByName($element, $this->t('The metadata key %key is used by more than one field.', ['%key' => $effective]));
        }
        $usedKeys[$effective] = TRUE;
      }

      $reference = FieldRoles::isReference($definition);
      $depth = 1;
      $targetBundles = [];
      if ($reference) {
        $depth = (int) ($row['depth'] ?? 1);
        if ($depth < 1 || $depth > 2) {
          if ($validate) {
            $form_state->setErrorByName('field_roles][' . $name . '][depth', $this->t('The reference depth must be 1 or 2.'));
          }
          $depth = max(1, min(2, $depth));
        }
        $options = $this->targetBundleOptions($definition);
        $targetBundles = self::checkedValues($row['target_bundles'] ?? []);
        if ($validate && array_diff($targetBundles, array_keys($options)) !== []) {
          $form_state->setErrorByName('field_roles][' . $name . '][target_bundles', $this->t('Some referenced bundles of %field do not exist.', ['%field' => $definition->getLabel()]));
        }
        $targetBundles = array_values(array_intersect($targetBundles, array_keys($options)));
      }

      $mappings = [];
      foreach ($roles as $role) {
        $mapping = ['field' => $name, 'role' => $role];
        if ($key !== '' && in_array($role, self::KEY_ROLES, TRUE)) {
          $mapping['key'] = $key;
        }
        if ($reference && in_array($role, self::REFERENCE_ROLES, TRUE)) {
          $mapping['depth'] = $depth;
          if ($targetBundles !== []) {
            $mapping['target_bundles'] = $targetBundles;
          }
        }
        $mappings[] = $mapping;
      }
      $collected[] = [
        'weight' => is_numeric($row['weight'] ?? NULL) ? (int) $row['weight'] : 0,
        'index' => $index++,
        'mappings' => $mappings,
      ];
    }
    usort($collected, static fn (array $a, array $b): int => [$a['weight'], $a['index']] <=> [$b['weight'], $b['index']]);
    return array_merge(...array_column($collected, 'mappings'));
  }

  /**
   * Rejects an enabled source overlapping another one (amendment A10).
   *
   * Two enabled sources overlap when they send the same entity type to the
   * same knowledge base with intersecting bundles and languages (an empty
   * list means all): both would write the same remote source IDs.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $entityTypeId
   *   The entity type ID.
   * @param list<string> $bundles
   *   The selected bundles.
   * @param list<string> $langcodes
   *   The selected languages.
   * @param string $knowledgeBase
   *   The knowledge base.
   */
  private function validateOverlap(FormStateInterface $form_state, string $entityTypeId, array $bundles, array $langcodes, string $knowledgeBase): void {
    $id = $this->getSource()->isNew() ? (string) $form_state->getValue('id') : (string) $this->getSource()->id();
    foreach ($this->entityTypeManager->getStorage('webassistant_source')->loadMultiple() as $other) {
      if (!$other instanceof SourceInterface || (string) $other->id() === $id || !$other->status()) {
        continue;
      }
      if ($other->getKnowledgeBase() !== $knowledgeBase || $other->getTargetEntityTypeId() !== $entityTypeId) {
        continue;
      }
      $otherBundles = $other->getBundles();
      $otherLangcodes = $other->getLangcodes();
      $bundlesOverlap = $bundles === [] || $otherBundles === [] || array_intersect($bundles, $otherBundles) !== [];
      $langcodesOverlap = $langcodes === [] || $otherLangcodes === [] || array_intersect($langcodes, $otherLangcodes) !== [];
      if ($bundlesOverlap && $langcodesOverlap) {
        $form_state->setErrorByName('knowledge_base', $this->t('The enabled source %other already sends some of these items to the knowledge base %kb. Choose another knowledge base, other bundles or languages, or disable one of the sources.', [
          '%other' => $other->label(),
          '%kb' => $knowledgeBase,
        ]));
        return;
      }
    }
  }

  /**
   * Returns the labels of mapped fields that may contain personal data.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The saved source.
   *
   * @return list<string>
   *   Field labels.
   */
  private function sensitiveMappedFields(SourceInterface $source): array {
    $fields = $this->discoverFields($source->getTargetEntityTypeId());
    $labels = [];
    foreach ($source->getFields() as $mapping) {
      $definition = $fields[$mapping['field']]['definition'] ?? NULL;
      if ($definition !== NULL && in_array($mapping['role'], self::EXPOSING_ROLES, TRUE) && FieldRoles::isSensitive($definition)) {
        $labels[$mapping['field']] = (string) $definition->getLabel();
      }
    }
    return array_values($labels);
  }

  /**
   * Returns the first non-empty value of a key among mappings.
   *
   * @param list<array<string, mixed>> $mappings
   *   The mappings.
   * @param string $key
   *   The key.
   *
   * @return string|null
   *   The value, or NULL.
   */
  private function firstValue(array $mappings, string $key): ?string {
    foreach ($mappings as $mapping) {
      if (isset($mapping[$key]) && is_scalar($mapping[$key]) && (string) $mapping[$key] !== '') {
        return (string) $mapping[$key];
      }
    }
    return NULL;
  }

  /**
   * Returns the checked values of checkboxes or a multiple select.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return list<string>
   *   The checked keys.
   */
  private static function checkedValues(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $checked = [];
    foreach ($value as $item) {
      if (is_scalar($item) && (string) $item !== '' && (string) $item !== '0') {
        $checked[] = (string) $item;
      }
    }
    return array_values(array_unique($checked));
  }

}
