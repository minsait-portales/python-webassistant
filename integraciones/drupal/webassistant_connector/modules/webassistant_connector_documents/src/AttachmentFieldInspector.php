<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\file\FileInterface;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\media\MediaInterface;
use Drupal\media\Plugin\media\Source\File as FileSource;

/**
 * Finds the fields that hold attachments and the files behind them.
 *
 * Attachment fields are stored file fields (file, image and any field type
 * whose item class extends FileItem; not the generated media thumbnails) and
 * entity references to media whose type uses a file-based source plugin
 * (File, Image, AudioFile, VideoFile). Media is optional: without the media
 * module only file fields qualify.
 *
 * Service: webassistant_connector_documents.field_inspector.
 */
final class AttachmentFieldInspector {

  /**
   * Kind of field: stores files.
   */
  public const KIND_FILE = 'file';

  /**
   * Kind of field: references media items with a file source.
   */
  public const KIND_MEDIA = 'media';

  /**
   * Constructs an AttachmentFieldInspector.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $fieldTypeManager
   *   The field type plugin manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly FieldTypePluginManagerInterface $fieldTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns the attachment fields of an entity type.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return array<string, array{label: string, kind: string, bundles: list<string>}>
   *   Fields keyed by name, sorted by name: label (of the last bundle), kind
   *   (KIND_* constant) and the bundles where the field qualifies.
   */
  public function eligibleFields(string $entityTypeId): array {
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if ($entityType === NULL || !$entityType->entityClassImplements(FieldableEntityInterface::class)) {
      return [];
    }
    $fields = [];
    foreach ($this->entityFieldManager->getFieldMap()[$entityTypeId] ?? [] as $name => $info) {
      $kind = NULL;
      $label = (string) $name;
      $bundles = [];
      // Media thumbnails are images generated from the source.
      if ($entityTypeId === 'media' && $name === 'thumbnail') {
        continue;
      }
      foreach ($info['bundles'] ?? [] as $bundle) {
        $definition = $this->entityFieldManager->getFieldDefinitions($entityTypeId, (string) $bundle)[$name] ?? NULL;
        $definitionKind = $definition !== NULL && !$definition->isComputed() ? $this->kindOf($definition) : NULL;
        if ($definitionKind === NULL) {
          continue;
        }
        $kind = $definitionKind;
        $label = (string) $definition->getLabel();
        $bundles[] = (string) $bundle;
      }
      if ($kind !== NULL) {
        sort($bundles);
        $fields[(string) $name] = ['label' => $label, 'kind' => $kind, 'bundles' => $bundles];
      }
    }
    ksort($fields);
    return $fields;
  }

  /**
   * Returns the kind of attachment field of a definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition (of a bundle).
   *
   * @return string|null
   *   KIND_FILE, KIND_MEDIA, or NULL if the field holds no attachments.
   */
  public function kindOf(FieldDefinitionInterface $definition): ?string {
    $type = $this->fieldTypeManager->getDefinition($definition->getType(), FALSE);
    $class = is_array($type) ? ($type['class'] ?? NULL) : NULL;
    if (!is_string($class)) {
      return NULL;
    }
    if (is_a($class, FileItem::class, TRUE)) {
      return self::KIND_FILE;
    }
    if (!is_a($class, EntityReferenceItem::class, TRUE) || $definition->getSetting('target_type') !== 'media') {
      return NULL;
    }
    $fileTypes = array_keys($this->fileMediaTypes());
    if ($fileTypes === []) {
      return NULL;
    }
    $handlerSettings = $definition->getSetting('handler_settings');
    $targetBundles = is_array($handlerSettings) && is_array($handlerSettings['target_bundles'] ?? NULL) ? $handlerSettings['target_bundles'] : [];
    if ($targetBundles === []) {
      return self::KIND_MEDIA;
    }
    return array_intersect(array_map('strval', array_keys($targetBundles)), $fileTypes) !== [] ? self::KIND_MEDIA : NULL;
  }

