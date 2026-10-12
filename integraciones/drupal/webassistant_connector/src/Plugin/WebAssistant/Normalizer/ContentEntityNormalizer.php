<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\WebAssistant\Normalizer;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\file\FileInterface;
use Drupal\link\LinkItemInterface;
use Drupal\media\MediaInterface;
use Drupal\webassistant_connector\Attribute\Normalizer;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Normalizer\FieldRoles;
use Drupal\webassistant_connector\Normalizer\NormalizationContext;
use Drupal\webassistant_connector\Normalizer\NormalizerPluginBase;
use Drupal\webassistant_connector\Sync\AclResolver;
use Drupal\webassistant_connector\Sync\UrlResolver;

/**
 * Default normalizer for any content entity (design section 8.2).
 *
 * Title from the title role (or label()); HTML text with an <h1> of the
 * title and, for each main_content/context field, its processed value
 * (processed text, escaped strings, labels of references with depth <= 2
 * and cycle detection); metadata from metadata/filter fields; image role ->
 * metadata.image_url (absolute); date role -> updated_at.
 *
 * Every mapped field must be viewable by every principal of the ACL, and
 * referenced entities must be viewable by all of them; otherwise they are
 * dropped with a warning (amendments B2/B3). Reference depth: 1 (default)
 * emits the labels of the referenced entities; 2 also emits their text
 * fields and the labels of their own references (one more hop). The
 * optional target_bundles of the mapping restricts the referenced bundles.
 */
#[Normalizer(
  id: 'content_entity',
  label: new TranslatableMarkup('Content entity'),
  description: new TranslatableMarkup('Generic normalizer driven by the field roles of the source.'),
)]
final class ContentEntityNormalizer extends NormalizerPluginBase {

  /**
   * Maximum text length accepted by the platform.
   */
  public const MAX_TEXT_LENGTH = CanonicalDocument::MAX_TEXT_LENGTH;

  /**
   * Maximum number of metadata keys accepted by the platform.
   */
  public const MAX_METADATA_KEYS = CanonicalDocument::MAX_METADATA_KEYS;

  /**
   * Maximum length of a metadata string value.
   */
  public const MAX_METADATA_STRING = 1000;

  /**
   * Maximum number of values of a metadata list.
   */
  public const MAX_METADATA_LIST = 100;

  /**
   * Metadata keys set by the connector itself.
   */
  public const RESERVED_METADATA_KEYS = ['drupal', 'image_url', 'image_alt'];

