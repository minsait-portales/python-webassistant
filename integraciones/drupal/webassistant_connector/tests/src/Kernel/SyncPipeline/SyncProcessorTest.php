<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\SyncPipeline;

use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\node\NodeInterface;
use Drupal\webassistant_connector\Hook\SourceLifecycleHooks;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Plugin\QueueWorker\DispatchWorker;
use Drupal\webassistant_connector\Plugin\QueueWorker\IndexWorker;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SyncProcessor;
use Drupal\webassistant_connector\Sync\SyncReport;
use Drupal\webassistant_connector_sync_test\ScriptedPlatformClient;
use Drupal\webassistant_connector_sync_test\TestOutboxHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests outbox processing (design 9.3, amendments A3-A5, A8, A9, A11, A15).
 *
 * @group webassistant_connector
 */
#[CoversClass(SyncProcessor::class)]
#[CoversClass(DocumentMap::class)]
#[CoversClass(SyncReport::class)]
#[CoversClass(DispatchWorker::class)]
#[CoversClass(IndexWorker::class)]
#[CoversClass(Indexer::class)]
#[CoversClass(SourceLifecycleHooks::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class SyncProcessorTest extends SyncKernelTestBase {

  /**
   * The article under test (English and Spanish).
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->node = $this->createArticle();
  }

  /**
   * Returns the processor.
   */
  private function processor(): SyncProcessor {
    return $this->container->get('webassistant_connector.sync_processor');
  }

  /**
   * Upserts are sent in batches, mapped, and unchanged documents skipped.
   */
  public function testUpsertBatchesMapAndUnchanged(): void {
    $this->config('webassistant_connector.settings')->set('sync.batch_size', 1)->save();
    $report = $this->processor()->process(100, 0);

    $this->assertSame(2, $report->getProcessed());
    $this->assertSame(2, $report->getUpserted());
    $this->assertFalse($report->hasFailures());
    $this->assertCount(2, ScriptedPlatformClient::callsOf('ingest'), 'One request per batch of one document.');
    $documents = ScriptedPlatformClient::ingestedDocuments();
    $this->assertSame([$this->remoteId($this->node, 'en'), $this->remoteId($this->node, 'es')], array_column($documents, 'source_id'));
    $this->assertSame('help', $documents[0]['knowledge_base']);
    $this->assertSame(['public'], $documents[0]['acl']);

    $map = $this->mapRows();
    $this->assertCount(2, $map);
    $row = $map[$this->remoteId($this->node, 'en')];
    $this->assertSame('articles', $row['source']);
    $this->assertSame((string) $this->node->id(), $row['entity_id']);
    $this->assertSame('queued', $row['remote_status']);
    $this->assertSame($documents[0]['version'], $row['version']);
    $this->assertSame(DocumentMap::payloadHash($documents[0]), $row['payload_hash']);
    $this->assertSame(['public'], $row['acl']);

    foreach ($this->outboxRows() as $item) {
      $this->assertSame(OutboxItem::STATUS_DONE, $item->status);
      $this->assertSame(1, $item->remote['sent']);
    }
    $this->assertNotNull($this->container->get('state')->get(SyncProcessor::STATE_LAST_SUCCESS));
    $this->assertNotNull($this->container->get('webassistant_connector.sync_status')->averageLatency());

    // Saving without changes records events but sends nothing (A8).
    $this->node->save();
    $report = $this->processor()->process(100, 0);
    $this->assertSame(2, $report->getProcessed());
    $this->assertSame(0, $report->getUpserted());
    $this->assertSame(2, $report->getUnchanged());
    $this->assertCount(2, ScriptedPlatformClient::callsOf('ingest'));

    // A changed title is sent again.
    $this->node->setTitle('Hello, world')->save();
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getUpserted());
    $this->assertSame('Hello, world', ScriptedPlatformClient::ingestedDocuments()[2]['title']);
  }

  /**
   * Skipped translations and deleted entities delete their documents.
   */
  public function testSkipAndDeleteRemoveMappedDocuments(): void {
    $this->processor()->process(100, 0);
    $this->assertCount(2, $this->mapRows());

    $this->node->getTranslation('es')->setUnpublished();
    $this->node->save();
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getSkipped());
    $this->assertSame(1, $report->getDeleted());
    $this->assertSame([['help', $this->remoteId($this->node, 'es')]], ScriptedPlatformClient::callsOf('deleteDocument'));
    $this->assertSame([$this->remoteId($this->node, 'en')], array_keys($this->mapRows()));
    $this->assertSame('unpublished', $this->outboxRows()[$this->key($this->node, 'es')]->remote['skip_reason']);

    $this->node->delete();
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getDeleted());
    $this->assertSame([], $this->mapRows());
    $this->assertCount(2, ScriptedPlatformClient::callsOf('deleteDocument'));
  }

  /**
   * A translation removed without an original to compare is still deleted.
   *
   * Publishing a workspace saves the entity with itself as the original, so
   * hook_entity_update() cannot see the removed translation.
   */
  public function testTranslationRemovedWithoutOriginalIsDeleted(): void {
    $this->processor()->process(100, 0);
    $this->assertCount(2, $this->mapRows());

    $this->node->removeTranslation('es');
    $this->node->setTitle('Hello again');
    $this->node->setOriginal(clone $this->node);
    $this->node->save();
    $this->assertSame(OutboxItem::STATUS_DONE, $this->outboxRows()[$this->key($this->node, 'es')]->status, 'The hook saw no removed translation.');

    $this->processor()->process(100, 0);
    $this->assertSame([['help', $this->remoteId($this->node, 'es')]], ScriptedPlatformClient::callsOf('deleteDocument'));
    $this->assertSame([$this->remoteId($this->node, 'en')], array_keys($this->mapRows()));
    $this->assertSame('translation_missing', $this->outboxRows()[$this->key($this->node, 'es')]->remote['skip_reason']);
  }

  /**
   * A mapped document that is not generated anymore is deleted.
   */
  public function testRemovedAttachmentIsDeleted(): void {
    $this->processor()->process(100, 0);
    $attachmentId = $this->remoteId($this->node, 'en') . ':file:0b5c3e5e-1111-4222-8333-944444444444';
    $this->documentMap()->save([
      'knowledge_base' => 'docs',
      'remote_id' => $attachmentId,
      'source' => 'articles',
      'entity_type' => 'node',
      'entity_id' => (string) $this->node->id(),
      'langcode' => 'en',
      'attachment' => '0b5c3e5e-1111-4222-8333-944444444444',
      'version' => 'm1.abc',
      'content_hash' => 'abc',
      'payload_hash' => 'abc',
      'acl' => ['public'],
      'remote_status' => 'ready',
    ]);
    $this->assertCount(1, $this->documentMap()->findByAttachment('0b5c3e5e-1111-4222-8333-944444444444'));

    $this->node->save();
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getDeleted());
    $this->assertSame([['docs', $attachmentId]], ScriptedPlatformClient::callsOf('deleteDocument'));
    $this->assertSame([], $this->documentMap()->findByAttachment('0b5c3e5e-1111-4222-8333-944444444444'));
    $this->assertCount(2, $this->mapRows());
  }

  /**
   * Rejected documents go to the dead letter with their errors.
   */
  public function testRejectedGoesDead(): void {
    ScriptedPlatformClient::$ingestResults[$this->remoteId($this->node, 'en')] = [
      'status' => 'rejected',
      'operation' => 'invalid',
      'document_id' => NULL,
      'errors' => [['path' => '/title', 'message' => 'Too long']],
    ];
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getDead());
    $this->assertSame(1, $report->getUpserted());
    $this->assertFalse($report->isSuspended());

    $rows = $this->outboxRows();
    $english = $rows[$this->key($this->node, 'en')];
    $this->assertSame(OutboxItem::STATUS_DEAD, $english->status);
    $this->assertStringContainsString('Rejected by the platform', (string) $english->lastError);
    $this->assertStringContainsString('/title Too long', (string) $english->lastError);
    $this->assertSame([['path' => '/title', 'message' => 'Too long']], $english->remote['rejected'][0]['errors']);
    $this->assertSame(OutboxItem::STATUS_DONE, $rows[$this->key($this->node, 'es')]->status);
    $this->assertSame([$this->remoteId($this->node, 'es')], array_keys($this->mapRows()));
  }

  /**
   * Retryable errors back off, honour Retry-After and stop the run.
   */
  public function testRetryableErrorsBackOffAndSuspend(): void {
    ScriptedPlatformClient::failNext('ingest', new PlatformException('WebAssistant API error 503 (unavailable)', 503, 'unavailable', TRUE, NULL, 'corr-503'));
    $start = time();
    $report = $this->processor()->process(100, 0);
    $this->assertTrue($report->isSuspended());
    $this->assertSame(1, $report->getFailed());
    $this->assertSame(1, $report->getReleased());
    $this->assertSame('corr-503', $report->getErrors()[0]['correlation_id']);

    $rows = $this->outboxRows();
    $english = $rows[$this->key($this->node, 'en')];
    $this->assertSame(OutboxItem::STATUS_FAILED, $english->status);
    $this->assertSame(1, $english->attempts);
    $this->assertSame('corr-503', $english->correlationId);
    $this->assertGreaterThanOrEqual($start + 30, $english->nextAttempt);
    $this->assertLessThanOrEqual(time() + 30, $english->nextAttempt);
    $spanish = $rows[$this->key($this->node, 'es')];
    $this->assertSame(OutboxItem::STATUS_PENDING, $spanish->status, 'The rest of the claim is released.');
    $this->assertNull($spanish->claimToken);

    // Retry-After wins over the exponential backoff.
    $this->updateOutbox(['next_attempt' => 0]);
    ScriptedPlatformClient::failNext('ingest', new PlatformException('WebAssistant API error 429 (rate_limited)', 429, 'rate_limited', TRUE, 120));
    $report = $this->processor()->process(100, 0);
    $this->assertTrue($report->isSuspended());
    $this->assertSame(120, $report->getSuspendDelay());
    $english = $this->outboxRows()[$this->key($this->node, 'en')];
    $this->assertSame(2, $english->attempts);
    $this->assertGreaterThanOrEqual($start + 120, $english->nextAttempt);
    $this->assertLessThanOrEqual(time() + 120, $english->nextAttempt);

    $this->assertSame(30, SyncProcessor::backoff(0));
    $this->assertSame(240, SyncProcessor::backoff(3));
    $this->assertSame(3600, SyncProcessor::backoff(12));
    $this->assertSame(5, SyncProcessor::backoff(1, 0));
  }

  /**
   * Non-retryable errors and exhausted attempts go to the dead letter.
   */
  public function testNonRetryableAndMaxAttempts(): void {
    $errors = [['path' => '/acl', 'message' => 'Invalid']];
    ScriptedPlatformClient::failNext('ingest', new PlatformException('WebAssistant API error 422 (validation_error)', 422, 'validation_error', FALSE, NULL, 'corr-422', $errors));
    $report = $this->processor()->process(100, 0);
    $this->assertFalse($report->isSuspended(), 'A document error does not stop the run.');
    $this->assertSame(1, $report->getDead());
    $this->assertSame(1, $report->getUpserted());
    $english = $this->outboxRows()[$this->key($this->node, 'en')];
    $this->assertSame(OutboxItem::STATUS_DEAD, $english->status);
    $this->assertSame([['path' => '/acl', 'message' => 'Invalid']], $english->remote['errors']);

    $this->config('webassistant_connector.settings')->set('sync.max_attempts', 3)->save();
    $this->node->setTitle('Again')->save();
    $this->updateOutbox(['attempts' => 2], $this->key($this->node, 'en'));
    ScriptedPlatformClient::failNext('ingest', new PlatformException('Network error', NULL, PlatformException::CODE_NETWORK_ERROR, TRUE));
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getDead());
    $this->assertTrue($report->isSuspended());
    $this->assertSame(OutboxItem::STATUS_DEAD, $this->outboxRows()[$this->key($this->node, 'en')]->status);
  }

  /**
   * A 401 or missing credentials never kill events.
   */
  public function testGlobalErrorsKeepEventsAlive(): void {
    // Even with a single attempt allowed, a 401 does not kill the event nor
    // count as an attempt.
    $this->config('webassistant_connector.settings')->set('sync.max_attempts', 1)->save();
    ScriptedPlatformClient::failNext('ingest', new PlatformException('WebAssistant API error 401 (unauthorized)', 401, 'unauthorized', FALSE, NULL, 'corr-401'));
    $report = $this->processor()->process(100, 0);
    $this->assertTrue($report->isSuspended());
    $this->assertSame(1, $report->getFailed());
    $this->assertSame(0, $report->getDead());
    $english = $this->outboxRows()[$this->key($this->node, 'en')];
    $this->assertSame(OutboxItem::STATUS_FAILED, $english->status);
    $this->assertSame(0, $english->attempts);
    $this->assertSame('corr-401', $english->correlationId);
    $this->assertGreaterThan(time(), $english->nextAttempt);

    putenv('WEBASSISTANT_CLIENT_SECRET');
    $this->updateOutbox(['next_attempt' => 0]);
    $calls = count(ScriptedPlatformClient::$calls);
    $report = $this->processor()->process(100, 0);
    $this->assertTrue($report->isSuspended());
    $this->assertSame(0, $report->getProcessed());
    $this->assertCount($calls, ScriptedPlatformClient::$calls, 'Nothing is sent without credentials.');
  }

  /**
   * Disabled synchronization records but does not process.
   */
  public function testDisabledProcessing(): void {
    $this->config('webassistant_connector.settings')->set('sync.enabled', FALSE)->save();
    $this->assertFalse($this->processor()->isEnabled());
    $this->assertSame(0, $this->processor()->process(100, 0)->getProcessed());
    $this->assertSame(2, $this->container->get('webassistant_connector.outbox')->countDue());
  }

  /**
   * Orphans are deleted unless a source produces them; foreign IDs never.
   */
  public function testOrphans(): void {
    $this->processor()->process(100, 0);
    $outbox = $this->container->get('webassistant_connector.outbox');
    $english = $this->remoteId($this->node, 'en');
    // The map lost the English row: the orphan check finds its producer.
    $this->documentMap()->delete('help', $english);
    $outbox->recordOrphan('help', $english);
    $outbox->recordOrphan('help', self::PREFIX . ':node:999:en');
    $outbox->recordOrphan('help', 'another-site:node:1:en');
    $report = $this->processor()->process(100, 0);
    $this->assertSame([['help', self::PREFIX . ':node:999:en']], ScriptedPlatformClient::callsOf('deleteDocument'));
    $this->assertSame(1, $report->getDeleted());
    // The producer was recorded instead and processed in the same run.
    $this->assertSame(2, $this->outboxRows()[$this->key($this->node, 'en')]->seq);
    $this->assertArrayHasKey($english, $this->mapRows(), 'The document is mapped again.');
    $orphans = array_filter($this->outboxRows(), static fn (OutboxItem $item): bool => $item->source === Outbox::SOURCE_ORPHAN);
    $this->assertSame([
      ['recorded' => 'articles'],
      ['deleted' => 1, 'knowledge_base' => 'help', 'remote_id' => self::PREFIX . ':node:999:en'],
      ['skipped' => 'foreign'],
    ], array_values(array_map(static fn (OutboxItem $item): array => $item->remote, $orphans)));
  }

  /**
   * Pseudo-source rows go to their handler.
   */
  public function testPseudoSourceHandlers(): void {
    $outbox = $this->container->get('webassistant_connector.outbox');
    $outbox->recordKey(OutboxItem::OPERATION_UPSERT, '_test', 'file', '5', 'und', 'file-uuid');
    $outbox->recordKey(OutboxItem::OPERATION_UPSERT, '_unknown', 'file', '6', 'und');
    $this->processor()->process(100, 0);
    $this->assertSame(['_test:file:5:und'], TestOutboxHandler::$processed);
    $rows = $this->outboxRows();
    $this->assertSame(['handled' => TRUE], $rows['_test:file:5:und']->remote);
    $this->assertSame(OutboxItem::STATUS_DONE, $rows['_unknown:file:6:und']->status);
    $this->assertSame(['skipped' => 'no_handler'], $rows['_unknown:file:6:und']->remote);
  }

  /**
   * The dispatch worker requeues while events are due and suspends outages.
   */
  public function testDispatchWorker(): void {
    $this->config('webassistant_connector.settings')->set('sync.process_limit', 1)->save();
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(Outbox::DISPATCH_QUEUE);
    try {
      $worker->processItem([]);
      $this->fail('Due events remain: the ticket must be requeued.');
    }
    catch (RequeueException) {
      // Expected.
    }
    $worker->processItem([]);
    $this->assertSame(0, $this->container->get('webassistant_connector.outbox')->countDue());

    $this->node->setTitle('Changed')->save();
    ScriptedPlatformClient::failNext('ingest', new PlatformException('WebAssistant API error 502', 502, 'http_502', TRUE));
    $this->expectException(SuspendQueueException::class);
    $worker->processItem([]);
  }

  /**
   * Disabling a source deletes its documents through the index queue.
   */
  public function testDisabledSourceDeletesDocuments(): void {
    $this->processor()->process(100, 0);
    $this->clearQueues();
    $this->source->disable()->save();
    $this->assertSame(1, $this->queueCount(Indexer::INDEX_QUEUE), 'Only the map refresh is scheduled for a disabled source.');
    $this->runQueue(Indexer::INDEX_QUEUE);
    $report = $this->processor()->process(100, 0);
    $this->assertSame(2, $report->getDeleted());
    $this->assertSame([], $this->mapRows());

    // Enabling it again reindexes every covered entity.
    $this->source->enable()->save();
    $this->runQueue(Indexer::INDEX_QUEUE);
    $report = $this->processor()->process(100, 0);
    $this->assertSame(2, $report->getUpserted());
    $this->assertCount(2, $this->mapRows());
  }

  /**
   * Narrowing or deleting a source deletes what falls out of scope (A9).
   */
  public function testNarrowedAndDeletedSource(): void {
    $this->processor()->process(100, 0);
    $this->assertCount(2, $this->mapRows());
    $this->clearQueues();

    // A label change does not touch the documents.
    $this->source->set('label', 'Renamed')->save();
    $this->assertSame(0, $this->queueCount(Indexer::INDEX_QUEUE));

    // Only Spanish from now on: the English document is deleted.
    $this->source->set('langcodes', ['es'])->save();
    $this->assertSame(2, $this->queueCount(Indexer::INDEX_QUEUE), 'Entities and mapped documents are refreshed.');
    $this->runQueue(Indexer::INDEX_QUEUE);
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getDeleted());
    $this->assertSame(1, $report->getUpserted(), 'The Spanish document carries the new mapping hash.');
    $this->assertSame([['help', $this->remoteId($this->node, 'en')]], ScriptedPlatformClient::callsOf('deleteDocument'));
    $this->assertSame([$this->remoteId($this->node, 'es')], array_keys($this->mapRows()));

    // A newer job of the same source supersedes the pending one.
    $this->source->set('langcodes', [])->save();
    $this->source->set('langcodes', ['es'])->save();
    $this->assertSame(4, $this->queueCount(Indexer::INDEX_QUEUE));
    $this->runQueue(Indexer::INDEX_QUEUE);
    $recorded = array_filter($this->outboxRows(), static fn (OutboxItem $item): bool => $item->status === OutboxItem::STATUS_PENDING);
    $this->assertSame([$this->key($this->node, 'es')], array_keys($recorded), 'Only the latest jobs ran.');
    $this->processor()->process(100, 0);

    // Deleting the source deletes its remaining documents.
    $this->source->delete();
    $this->runQueue(Indexer::INDEX_QUEUE);
    $report = $this->processor()->process(100, 0);
    $this->assertSame(1, $report->getDeleted());
    $this->assertSame([], $this->mapRows());
    $this->assertSame('not_applicable', $this->outboxRows()[$this->key($this->node, 'es')]->remote['skip_reason']);
  }

  /**
   * Purge deletes every remote document and clears the map and the outbox.
   */
  public function testPurge(): void {
    $this->processor()->process(100, 0);
    $this->node->setTitle('Pending change')->save();
    $result = $this->processor()->purgeRemote();
    $this->assertSame(['deleted' => 2, 'failed' => 0, 'errors' => []], $result);
    $this->assertSame([], $this->mapRows());
    $this->assertSame([], $this->outboxRows());
    $this->assertCount(2, ScriptedPlatformClient::callsOf('deleteDocument'));
  }

  /**
   * Processes every item of a queue.
   *
   * @param string $name
   *   The queue name.
   */
  private function runQueue(string $name): void {
    $queue = $this->container->get('queue')->get($name);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance($name);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

}
