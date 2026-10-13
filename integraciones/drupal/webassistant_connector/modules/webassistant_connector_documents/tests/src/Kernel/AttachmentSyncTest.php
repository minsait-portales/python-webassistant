<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Kernel;

use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SyncReport;
use Drupal\webassistant_connector_documents\Hook\AttachmentSyncHooks;
use Drupal\webassistant_connector_documents\Sync\AttachmentOutboxHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the capture and fan-out of attachment changes (amendment A14).
 *
 * @group webassistant_connector
 */
#[CoversClass(AttachmentSyncHooks::class)]
#[CoversClass(AttachmentOutboxHandler::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class AttachmentSyncTest extends DocumentsKernelTestBase {

  /**
   * Claims and processes the due outbox rows of one pseudo-source.
   *
   * @return int
   *   Number of rows processed.
   */
  private function processAttachmentRows(): int {
    $outbox = $this->container->get('webassistant_connector.outbox');
    $processor = $this->container->get('webassistant_connector.sync_processor');
    $count = 0;
    foreach ($outbox->claim(100) as $item) {
      if ($item->source !== Outbox::SOURCE_ATTACHMENT) {
        $outbox->release($item);
        continue;
      }
      $processor->processItem($item, new SyncReport());
      $count++;
    }
    return $count;
  }

  /**
   * Updating a file records one row that fans out to its articles.
   */
  public function testFileUpdate(): void {
    $file = $this->createFile();
    $node = $this->createArticle(['field_document' => [['target_id' => $file->id(), 'display' => 1]]]);
    $other = $this->createArticle(['title' => 'Unrelated']);
    $this->clearOutbox();

    $file->setFilename('manual-v2.pdf');
    $file->save();
    $rows = $this->outboxRows();
    $key = Outbox::SOURCE_ATTACHMENT . ':file:' . $file->id() . ':und';
    $this->assertSame([$key], array_keys($rows), 'Only the lightweight attachment row is recorded.');
    $this->assertSame((string) $file->uuid(), $rows[$key]->entityUuid);
    $this->assertSame(OutboxItem::OPERATION_UPSERT, $rows[$key]->operation);

    // A mapped document of the file in another source is refreshed too.
    $this->container->get('webassistant_connector.document_map')->save([
      'knowledge_base' => 'legacy',
      'remote_id' => self::PREFIX . ':node:' . $other->id() . ':en:file:' . $file->uuid(),
      'source' => 'legacy_source',
      'entity_type' => 'node',
      'entity_id' => (string) $other->id(),
      'langcode' => 'en',
      'attachment' => (string) $file->uuid(),
      'version' => 'm1.0',
      'content_hash' => '',
      'payload_hash' => '',
      'acl' => ['public'],
      'remote_status' => 'ready',
    ]);

    $this->assertSame(1, $this->processAttachmentRows());
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::STATUS_DONE, $rows[$key]->status);
    $this->assertSame(['entity_type' => 'file', 'recorded' => 2], $rows[$key]->remote);
    $this->assertArrayHasKey('articles:node:' . $node->id() . ':en', $rows);
    $this->assertSame(OutboxItem::STATUS_PENDING, $rows['articles:node:' . $node->id() . ':en']->status);
    $this->assertArrayHasKey('legacy_source:node:' . $other->id() . ':en', $rows);
    $this->assertArrayNotHasKey('articles:node:' . $other->id() . ':en', $rows);

    // Deleting the file records the row again (delete hint).
    $this->clearOutbox();
    $file->delete();
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::OPERATION_DELETE, $rows[$key]->operation);
  }

  /**
   * Media changes fan out through the media reference fields.
   */
  public function testMediaChanges(): void {
    $file = $this->createFile('sheet.pdf');
    $media = $this->createMedia($file);
    $node = $this->createArticle(['field_media' => [['target_id' => $media->id()]]]);
    $nodeKey = 'articles:node:' . $node->id() . ':en';
    $mediaKey = Outbox::SOURCE_ATTACHMENT . ':media:' . $media->id() . ':und';
    $fileKey = Outbox::SOURCE_ATTACHMENT . ':file:' . $file->id() . ':und';
    $this->clearOutbox();

    $media->setName('Product sheet v2')->save();
    $this->assertSame([$mediaKey], array_keys($this->outboxRows()));
    $this->processAttachmentRows();
    $this->assertArrayHasKey($nodeKey, $this->outboxRows());

    // Changing the file of a media item reaches the article through the
    // media item.
    $this->clearOutbox();
    $file->setFilename('sheet-v2.pdf');
    $file->save();
    $this->processAttachmentRows();
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::STATUS_DONE, $rows[$fileKey]->status);
    $this->assertArrayHasKey($nodeKey, $rows);

    // Deleting the media item records it and its file; the dangling
    // reference still finds the article.
    $this->clearOutbox();
    $media->delete();
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::OPERATION_DELETE, $rows[$mediaKey]->operation);
    $this->assertArrayHasKey($fileKey, $rows);
    $this->assertSame(2, $this->processAttachmentRows());
    $this->assertArrayHasKey($nodeKey, $this->outboxRows());
  }

  /**
   * Nothing is recorded without sources with attachments enabled.
   */
  public function testNoAttachmentSources(): void {
    $file = $this->createFile();
    $this->createArticle(['field_document' => [['target_id' => $file->id(), 'display' => 1]]]);
    $temporary = $this->createFile('draft.pdf', self::PDF_BYTES, FALSE);
    $this->clearOutbox();

    // A file that stays temporary was never indexed.
    $temporary->setFilename('draft-2.pdf');
    $temporary->save();
    $this->assertSame([], $this->outboxRows());

    $this->setDocumentsSettings(['enabled' => FALSE]);
    $this->clearOutbox();
    $file->setFilename('manual-v2.pdf');
    $file->save();
    $this->assertSame([], $this->outboxRows());

    // A disabled source does not count either.
    $this->setDocumentsSettings([]);
    $this->source->disable()->save();
    $this->container->get('webassistant_connector.source_matcher')->reset();
    $this->clearOutbox();
    $file->setFilename('manual-v3.pdf');
    $file->save();
    $this->assertSame([], $this->outboxRows());
  }

}
