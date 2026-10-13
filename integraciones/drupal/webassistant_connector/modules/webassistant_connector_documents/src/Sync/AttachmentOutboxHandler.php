<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents\Sync;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxHandlerInterface;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use Drupal\webassistant_connector_documents\AttachmentFieldInspector;
use Drupal\webassistant_connector_documents\DocumentsSettings;

/**
 * Fans out attachment changes to the referencing entities (amendment A14).
 *
 * The hooks record one lightweight "_attachment" row per changed file or
 * media item (entity_type file|media, langcode "und"). This handler, run by
 * the outbox worker outside any transaction, records an upsert for:
 * - every entity translation whose mapped documents include the file (map
 *   rows with attachment = file UUID), whatever its source;
 * - every entity of an enabled source with attachments enabled whose
 *   configured fields reference the file, or a media item using it, found
 *   with entity queries without access checks.
 * The worker then rebuilds those translations from their current state: a
 * changed file gets a new version, a removed or unpublished one is deleted.
 * It never calls the platform.
 *
 * Service: webassistant_connector_documents.attachment_handler (tagged
 * webassistant_connector.outbox_handler).
 */
final class AttachmentOutboxHandler implements OutboxHandlerInterface {

  /**
   * Entity type of rows recorded for files.
   */
  public const TYPE_FILE = 'file';

  /**
   * Entity type of rows recorded for media items.
   */
  public const TYPE_MEDIA = 'media';

  /**
   * Language code of attachment rows.
   */
  public const LANGCODE = 'und';

  /**
   * Entities loaded at once.
   */
  private const CHUNK = 50;

  /**
   * Constructs an AttachmentOutboxHandler.
   *
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\webassistant_connector_documents\AttachmentFieldInspector $fieldInspector
   *   The attachment field inspector.
   */
  public function __construct(
    private readonly Outbox $outbox,
    private readonly DocumentMap $documentMap,
    private readonly SourceMatcher $sourceMatcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AttachmentFieldInspector $fieldInspector,
  ) {}

  /**
   * Records the change of a file (called from the hooks).
   *
   * @param string $operation
   *   OutboxItem::OPERATION_UPSERT, or OPERATION_DELETE when the file is
   *   being deleted (a hint: the worker decides from the current state).
   * @param string $fileId
   *   The file ID.
   * @param string $fileUuid
   *   The file UUID (finds the mapped documents of the file).
   */
  public function recordFile(string $operation, string $fileId, string $fileUuid): void {
    $this->outbox->recordKey($operation, Outbox::SOURCE_ATTACHMENT, self::TYPE_FILE, $fileId, self::LANGCODE, $fileUuid);
  }

  /**
   * Records the change of a media item (called from the hooks).
   *
   * @param string $operation
   *   OutboxItem::OPERATION_UPSERT or OPERATION_DELETE (a hint).
   * @param string $mediaId
   *   The media ID.
   * @param string $mediaUuid
   *   The media UUID.
   */
  public function recordMedia(string $operation, string $mediaId, string $mediaUuid): void {
    $this->outbox->recordKey($operation, Outbox::SOURCE_ATTACHMENT, self::TYPE_MEDIA, $mediaId, self::LANGCODE, $mediaUuid);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(OutboxItem $item): bool {
    return $item->source === Outbox::SOURCE_ATTACHMENT;
  }

  /**
   * {@inheritdoc}
   */
  public function process(OutboxItem $item): array {
    $fileIds = [];
    $mediaIds = [];
    $fileUuids = [];
    if ($item->entityType === self::TYPE_FILE) {
      $fileIds[] = $item->entityId;
      $mediaIds = $this->fieldInspector->mediaIdsUsingFile($item->entityId);
      if ($item->entityUuid !== '') {
        $fileUuids[] = $item->entityUuid;
      }
    }
    elseif ($item->entityType === self::TYPE_MEDIA) {
      $mediaIds[] = $item->entityId;
      $media = $this->entityTypeManager->hasDefinition('media') ? $this->entityTypeManager->getStorage('media')->load($item->entityId) : NULL;
      if ($media instanceof MediaInterface) {
        $file = $this->fieldInspector->mediaFile($media);
        if ($file !== NULL) {
          $fileUuids[] = (string) $file->uuid();
        }
      }
    }
    else {
      return ['skipped' => 'unknown_entity_type'];
    }

    $recorded = [];
    // Translations whose documents include the file, whatever their source.
    foreach ($fileUuids as $uuid) {
      foreach ($this->documentMap->findByAttachment($uuid) as $row) {
        $key = implode('|', [$row['source'], $row['entity_type'], $row['entity_id'], $row['langcode']]);
        if (!isset($recorded[$key]) && !str_starts_with((string) $row['source'], '_')) {
          $this->outbox->recordKey(OutboxItem::OPERATION_UPSERT, (string) $row['source'], (string) $row['entity_type'], (string) $row['entity_id'], (string) $row['langcode']);
          $recorded[$key] = TRUE;
        }
      }
    }

    // Entities of the enabled sources whose configured fields reference the
    // file or a media item using it.
    foreach ($this->sourceMatcher->getActiveSources() as $source) {
      $settings = DocumentsSettings::fromSource($source);
      if (!$settings->isActive()) {
        continue;
      }
      $ids = $this->referencingIds($source, $settings, $fileIds, $mediaIds);
      $this->recordEntities($source, $ids, $recorded);
    }

    return [
      'entity_type' => $item->entityType,
      'recorded' => count($recorded),
    ];
  }

  /**
   * Finds the entities of a source that reference the files or media.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector_documents\DocumentsSettings $settings
   *   Its attachment settings.
   * @param list<string> $fileIds
   *   File IDs.
   * @param list<string> $mediaIds
   *   Media IDs.
   *
   * @return list<string>
   *   Entity IDs of the source entity type.
   */
  private function referencingIds(SourceInterface $source, DocumentsSettings $settings, array $fileIds, array $mediaIds): array {
    $entityTypeId = $source->getTargetEntityTypeId();
    $eligible = $this->fieldInspector->eligibleFields($entityTypeId);
    $ids = [];
    foreach ($settings->fields as $fieldName) {
      $kind = $eligible[$fieldName]['kind'] ?? NULL;
      $targets = match ($kind) {
        AttachmentFieldInspector::KIND_FILE => $fileIds,
        AttachmentFieldInspector::KIND_MEDIA => $mediaIds,
        default => [],
      };
      foreach ($this->fieldInspector->findReferencing($entityTypeId, $fieldName, $targets, $source->getBundles()) as $id) {
        $ids[$id] = $id;
      }
    }
    return array_values($ids);
  }

  /**
   * Records an upsert for every covered translation of some entities.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param list<string> $ids
   *   Entity IDs.
   * @param array<string, true> $recorded
   *   Keys already recorded, by reference.
   */
  private function recordEntities(SourceInterface $source, array $ids, array &$recorded): void {
    if ($ids === []) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage($source->getTargetEntityTypeId());
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $entity) {
        if (!$entity instanceof ContentEntityInterface || !$source->appliesTo($entity)) {
          continue;
        }
        foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
          $langcode = (string) $langcode;
          $key = implode('|', [(string) $source->id(), $entity->getEntityTypeId(), (string) $entity->id(), $langcode]);
          if (isset($recorded[$key]) || !$source->appliesToLangcode($langcode)) {
            continue;
          }
          $this->outbox->record(OutboxItem::OPERATION_UPSERT, $source, $entity, $langcode);
          $recorded[$key] = TRUE;
        }
      }
      // Long fan-outs must not fill the memory (amendment A13).
      $storage->resetCache($chunk);
    }
  }

}
