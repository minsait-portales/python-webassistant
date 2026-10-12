<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\SyncPipeline;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\webassistant_connector\Hook\SyncHooks;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector_sync_test\Hook\SyncTestHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests change capture and the outbox (design 9.1-9.2, amendments A1-A4, A7).
 *
 * @group webassistant_connector
 */
#[CoversClass(SyncHooks::class)]
#[CoversClass(Outbox::class)]
#[CoversClass(OutboxItem::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class OutboxCaptureTest extends SyncKernelTestBase {

  /**
   * Inserts record every translation; updates coalesce into the same rows.
   */
  public function testInsertAndUpdateCoalesce(): void {
    $node = $this->createArticle();
    $rows = $this->outboxRows();
    $this->assertSame([$this->key($node, 'en'), $this->key($node, 'es')], array_keys($rows));
    foreach ($rows as $row) {
      $this->assertSame(OutboxItem::STATUS_PENDING, $row->status);
      $this->assertSame(OutboxItem::OPERATION_UPSERT, $row->operation);
      $this->assertSame(1, $row->seq);
      $this->assertSame($node->uuid(), $row->entityUuid);
      $this->assertSame((string) $node->getRevisionId(), $row->revisionId);
    }
    $this->assertSame(1, $this->queueCount(Outbox::DISPATCH_QUEUE), 'One dispatch ticket after the save.');

    // A failed row goes back to pending with its attempts reset.
    $this->updateOutbox([
      'status' => OutboxItem::STATUS_FAILED,
      'attempts' => 3,
      'next_attempt' => time() + 1000,
      'last_error' => 'Boom',
    ], $this->key($node, 'en'));
    $node->setTitle('Hello again')->save();

    $rows = $this->outboxRows();
    $this->assertCount(2, $rows, 'Updates coalesce into the existing rows.');
    $english = $rows[$this->key($node, 'en')];
    $this->assertSame(OutboxItem::STATUS_PENDING, $english->status);
    $this->assertSame(2, $english->seq);
    $this->assertSame(0, $english->attempts);
    $this->assertNull($english->lastError);
    $this->assertLessThanOrEqual(time(), $english->nextAttempt);
    $this->assertSame(1, $this->queueCount(Outbox::DISPATCH_QUEUE), 'No second ticket while one is waiting.');
  }

  /**
   * Only enabled sources, covered bundles and covered languages are captured.
   */
  public function testScope(): void {
    Node::create(['type' => 'page', 'title' => 'Not covered'])->save();
    $this->assertSame([], $this->outboxRows());
    $this->assertSame(0, $this->queueCount(Outbox::DISPATCH_QUEUE));

    $this->source->set('langcodes', ['es'])->save();
    $node = $this->createArticle();
    $this->assertSame([$this->key($node, 'es')], array_keys($this->outboxRows()));

    $this->source->disable()->save();
    $node->setTitle('Changed')->save();
    $this->assertSame(1, $this->outboxRows()[$this->key($node, 'es')]->seq, 'Disabled sources capture nothing.');
  }

  /**
   * Non-default revisions (drafts) are ignored.
   */
  public function testDraftsAreIgnored(): void {
    $node = $this->createArticle();
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision(FALSE);
    $node->setTitle('Draft');
    $node->save();
    foreach ($this->outboxRows() as $row) {
      $this->assertSame(1, $row->seq);
    }
  }

  /**
   * Removed translations and deleted entities record delete hints.
   */
  public function testTranslationRemovalAndDelete(): void {
    $node = $this->createArticle();
    $node->removeTranslation('es');
    $node->save();
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::OPERATION_DELETE, $rows[$this->key($node, 'es')]->operation);
    $this->assertSame(2, $rows[$this->key($node, 'es')]->seq);
    $this->assertSame(OutboxItem::OPERATION_UPSERT, $rows[$this->key($node, 'en')]->operation);

    $node->delete();
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::OPERATION_DELETE, $rows[$this->key($node, 'en')]->operation);
    $this->assertSame(3, $rows[$this->key($node, 'en')]->seq);
  }

  /**
   * A rolled back save leaves neither a row nor a dispatch ticket.
   */
  public function testRollbackLeavesNothing(): void {
    $database = $this->container->get('database');
    $transaction = $database->startTransaction();
    Node::create(['type' => 'article', 'title' => 'Rolled back'])->save();
    $this->assertCount(1, $this->outboxRows(), 'The capture runs inside the transaction.');
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([], $this->outboxRows());
    $this->assertSame(0, $this->queueCount(Outbox::DISPATCH_QUEUE), 'No ticket for a rolled back capture.');

    // A failure later in the same save: the storage rolls back the capture.
    SyncTestHooks::$failNodeInsert = TRUE;
    try {
      Node::create(['type' => 'article', 'title' => 'Failing'])->save();
      $this->fail('The save should fail.');
    }
    catch (EntityStorageException $e) {
      $this->assertSame('Simulated failure after the capture.', $e->getMessage());
    }
    SyncTestHooks::$failNodeInsert = FALSE;
    $this->assertSame(1, SyncTestHooks::$rowsSeen, 'The capture ran before the failing hook.');
    $this->assertSame([], $this->outboxRows());
    $this->assertSame(0, $this->queueCount(Outbox::DISPATCH_QUEUE));

    // The next successful save still gets its ticket.
    $this->createArticle();
    $this->assertSame(1, $this->queueCount(Outbox::DISPATCH_QUEUE));
  }

  /**
   * Claims use leases; finishing is guarded by seq and the claim token.
   */
  public function testClaimLeaseAndSequenceGuard(): void {
    $outbox = $this->container->get('webassistant_connector.outbox');
    $outbox->recordKey(OutboxItem::OPERATION_UPSERT, 'articles', 'node', '7', 'en', 'uuid-7', '70');
    $claimed = $outbox->claim(10);
    $this->assertCount(1, $claimed);
    $item = $claimed[0];
    $this->assertSame(OutboxItem::STATUS_PROCESSING, $item->status);
    $this->assertNotNull($item->claimToken);
    $this->assertGreaterThan(time(), $item->leaseUntil);
    $this->assertSame(1, $item->claimedSeq);
    $this->assertSame([], $outbox->claim(10), 'A leased row is not claimed twice.');

    // A capture while processing keeps the row processing.
    $outbox->recordKey(OutboxItem::OPERATION_DELETE, 'articles', 'node', '7', 'en');
    $row = $outbox->load($item->id);
    $this->assertSame(OutboxItem::STATUS_PROCESSING, $row->status);
    $this->assertSame(2, $row->seq);
    $this->assertSame('uuid-7', $row->entityUuid, 'An empty UUID does not overwrite the known one.');
    $this->assertFalse($outbox->markDone($item), 'The stale claim cannot finish the row.');
    $row = $outbox->load($item->id);
    $this->assertSame(OutboxItem::STATUS_PENDING, $row->status);
    $this->assertNull($row->claimToken);

    // The fresh claim finishes it.
    $item = $outbox->claim(10)[0];
    $this->assertSame(2, $item->claimedSeq);
    $this->assertTrue($outbox->markDone($item, ['documents' => 1], 'corr-1'));
    $row = $outbox->load($item->id);
    $this->assertSame(OutboxItem::STATUS_DONE, $row->status);
    $this->assertSame(['documents' => 1], $row->remote);
    $this->assertSame('corr-1', $row->correlationId);

    // An expired lease can be claimed again; the old claim is void.
    $outbox->recordKey(OutboxItem::OPERATION_UPSERT, 'articles', 'node', '7', 'en');
    $first = $outbox->claim(10)[0];
    $this->updateOutbox(['lease_until' => time() - 1]);
    $second = $outbox->claim(10)[0];
    $this->assertNotSame($first->claimToken, $second->claimToken);
    $this->assertFalse($outbox->markFailed($first, 'late', time() + 60));
    $this->assertSame($second->claimToken, $outbox->load($first->id)->claimToken, 'The old claim does not touch the new one.');
    $this->assertTrue($outbox->markFailed($second, 'Platform down', time() + 60, 'corr-2'));
    $row = $outbox->load($first->id);
    $this->assertSame(OutboxItem::STATUS_FAILED, $row->status);
    $this->assertSame(1, $row->attempts);
    $this->assertSame('Platform down', $row->lastError);
    $this->assertSame([], $outbox->claim(10), 'Failed rows wait for next_attempt.');
  }

  /**
   * Retry, release, purge, counters and orphans.
   */
  public function testRetryPurgeAndOrphans(): void {
    $outbox = $this->container->get('webassistant_connector.outbox');
    foreach (['1', '2', '3', '4'] as $id) {
      $outbox->recordKey(OutboxItem::OPERATION_UPSERT, 'articles', 'node', $id, 'en');
    }
    $this->updateOutbox(['status' => OutboxItem::STATUS_FAILED, 'attempts' => 2, 'next_attempt' => time() + 600], 'articles:node:1:en');
    $this->updateOutbox(['status' => OutboxItem::STATUS_DEAD, 'attempts' => 8], 'articles:node:2:en');
    $this->updateOutbox(['status' => OutboxItem::STATUS_DONE, 'changed' => time() - 10 * 86400], 'articles:node:3:en');
    $this->assertSame([
      OutboxItem::STATUS_PENDING => 1,
      OutboxItem::STATUS_PROCESSING => 0,
      OutboxItem::STATUS_DONE => 1,
      OutboxItem::STATUS_FAILED => 1,
      OutboxItem::STATUS_DEAD => 1,
    ], $outbox->countByStatus());
    $this->assertSame(1, $outbox->countDue());

    $this->assertSame(1, $outbox->retry());
    $this->assertSame(OutboxItem::STATUS_DEAD, $this->outboxRows()['articles:node:2:en']->status, 'Dead rows need an explicit retry.');
    $dead = $this->outboxRows()['articles:node:2:en'];
    $this->assertSame(0, $outbox->retry([OutboxItem::STATUS_DEAD], $dead->id + 1000));
    $this->assertSame(1, $outbox->retry([OutboxItem::STATUS_DEAD], $dead->id));
    $this->assertSame(0, $this->outboxRows()['articles:node:2:en']->attempts);
    $this->assertSame(3, $outbox->countDue());

    $this->assertSame(1, $outbox->purge(time() - 7 * 86400));
    $this->assertArrayNotHasKey('articles:node:3:en', $this->outboxRows());

    // Released claims are due again.
    $claimed = $outbox->claim(1);
    $outbox->release($claimed[0]);
    $this->assertSame(3, $outbox->countDue());

    $outbox->recordOrphan('help', 'drupal-test:node:999:en');
    $outbox->recordOrphan('help', 'drupal-test:node:999:en');
    $orphans = array_filter($this->outboxRows(), static fn (OutboxItem $item): bool => $item->source === Outbox::SOURCE_ORPHAN);
    $this->assertCount(1, $orphans);
    $orphan = reset($orphans);
    $this->assertSame(['knowledge_base' => 'help', 'remote_id' => 'drupal-test:node:999:en'], $orphan->remote);
    $this->assertSame(OutboxItem::OPERATION_DELETE, $orphan->operation);
    $this->assertSame(2, $orphan->seq);
    $this->assertTrue($orphan->isPseudoSource());
  }

}