  /**
   * Constructs a ContentEntityNormalizer.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\webassistant_connector\Sync\UrlResolver $urlResolver
   *   The URL resolver.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository (translations of referenced entities).
   * @param \Drupal\webassistant_connector\Sync\AclResolver $aclResolver
   *   The ACL resolver (field and entity access per principal).
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (fresh copies of referenced entities).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UrlResolver $urlResolver,
    protected EntityRepositoryInterface $entityRepository,
    protected AclResolver $aclResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context): array {
    $accounts = $context->principalAccounts !== [] ? $context->principalAccounts : $this->aclResolver->principalAccounts($context->acl);
    $context->visit($translation->getEntityTypeId(), (string) $translation->id());
    try {
      $title = $this->title($translation, $source, $context, $accounts);
      $html = '<h1>' . Html::escape($title) . '</h1>';
      foreach ([SourceInterface::ROLE_MAIN_CONTENT, SourceInterface::ROLE_CONTEXT] as $role) {
        foreach ($source->getFieldsByRole($role) as $mapping) {
          $items = $this->viewableItems($translation, $mapping['field'], $context, $accounts);
          if ($items === NULL) {
            continue;
          }
          $fragment = $this->renderItems($items, $mapping, $context, $accounts, 1);
          if ($fragment === '') {
            continue;
          }
          if ($role === SourceInterface::ROLE_CONTEXT) {
            $fragment = '<section><h2>' . Html::escape((string) $items->getFieldDefinition()->getLabel()) . '</h2>' . $fragment . '</section>';
          }
          $html .= "\n" . $fragment;
        }
      }
      if (mb_strlen($html) > self::MAX_TEXT_LENGTH) {
        $html = mb_substr($html, 0, self::MAX_TEXT_LENGTH);
        $context->addWarning(sprintf('The text was cut to %d characters.', self::MAX_TEXT_LENGTH));
      }

      $metadata = ['drupal' => $context->drupalMetadata];
      $metadata += $this->image($translation, $source, $context, $accounts);
      $metadata += $this->metadata($translation, $source, $context, $accounts, count($metadata));

      return [
        new CanonicalDocument(
          sourceId: $context->baseSourceId,
          knowledgeBase: $source->getKnowledgeBase(),
          type: $source->getDocumentType(),
          title: $title,
          version: $context->version,
          locale: $context->locale,
          text: $html,
          contentFormat: CanonicalDocument::FORMAT_HTML,
          canonicalUrl: $context->canonicalUrl,
          acl: $context->acl,
          siteId: $context->siteId,
          metadata: $metadata,
          createdAt: $this->createdAt($translation),
          updatedAt: $this->updatedAt($translation, $source, $context, $accounts),
        ),
      ];
    }
    finally {
      $context->leave($translation->getEntityTypeId(), (string) $translation->id());
    }
  }

  /**
   * Returns the title: first non-empty title field, else the label.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   *
   * @return string
   *   A non-empty title. When a title field (or the label field) is not
   *   viewable by every principal, a neutral "{entity type} {id}" is used.
   */
  private function title(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context, array $accounts): string {
    $denied = FALSE;
    foreach ($source->getFieldsByRole(SourceInterface::ROLE_TITLE) as $mapping) {
      if (!$translation->hasField($mapping['field']) || $translation->get($mapping['field'])->isEmpty()) {
        continue;
      }
      $items = $this->viewableItems($translation, $mapping['field'], $context, $accounts);
      if ($items === NULL) {
        $denied = TRUE;
        continue;
      }
      $title = self::plain($this->plainValue($items->first()));
      if ($title !== '') {
        return $title;
      }
    }
    $labelKey = $translation->getEntityType()->getKey('label');
    if (!$denied && is_string($labelKey) && $translation->hasField($labelKey)) {
      $denied = !$this->aclResolver->fieldViewableByAll($translation->get($labelKey), $accounts);
    }
    $label = $denied ? '' : self::plain((string) $translation->label());
    return $label !== '' ? $label : trim($translation->getEntityType()->getLabel() . ' ' . $translation->id());
  }

  /**
   * Returns the items of a field if every principal may view them.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field
   *   The field name.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (warnings).
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|null
   *   The non-empty items, or NULL if missing, empty or not viewable.
   */
  private function viewableItems(FieldableEntityInterface $entity, string $field, NormalizationContext $context, array $accounts): ?FieldItemListInterface {
    if (!$entity->hasField($field)) {
      return NULL;
    }
    $items = $entity->get($field);
    if ($items->isEmpty()) {
      return NULL;
    }
    if (!$this->aclResolver->fieldViewableByAll($items, $accounts)) {
      $context->addWarning(sprintf('%s dropped: not viewable by every principal of the ACL (%s).', $field, implode(', ', $context->acl)));
      return NULL;
    }
    return $items;
  }

