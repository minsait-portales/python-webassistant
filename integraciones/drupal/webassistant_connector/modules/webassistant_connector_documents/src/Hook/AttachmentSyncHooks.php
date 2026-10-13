<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use Drupal\webassistant_connector_documents\AttachmentFieldInspector;
use Drupal\webassistant_connector_documents\DocumentsSettings;
use Drupal\webassistant_connector_documents\Sync\AttachmentOutboxHandler;

/**
 * Captures changes of files and media items (design 11, amendment A14).
 *
 * Each change records ONE lightweight "_attachment" outbox row (a single
 * merge inside the entity transaction, no HTTP, no entity queries). The
 * outbox worker fans it out to the referencing entities through
 * AttachmentOutboxHandler. Nothing is recorded while no enabled source has
 * attachments enabled.
 */
final class AttachmentSyncHooks {

  /**
   * Constructs an AttachmentSyncHooks object.
   *
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher (enabled sources, cached in memory).
   * @param \Drupal\webassistant_connector_documents\Sync\AttachmentOutboxHandler $handler
   *   The attachment handler (records the rows).
   * @param \Drupal\webassistant_connector_documents\AttachmentFieldInspector $fieldInspector
   *   The attachment field inspector.
   */
  public function __construct(
    private readonly SourceMatcher $sourceMatcher,
    private readonly AttachmentOutboxHandler $handler,
    private readonly AttachmentFieldInspector $fieldInspector,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_update() for file.
   */
  #[Hook('file_update')]
  public function fileUpdate(FileInterface $file): void {
    $original = $file->getOriginal();
    // A file that stays temporary was never indexed.
    if ($original instanceof FileInterface && $original->isTemporary() && $file->isTemporary()) {
      return;
    }
    if ($this->hasAttachmentSources()) {
      $this->handler->recordFile(OutboxItem::OPERATION_UPSERT, (string) $file->id(), (string) $file->uuid());
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for file.
   */
  #[Hook('file_predelete')]
  public function filePredelete(FileInterface $file): void {
    if ($this->hasAttachmentSources()) {
      $this->handler->recordFile(OutboxItem::OPERATION_DELETE, (string) $file->id(), (string) $file->uuid());
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for media.
   */
  #[Hook('media_update')]
  public function mediaUpdate(MediaInterface $media): void {
    if (!$this->hasAttachmentSources() || !isset($this->fieldInspector->fileMediaTypes()[$media->bundle()])) {
      return;
    }
    $this->handler->recordMedia(OutboxItem::OPERATION_UPSERT, (string) $media->id(), (string) $media->uuid());
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for media.
   */
  #[Hook('media_delete')]
  public function mediaDelete(MediaInterface $media): void {
    if (!$this->hasAttachmentSources() || !isset($this->fieldInspector->fileMediaTypes()[$media->bundle()])) {
      return;
    }
    $this->handler->recordMedia(OutboxItem::OPERATION_DELETE, (string) $media->id(), (string) $media->uuid());
    // The mapped documents of its file are found by the file UUID, even if
    // no field references the deleted media item any more.
    $file = $this->fieldInspector->mediaFile($media);
    if ($file !== NULL) {
      $this->handler->recordFile(OutboxItem::OPERATION_UPSERT, (string) $file->id(), (string) $file->uuid());
    }
  }

  /**
   * Whether an enabled source has attachments enabled.
   */
  private function hasAttachmentSources(): bool {
    foreach ($this->sourceMatcher->getActiveSources() as $source) {
      if (DocumentsSettings::fromSource($source)->isActive()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