  /**
   * Returns the media types whose source plugin stores a file.
   *
   * @return array<string, string>
   *   Source field names keyed by media type ID (empty without media).
   */
  public function fileMediaTypes(): array {
    if (!$this->moduleHandler->moduleExists('media') || !$this->entityTypeManager->hasDefinition('media_type')) {
      return [];
    }
    $types = [];
    /** @var \Drupal\media\MediaTypeInterface $type */
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $id => $type) {
      $source = $type->getSource();
      $field = $source->getConfiguration()['source_field'] ?? '';
      if ($source instanceof FileSource && is_string($field) && $field !== '') {
        $types[(string) $id] = $field;
      }
    }
    ksort($types);
    return $types;
  }

  /**
   * Returns the ID of the file of a media item with a file source.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media item.
   *
   * @return string|null
   *   The file ID, or NULL if the media type has no file source or the
   *   source field is empty.
   */
  public function mediaFileId(MediaInterface $media): ?string {
    $field = $this->fileMediaTypes()[$media->bundle()] ?? NULL;
    if ($field === NULL || !$media->hasField($field) || $media->get($field)->isEmpty()) {
      return NULL;
    }
    $value = $media->get($field)->first()?->get('target_id')->getValue();
    return is_scalar($value) && (string) $value !== '' ? (string) $value : NULL;
  }

  /**
   * Loads the file of a media item with a file source.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media item.
   * @param bool $fresh
   *   TRUE to bypass the static entity cache (loadUnchanged()).
   *
   * @return \Drupal\file\FileInterface|null
   *   The file, or NULL.
   */
  public function mediaFile(MediaInterface $media, bool $fresh = FALSE): ?FileInterface {
    $fileId = $this->mediaFileId($media);
    if ($fileId === NULL) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $file = $fresh ? $storage->loadUnchanged($fileId) : $storage->load($fileId);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Returns the media items whose file source stores a file.
   *
   * @param string $fileId
   *   The file ID.
   *
   * @return list<string>
   *   Media IDs (no access check: used to find what must be resynchronized).
   */
  public function mediaIdsUsingFile(string $fileId): array {
    $byField = [];
    foreach ($this->fileMediaTypes() as $type => $field) {
      $byField[$field][] = $type;
    }
    if ($byField === []) {
      return [];
    }
    $storages = $this->entityFieldManager->getFieldStorageDefinitions('media');
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = [];
    foreach ($byField as $field => $types) {
      if (!isset($storages[$field])) {
        continue;
      }
      $result = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $types, 'IN')
        ->condition($field . '.target_id', $fileId)
        ->execute();
      foreach ($result as $id) {
        $ids[(string) $id] = (string) $id;
      }
    }
    return array_values($ids);
  }

  /**
   * Returns the entities whose field references some targets.
   *
   * Entity query on the default revisions, without access checks (it finds
   * what must be resynchronized; the worker checks access when it builds
   * the documents).
   *
   * @param string $entityTypeId
   *   The entity type of the referencing entities.
   * @param string $fieldName
   *   The file or media reference field.
   * @param list<string> $targetIds
   *   The referenced file or media IDs.
   * @param list<string> $bundles
   *   Restrict to these bundles (empty: all).
   *
   * @return list<string>
   *   Entity IDs (empty if the field does not exist).
   */
  public function findReferencing(string $entityTypeId, string $fieldName, array $targetIds, array $bundles = []): array {
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if ($targetIds === [] || $entityType === NULL || !$entityType->entityClassImplements(FieldableEntityInterface::class)) {
      return [];
    }
    if (!isset($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId)[$fieldName])) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage($entityTypeId)->getQuery()
      ->accessCheck(FALSE)
      ->condition($fieldName . '.target_id', $targetIds, 'IN');
    $bundleKey = $entityType->getKey('bundle');
    if (is_string($bundleKey) && $bundleKey !== '' && $bundles !== []) {
      $query->condition($bundleKey, $bundles, 'IN');
    }
    return array_values(array_map('strval', $query->execute()));
  }

}
