<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents\Hook;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Form\SourceForm;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector_documents\AttachmentFieldInspector;
use Drupal\webassistant_connector_documents\DocumentsSettings;
use Drupal\webassistant_connector_documents\MediaTypes;

/**
 * Adds the attachment settings to the source form (design section 11).
 *
 * The settings are third-party settings of the source, saved by an entity
 * builder (SourceForm copies only its own properties). Only file fields and
 * references to media with a file source of the selected entity type are
 * offered; the list follows the entity type when a new source changes it.
 */
final class SourceFormHooks {

  use StringTranslationTrait;

  /**
   * Key of the settings element (and of its form values).
   */
  public const ELEMENT = 'webassistant_connector_documents';

  /**
   * HTML ID of the settings element (AJAX wrapper).
   */
  public const WRAPPER_ID = 'webassistant-connector-documents-settings';

  /**
   * Form operations that edit a source.
   */
  private const OPERATIONS = ['add', 'edit', 'default'];

  /**
   * Constructs a SourceFormHooks object.
   *
   * @param \Drupal\webassistant_connector_documents\AttachmentFieldInspector $fieldInspector
   *   The attachment field inspector.
   */
  public function __construct(
    private readonly AttachmentFieldInspector $fieldInspector,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for webassistant_source_form.
   *
   * The base form ID is shared by the add, edit, delete and preview forms:
   * only the add and edit forms get the settings.
   */
  #[Hook('form_webassistant_source_form_alter')]
  public function sourceFormAlter(array &$form, FormStateInterface $form_state): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface || !in_array($formObject->getOperation(), self::OPERATIONS, TRUE)) {
      return;
    }
    $source = $formObject->getEntity();
    if (!$source instanceof SourceInterface) {
      return;
    }
    $selected = $form['scope']['entity_type']['#default_value'] ?? NULL;
    $entityTypeId = is_string($selected) && $selected !== '' ? $selected : $source->getTargetEntityTypeId();
    $form[self::ELEMENT] = $this->buildElement($source, $entityTypeId);
    $form['#entity_builders'][] = [self::class, 'buildEntity'];
    if (isset($form['scope']['entity_type']['#ajax'])) {
      // The field list depends on the entity type: refresh it with the rest
      // of the form.
      $form['scope']['entity_type']['#ajax']['callback'] = [self::class, 'ajaxRefresh'];
    }
  }

  /**
   * Builds the settings element.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string $entityTypeId
   *   The selected entity type.
   *
   * @return array<string, mixed>
   *   The element.
   */
  private function buildElement(SourceInterface $source, string $entityTypeId): array {
    $sameType = $entityTypeId === $source->getTargetEntityTypeId();
    $settings = $sameType ? DocumentsSettings::fromSource($source) : new DocumentsSettings();
    $options = [];
    foreach ($this->fieldInspector->eligibleFields($entityTypeId) as $name => $info) {
      $arguments = [
        '@label' => $info['label'],
        '@name' => $name,
        '@bundles' => implode(', ', $info['bundles']),
      ];
      $options[$name] = $info['kind'] === AttachmentFieldInspector::KIND_MEDIA
        ? $this->t('@label (@name): media, in @bundles', $arguments)
        : $this->t('@label (@name): files, in @bundles', $arguments);
    }
    $enabledSelector = ':input[name="' . self::ELEMENT . '[enabled]"]';
    $visible = ['visible' => [$enabledSelector => ['checked' => TRUE]]];

    $element = [
      '#type' => 'details',
      '#title' => $this->t('Attached documents'),
      '#open' => $settings->enabled,
      '#tree' => TRUE,
      '#attributes' => ['id' => self::WRAPPER_ID],
      '#description' => $this->t('Indexes the files attached to the content (PDF, Word, HTML, text or Markdown) as separate documents of type "document". The platform downloads each file from Drupal through a signed URL that expires; citations link to the content that publishes the file. Each file inherits the access of its content, restricted to the readers that may view its media item and download it.'),
      '#element_validate' => [[self::class, 'validateElement']],
    ];
    $element['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Index attached documents'),
      '#default_value' => $settings->enabled,
    ];
    if ($options === []) {
      $element['enabled']['#disabled'] = !$settings->enabled;
      $element['no_fields'] = [
        '#type' => 'item',
        '#markup' => $this->t('This entity type has no file field nor reference to media items with a file.'),
      ];
    }
    else {
      $element['fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Fields'),
        '#options' => $options,
        '#default_value' => array_values(array_intersect($settings->fields, array_keys($options))),
        '#description' => $this->t('File fields, and references to media items whose source is a file (document, image, audio or video). Fields hidden from some readers are not indexed.'),
        '#states' => $visible,
      ];
    }
    $element['mime_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Formats'),
      '#options' => MediaTypes::labels(),
      '#default_value' => $settings->mimeTypes,
      '#description' => $this->t('Only these formats are sent. The platform cannot read other formats.'),
      '#states' => $visible,
    ];
    $element['knowledge_base'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Knowledge base'),
      '#default_value' => $settings->knowledgeBase,
      '#maxlength' => 63,
      '#size' => 32,
      '#description' => $this->t('Leave empty to send the files to the knowledge base of the source.'),
      '#states' => $visible,
    ];
    $element['max_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum file size (bytes)'),
      '#default_value' => $settings->maxBytes,
      '#min' => 1,
      '#max' => DocumentsSettings::MAX_BYTES,
      '#step' => 1,
      '#description' => $this->t('Larger files are not indexed. The platform does not download files larger than @max bytes.', ['@max' => DocumentsSettings::MAX_BYTES]),
      '#states' => $visible,
    ];
    $element['url_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Lifetime of the download URLs (seconds)'),
      '#default_value' => $settings->urlTtl,
      '#min' => DocumentsSettings::MIN_URL_TTL,
      '#max' => DocumentsSettings::MAX_URL_TTL,
      '#step' => 1,
      '#description' => $this->t('The platform must download the file before the URL expires; the reconciliation sends expired failures again with a new URL. Default: 604800 (7 days).'),
      '#states' => $visible,
    ];
    $element['download_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Download base URL'),
      '#default_value' => $settings->downloadBaseUrl,
      '#maxlength' => 255,
      '#description' => $this->t('URL with which the platform reaches this site, for example http://drupal on an internal network. Leave empty to use the public base URL of the synchronization settings.'),
      '#states' => $visible,
    ];
    return $element;
  }

  /**
   * Validates the settings (#element_validate).
   *
   * @param array<string, mixed> $element
   *   The settings element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateElement(array &$element, FormStateInterface $form_state): void {
    $values = $form_state->getValue($element['#parents']);
    if (!is_array($values) || empty($values['enabled'])) {
      return;
    }
    if (isset($element['fields']) && self::checked($values['fields'] ?? []) === []) {
      $form_state->setError($element['fields'], new TranslatableMarkup('Choose at least one field with attached documents.'));
    }
    elseif (!isset($element['fields'])) {
      $form_state->setError($element['enabled'], new TranslatableMarkup('This entity type has no field with attached documents.'));
    }
    if (self::checked($values['mime_types'] ?? []) === []) {
      $form_state->setError($element['mime_types'], new TranslatableMarkup('Choose at least one format.'));
    }
    $knowledgeBase = trim(is_scalar($values['knowledge_base'] ?? NULL) ? (string) $values['knowledge_base'] : '');
    if ($knowledgeBase !== '' && !preg_match(CanonicalDocument::KNOWLEDGE_BASE_PATTERN, $knowledgeBase)) {
      $form_state->setError($element['knowledge_base'], new TranslatableMarkup('The knowledge base of the attached documents must be empty or start with a lowercase letter followed by 1 to 62 lowercase letters, digits, hyphens or underscores.'));
    }
    $baseUrl = trim(is_scalar($values['download_base_url'] ?? NULL) ? (string) $values['download_base_url'] : '');
    if ($baseUrl !== '' && !DocumentsSettings::isValidBaseUrl($baseUrl)) {
      $form_state->setError($element['download_base_url'], new TranslatableMarkup('The download base URL must be an absolute http or https URL without query string, fragment nor credentials.'));
    }
    $other = $form_state->getValue('status') ? self::overlappingSource($form_state, $knowledgeBase) : NULL;
    if ($other !== NULL) {
      $form_state->setError($element['knowledge_base'], new TranslatableMarkup('The enabled source %other already sends the attached documents of some of these items to the knowledge base %kb. Choose another knowledge base for the attached documents, other bundles or languages, or disable one of the sources.', [
        '%other' => $other->label(),
        '%kb' => DocumentsSettings::fromSource($other)->knowledgeBaseFor($other),
      ]));
    }
  }

  /**
   * Returns an enabled source whose attachments would collide with these.
   *
   * Attachment source IDs do not contain the source (amendment A10 only
   * checks the main knowledge base): two sources sending the attachments of
   * the same entity to the same knowledge base would write the same remote
   * documents, and removing one source would delete the other's.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the source form.
   * @param string $knowledgeBase
   *   The knowledge base of the attachments ('' for the main one).
   *
   * @return \Drupal\webassistant_connector\Entity\SourceInterface|null
   *   The other source, or NULL.
   */
  private static function overlappingSource(FormStateInterface $form_state, string $knowledgeBase): ?SourceInterface {
    $formObject = $form_state->getFormObject();
    $source = $formObject instanceof EntityFormInterface ? $formObject->getEntity() : NULL;
    $id = $source instanceof SourceInterface && !$source->isNew() ? (string) $source->id() : (string) $form_state->getValue('id');
    $entityTypeId = (string) $form_state->getValue('entity_type');
    if ($knowledgeBase === '') {
      $knowledgeBase = trim((string) $form_state->getValue('knowledge_base'));
    }
    $bundles = self::checked($form_state->getValue('bundles'));
    $langcodes = self::checked($form_state->getValue('langcodes'));
    foreach (\Drupal::entityTypeManager()->getStorage('webassistant_source')->loadMultiple() as $other) {
      if (!$other instanceof SourceInterface || (string) $other->id() === $id || !$other->status() || $other->getTargetEntityTypeId() !== $entityTypeId) {
        continue;
      }
      $settings = DocumentsSettings::fromSource($other);
      if (!$settings->isActive() || $settings->knowledgeBaseFor($other) !== $knowledgeBase) {
        continue;
      }
      $otherBundles = $other->getBundles();
      $otherLangcodes = $other->getLangcodes();
      if (($bundles === [] || $otherBundles === [] || array_intersect($bundles, $otherBundles) !== [])
        && ($langcodes === [] || $otherLangcodes === [] || array_intersect($langcodes, $otherLangcodes) !== [])) {
        return $other;
      }
    }
    return NULL;
  }

  /**
   * Stores the settings as third-party settings (#entity_builders).
   *
   * Sources that never had attachment settings are left untouched while
   * attachments stay disabled, so saving them does not change their mapping
   * hash (which would reindex the source).
   *
   * @param string $entityTypeId
   *   The entity type ID of the source (webassistant_source).
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The source.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function buildEntity(string $entityTypeId, EntityInterface $entity, array &$form, FormStateInterface $form_state): void {
    if (!$entity instanceof SourceInterface) {
      return;
    }
    $values = $form_state->getValue(self::ELEMENT);
    if (!is_array($values)) {
      return;
    }
    $enabled = !empty($values['enabled']);
    if (!$enabled && $entity->getThirdPartySettings(DocumentsSettings::MODULE) === []) {
      return;
    }
    $allowedFields = array_map('strval', array_keys($form[self::ELEMENT]['fields']['#options'] ?? []));
    $settings = DocumentsSettings::fromArray([
      'enabled' => $enabled,
      'fields' => array_values(array_intersect(self::checked($values['fields'] ?? []), $allowedFields)),
      'knowledge_base' => $values['knowledge_base'] ?? '',
      'mime_types' => self::checked($values['mime_types'] ?? []),
      'max_bytes' => $values['max_bytes'] ?? NULL,
      'url_ttl' => $values['url_ttl'] ?? NULL,
      'download_base_url' => $values['download_base_url'] ?? '',
    ]);
    foreach ($settings->toArray() as $key => $value) {
      $entity->setThirdPartySetting(DocumentsSettings::MODULE, $key, $value);
    }
  }

  /**
   * AJAX callback of the entity type: also refreshes the field list.
   *
   * @param array<string, mixed> $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The response of the source form plus the settings element.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state): AjaxResponse {
    $formObject = $form_state->getFormObject();
    $response = $formObject instanceof SourceForm ? $formObject->ajaxRefresh($form, $form_state) : new AjaxResponse();
    if (isset($form[self::ELEMENT])) {
      $response->addCommand(new ReplaceCommand('#' . self::WRAPPER_ID, $form[self::ELEMENT]));
    }
    return $response;
  }

  /**
   * Returns the checked values of a checkboxes element.
   *
   * @param mixed $values
   *   The raw value.
   *
   * @return list<string>
   *   The checked keys.
   */
  private static function checked(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $checked = [];
    foreach ($values as $value) {
      if ((is_string($value) || is_int($value)) && (string) $value !== '' && (string) $value !== '0') {
        $checked[] = (string) $value;
      }
    }
    return $checked;
  }

}