  /**
   * Renders the items of a field as an HTML fragment.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The items.
   * @param array<string, mixed> $mapping
   *   The field mapping (depth, target_bundles).
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param int $hop
   *   Reference hop of these items (1 for fields of the indexed entity).
   *
   * @return string
   *   The HTML fragment (empty if nothing to emit).
   */
  private function renderItems(FieldItemListInterface $items, array $mapping, NormalizationContext $context, array $accounts, int $hop): string {
    $type = $items->getFieldDefinition()->getType();
    if (in_array($type, FieldRoles::REFERENCE_TYPES, TRUE)) {
      return $this->renderReferences($items, $mapping, $context, $accounts, $hop);
    }
    if (in_array($type, ['image', 'file'], TRUE)) {
      return '';
    }
    $parts = [];
    foreach ($items as $item) {
      if (in_array($type, FieldRoles::FORMATTED_TEXT_TYPES, TRUE)) {
        $processed = $item->get('processed')->getValue();
        $parts[] = trim((string) $processed);
        continue;
      }
      if ($item instanceof LinkItemInterface) {
        $url = $this->linkUrl($item);
        $title = trim((string) $item->get('title')->getValue());
        if ($url !== NULL) {
          $parts[] = '<p><a href="' . Html::escape($url) . '">' . Html::escape($title !== '' ? $title : $url) . '</a></p>';
        }
        continue;
      }
      $value = $this->plainValue($item);
      if (trim($value) !== '') {
        $parts[] = '<p>' . nl2br(Html::escape($value), FALSE) . '</p>';
      }
    }
    return implode("\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
  }

  /**
   * Renders referenced entities (labels, or nested content with depth 2).
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The reference items.
   * @param array<string, mixed> $mapping
   *   The field mapping (depth, target_bundles).
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param int $hop
   *   Reference hop of the referenced entities.
   *
   * @return string
   *   The HTML fragment.
   */
  private function renderReferences(FieldItemListInterface $items, array $mapping, NormalizationContext $context, array $accounts, int $hop): string {
    $depth = max(1, min(2, (int) ($mapping['depth'] ?? 1)));
    $nested = $hop < $depth;
    $labels = [];
    $sections = [];
    foreach ($this->referencedEntities($items, $mapping, $context, $accounts, $nested ? 'view' : 'view label') as $entity) {
      $label = self::plain((string) $entity->label());
      if (!$nested || !$entity instanceof ContentEntityInterface) {
        if ($label !== '') {
          $labels[] = '<li>' . Html::escape($label) . '</li>';
        }
        continue;
      }
      // Cycle detection: an entity already on the stack is only labelled.
      if (!$context->visit($entity->getEntityTypeId(), (string) $entity->id())) {
        if ($label !== '') {
          $labels[] = '<li>' . Html::escape($label) . '</li>';
        }
        continue;
      }
      try {
        $fresh = $items->getFieldDefinition()->getType() === 'entity_reference' ? $this->freshTranslation($entity, $context) : $entity;
        $sections[] = '<section><h3>' . Html::escape($label) . '</h3>' . $this->renderNested($fresh, $context, $accounts, $hop + 1) . '</section>';
      }
      finally {
        $context->leave($entity->getEntityTypeId(), (string) $entity->id());
      }
    }
    $html = $labels !== [] ? '<ul>' . implode('', $labels) . '</ul>' : '';
    return trim($html . implode("\n", $sections));
  }

  /**
   * Reloads a referenced entity before rendering its content.
   *
   * Processed text is cached on field items: an entity object already used
   * in this request (e.g. rendered for another user) could carry a value
   * computed with other access. The default revision is reloaded without
   * the static cache (amendment B1). Revision references are not reloaded:
   * they point to a specific revision.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The referenced entity.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (language).
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   A fresh copy in the language of the context, or the entity itself.
   */
  private function freshTranslation(ContentEntityInterface $entity, NormalizationContext $context): ContentEntityInterface {
    $fresh = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if (!$fresh instanceof ContentEntityInterface) {
      return $entity;
    }
    return $this->entityRepository->getTranslationFromContext($fresh, $context->langcode);
  }

  /**
   * Renders the text fields and reference labels of a referenced entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The referenced entity (translation).
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param int $hop
   *   Hop of the references of this entity (always beyond the depth, so
   *   they are labels only).
   *
   * @return string
   *   The HTML fragment.
   */
  private function renderNested(ContentEntityInterface $entity, NormalizationContext $context, array $accounts, int $hop): string {
    $labelKey = $entity->getEntityType()->getKey('label');
    $parts = [];
    foreach ($entity->getFields(FALSE) as $name => $items) {
      $definition = $items->getFieldDefinition();
      $type = $definition->getType();
      $base = $definition->getFieldStorageDefinition()->isBaseField();
      $textTypes = FieldRoles::FORMATTED_TEXT_TYPES;
      if (!$base) {
        $textTypes = array_merge($textTypes, ['string', 'string_long']);
      }
      $text = in_array($type, $textTypes, TRUE);
      $reference = !$base && in_array($type, FieldRoles::REFERENCE_TYPES, TRUE);
      if ($name === $labelKey || (!$text && !$reference) || $items->isEmpty()) {
        continue;
      }
      // Fields of referenced entities are not mapped by the administrator:
      // the ones that usually hold personal data are never emitted.
      if (FieldRoles::isSensitive($definition)) {
        continue;
      }
      if (!$this->aclResolver->fieldViewableByAll($items, $accounts)) {
        $context->addWarning(sprintf('%s of %s %s dropped: not viewable by every principal of the ACL.', $name, $entity->getEntityTypeId(), $entity->id()));
        continue;
      }
      $fragment = $this->renderItems($items, ['depth' => 1], $context, $accounts, $hop);
      if ($fragment !== '') {
        $parts[] = $fragment;
      }
    }
    return implode("\n", $parts);
  }

  /**
   * Returns the referenced entities every principal may see.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The reference items.
   * @param array<string, mixed> $mapping
   *   The field mapping (target_bundles).
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (language and warnings).
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param string $operation
   *   The operation to check: "view label" for labels, "view" for content.
   *
   * @return list<\Drupal\Core\Entity\EntityInterface>
   *   The entities, in the translation of the context when available.
   */
  private function referencedEntities(FieldItemListInterface $items, array $mapping, NormalizationContext $context, array $accounts, string $operation): array {
    $bundles = $mapping['target_bundles'] ?? [];
    $entities = [];
    foreach ($items as $item) {
      if (!$item instanceof EntityReferenceItem) {
        continue;
      }
      $entity = $item->get('entity')->getValue();
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      if ($bundles !== [] && !in_array($entity->bundle(), $bundles, TRUE)) {
        continue;
      }
      if ($entity instanceof ContentEntityInterface) {
        $entity = $this->entityRepository->getTranslationFromContext($entity, $context->langcode);
      }
      if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
        continue;
      }
      if (!$this->aclResolver->canAccessAll($entity, $accounts, $operation)) {
        $context->addWarning(sprintf('Reference to %s %s in %s dropped: not viewable by every principal of the ACL.', $entity->getEntityTypeId(), $entity->id(), $items->getName()));
        continue;
      }
      $entities[] = $entity;
    }
    return $entities;
  }

  /**
   * Builds the metadata of the metadata and filter fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param int $used
   *   Metadata keys already used.
   *
   * @return array<string, mixed>
   *   Metadata keyed by the mapping key (or the field name).
   */
  private function metadata(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context, array $accounts, int $used): array {
    $metadata = [];
    foreach ($source->getFields() as $mapping) {
      if (!in_array($mapping['role'], [SourceInterface::ROLE_METADATA, SourceInterface::ROLE_FILTER], TRUE)) {
        continue;
      }
      $key = $mapping['key'] ?? $mapping['field'];
      if (in_array($key, self::RESERVED_METADATA_KEYS, TRUE) || array_key_exists($key, $metadata)) {
        $context->addWarning(sprintf('%s ignored: the metadata key "%s" is reserved or already used.', $mapping['field'], $key));
        continue;
      }
      $items = $this->viewableItems($translation, $mapping['field'], $context, $accounts);
      if ($items === NULL) {
        continue;
      }
      $values = $this->metadataValues($items, $mapping, $context, $accounts);
      if ($values === []) {
        continue;
      }
      if ($used + count($metadata) >= self::MAX_METADATA_KEYS) {
        $context->addWarning(sprintf('%s dropped: documents accept at most %d metadata keys.', $mapping['field'], self::MAX_METADATA_KEYS));
        continue;
      }
      $multiple = $items->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
      $metadata[$key] = $multiple ? array_slice($values, 0, self::MAX_METADATA_LIST) : $values[0];
    }
    return $metadata;
  }

  /**
   * Returns the metadata values of a field (scalars).
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The items.
   * @param array<string, mixed> $mapping
   *   The mapping (role, target_bundles).
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   *
   * @return list<string|int|float|bool>
   *   The values.
   */
  private function metadataValues(FieldItemListInterface $items, array $mapping, NormalizationContext $context, array $accounts): array {
    $type = $items->getFieldDefinition()->getType();
    if (in_array($type, FieldRoles::REFERENCE_TYPES, TRUE)) {
      $labels = [];
      foreach ($this->referencedEntities($items, $mapping, $context, $accounts, 'view label') as $entity) {
        $label = self::truncate(self::plain((string) $entity->label()));
        if ($label !== '') {
          $labels[] = $label;
        }
      }
      return $labels;
    }
    $values = [];
    foreach ($items as $item) {
      $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $raw = $property !== NULL ? $item->get($property)->getValue() : NULL;
      $value = match (TRUE) {
        $type === 'boolean' => (bool) $raw,
        // Metadata shows the labels of lists; filters keep the stored keys.
        in_array($type, FieldRoles::LIST_TYPES, TRUE) && $mapping['role'] === SourceInterface::ROLE_METADATA => $this->plainValue($item),
        in_array($type, ['integer', 'list_integer'], TRUE) && is_numeric($raw) => (int) $raw,
        in_array($type, ['decimal', 'float', 'list_float'], TRUE) && is_numeric($raw) => (float) $raw,
        in_array($type, FieldRoles::DATE_TYPES, TRUE) => $this->dateString($item),
        $item instanceof LinkItemInterface => $this->linkUrl($item),
        default => is_scalar($raw) ? self::plain(in_array($type, FieldRoles::FORMATTED_TEXT_TYPES, TRUE) ? strip_tags((string) $raw) : (string) $raw) : NULL,
      };
      if (is_string($value)) {
        $value = self::truncate($value);
      }
      if ($value !== NULL && $value !== '') {
        $values[] = $value;
      }
    }
    return $values;
  }

  /**
   * Returns metadata.image_url (absolute) and image_alt of the image role.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   *
   * @return array<string, string>
   *   The metadata (empty if there is no usable public image).
   */
  private function image(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context, array $accounts): array {
    foreach ($source->getFieldsByRole(SourceInterface::ROLE_IMAGE) as $mapping) {
      $items = $this->viewableItems($translation, $mapping['field'], $context, $accounts);
      if ($items === NULL) {
        continue;
      }
      foreach ($items as $item) {
        if (!$item instanceof EntityReferenceItem) {
          continue;
        }
        $entity = $item->get('entity')->getValue();
        $alt = $item->getFieldDefinition()->getType() === 'image' ? (string) $item->get('alt')->getValue() : '';
        if ($entity instanceof MediaInterface) {
          if (!$entity->isPublished() || !$this->aclResolver->canAccessAll($entity, $accounts)) {
            continue;
          }
          $sourceField = (string) ($entity->getSource()->getConfiguration()['source_field'] ?? '');
          if ($sourceField === '' || !$entity->hasField($sourceField) || $entity->get($sourceField)->isEmpty()) {
            continue;
          }
          $mediaLabel = (string) $entity->label();
          $mediaItem = $entity->get($sourceField)->first();
          if (!$mediaItem instanceof EntityReferenceItem) {
            continue;
          }
          $entity = $mediaItem->get('entity')->getValue();
          $alt = $mediaItem->getFieldDefinition()->getType() === 'image' ? (string) $mediaItem->get('alt')->getValue() : $mediaLabel;
        }
        if (!$entity instanceof FileInterface || !$entity->isPermanent() || !str_starts_with((string) $entity->getMimeType(), 'image/')) {
          continue;
        }
        $uri = $entity->getFileUri();
        if (!in_array(StreamWrapperManager::getScheme($uri), ['public', 'http', 'https'], TRUE)) {
          $context->addWarning(sprintf('%s ignored: only public images are referenced in metadata.', $mapping['field']));
          continue;
        }
        $metadata = ['image_url' => $this->urlResolver->fileUrl($uri)];
        $alt = self::truncate(self::plain($alt));
        if ($alt !== '') {
          $metadata['image_alt'] = $alt;
        }
        return $metadata;
      }
    }
    return [];
  }

  /**
   * Returns updated_at: the date role, else the changed time.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   *
   * @return string|null
   *   ISO 8601 date, or NULL.
   */
  private function updatedAt(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context, array $accounts): ?string {
    foreach ($source->getFieldsByRole(SourceInterface::ROLE_DATE) as $mapping) {
      $items = $this->viewableItems($translation, $mapping['field'], $context, $accounts);
      $date = $items !== NULL ? $this->dateString($items->first()) : NULL;
      if ($date !== NULL) {
        return $date;
      }
    }
    if ($translation instanceof EntityChangedInterface && $translation->getChangedTime() > 0) {
      return CanonicalDocument::formatDate((int) $translation->getChangedTime());
    }
    return NULL;
  }

  /**
   * Returns created_at from a "created" timestamp field, if any.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   *
   * @return string|null
   *   ISO 8601 date, or NULL.
   */
  private function createdAt(ContentEntityInterface $translation): ?string {
    if (!$translation->hasField('created')) {
      return NULL;
    }
    $type = $translation->get('created')->getFieldDefinition()->getType();
    if (!in_array($type, ['created', 'timestamp'], TRUE)) {
      return NULL;
    }
    $value = $translation->get('created')->value;
    return is_numeric($value) && (int) $value > 0 ? CanonicalDocument::formatDate((int) $value) : NULL;
  }

  /**
   * Formats a date item as ISO 8601 in UTC.
   *
   * @param \Drupal\Core\Field\FieldItemInterface|null $item
   *   A timestamp, created, changed, datetime or daterange item.
   *
   * @return string|null
   *   The date (date-only values keep the Y-m-d form), or NULL.
   */
  private function dateString(?FieldItemInterface $item): ?string {
    if ($item === NULL) {
      return NULL;
    }
    $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
    $value = $property !== NULL ? $item->get($property)->getValue() : NULL;
    if (in_array($item->getFieldDefinition()->getType(), ['timestamp', 'created', 'changed'], TRUE)) {
      return is_numeric($value) && (int) $value > 0 ? CanonicalDocument::formatDate((int) $value) : NULL;
    }
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value . 'T00:00:00+00:00';
    }
    try {
      // Datetime values are stored in UTC without offset.
      return CanonicalDocument::formatDate((new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp());
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Returns the absolute http(s) URL of a link item.
   *
   * @param \Drupal\link\LinkItemInterface $item
   *   The item.
   *
   * @return string|null
   *   The URL, or NULL for other schemes or invalid URIs.
   */
  private function linkUrl(LinkItemInterface $item): ?string {
    try {
      return $this->urlResolver->absoluteFromUrl($item->getUrl());
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Returns the human-readable value of a non-reference item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface|null $item
   *   The item.
   *
   * @return string
   *   List labels, on/off labels for booleans, ISO dates, plain text of
   *   formatted text, labels of references, or the main property.
   */
  private function plainValue(?FieldItemInterface $item): string {
    if ($item === NULL) {
      return '';
    }
    $definition = $item->getFieldDefinition();
    $type = $definition->getType();
    if ($item instanceof EntityReferenceItem) {
      $entity = $item->get('entity')->getValue();
      return $entity instanceof EntityInterface ? (string) $entity->label() : '';
    }
    if (in_array($type, FieldRoles::DATE_TYPES, TRUE)) {
      return (string) $this->dateString($item);
    }
    $property = $definition->getFieldStorageDefinition()->getMainPropertyName();
    $raw = $property !== NULL ? $item->get($property)->getValue() : NULL;
    if ($type === 'boolean') {
      $label = $definition->getSetting($raw ? 'on_label' : 'off_label');
      return (string) ($label ?? ($raw ? '1' : '0'));
    }
    if (in_array($type, FieldRoles::LIST_TYPES, TRUE) && $item instanceof OptionsProviderInterface && is_scalar($raw)) {
      $options = $item->getPossibleOptions();
      return (string) ($options[$raw] ?? $raw);
    }
    if (in_array($type, FieldRoles::FORMATTED_TEXT_TYPES, TRUE)) {
      return Html::decodeEntities(strip_tags((string) $raw));
    }
    return is_scalar($raw) ? (string) $raw : '';
  }

  /**
   * Collapses whitespace of a plain string.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   The value, trimmed.
   */
  private static function plain(string $value): string {
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Truncates a metadata string.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   At most MAX_METADATA_STRING characters.
   */
  private static function truncate(string $value): string {
    return Unicode::truncate($value, self::MAX_METADATA_STRING, TRUE, TRUE);
  }

}
