<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\SyncPipeline;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\webassistant_connector\Hook\AccessEpochHooks;
use Drupal\webassistant_connector\Hook\RequirementsHooks;
use Drupal\webassistant_connector\Hook\SyncCronHooks;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Plugin\QueueWorker\ReconcileWorker;
use Drupal\webassistant_connector\Sync\AccessEpoch;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SyncStatus;
use Drupal\webassistant_connector_sync_test\ScriptedPlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests reconciliation, cron, the access epoch and the requirements.
 *
 * @group webassistant_connector
 */
#[CoversClass(Reconciler::class)]
#[CoversClass(ReconcileWorker::class)]
#[CoversClass(SyncCronHooks::class)]
#[CoversClass(AccessEpoch::class)]
#[CoversClass(AccessEpochHooks::class)]
#[CoversClass(RequirementsHooks::class)]
#[CoversClass(SyncStatus::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class ReconcilerTest extends SyncKernelTestBase {

  /**
   * The synchronized article (English and Spanish).
   */
  private NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->node = $this->createArticle();
    $this->container->get('webassistant_connector.sync_processor')->process(100, 0);
    $this->assertCount(2, $this->mapRows());
    // Documents synchronized during a reconciliation are never "missing":
    // date the synchronization before the runs of the tests.
    $this->container->get('database')->update(DocumentMap::TABLE)->fields(['synced' => time() - 60])->execute();
    ScriptedPlatformClient::$calls = [];
  }

  /**
   * Returns the reconciler.
   */
  private function reconciler(): Reconciler {
    return $this->container->get('webassistant_connector.reconciler');
  }

  /**
   * Builds an inventory item from a map row.
   *
   * @param string $remoteId
   *   The remote ID.
   * @param array<string, mixed> $overrides
   *   Values that differ from the map.
   *
   * @return array<string, mixed>
   *   The item.
   */
  private function inventoryItem(string $remoteId, array $overrides = []): array {
    $row = $this->mapRows()[$remoteId] ?? ['version' => 'm1.0000', 'content_hash' => '', 'acl' => ['public']];
    return $overrides + [
      'source_id' => $remoteId,
      'version' => $row['version'],
      'content_hash' => $row['content_hash'],
      'status' => 'ready',
      'acl' => array_reverse($row['acl']),
    ];
  }

  /**
   * Orphans, missing documents and foreign prefixes, in dry run and for real.
   */
  public function testOrphansMissingAndForeignPrefixes(): void {
    $english = $this->remoteId($this->node, 'en');
    $spanish = $this->remoteId($this->node, 'es');
    $orphan = self::PREFIX . ':node:999:en';
    ScriptedPlatformClient::$inventory['help'] = [
      '' => ['items' => [$this->inventoryItem($english), $this->inventoryItem($orphan)], 'next_cursor' => $orphan],
      $orphan => ['items' => [$this->inventoryItem('another-site:node:1:en')], 'next_cursor' => NULL],
    ];

    $report = $this->reconciler()->reconcile('help', TRUE);
    $this->assertTrue($report['dry_run']);
    $this->assertTrue($report['finished']);
    $this->assertSame(2, $report['remote'], 'Foreign documents are not counted.');
    $this->assertSame(2, $report['local']);
    $this->assertSame([$orphan], $report['orphans']);
    $this->assertSame([], $report['outdated']);
    $this->assertSame([$spanish], $report['missing']);
    $this->assertSame(0, $report['recorded']);
    $this->assertSame([], array_filter($this->outboxRows(), static fn (OutboxItem $item): bool => $item->status !== OutboxItem::STATUS_DONE), 'A dry run records nothing.');

    $report = $this->reconciler()->reconcile('help');
    $this->assertSame(2, $report['recorded']);
    $this->assertSame(1, $report['orphan_count']);
    $this->assertSame(1, $report['missing_count']);
    $rows = $this->outboxRows();
    $this->assertSame(OutboxItem::STATUS_PENDING, $rows[$this->key($this->node, 'es')]->status);
    $this->assertSame(OutboxItem::STATUS_DONE, $rows[$this->key($this->node, 'en')]->status);
    $this->assertSame(DocumentMap::STATUS_STALE, $this->mapRows()[$spanish]['remote_status']);
    $this->assertSame([['help', NULL, Reconciler::PAGE_SIZE], ['help', $orphan, Reconciler::PAGE_SIZE]], array_slice(ScriptedPlatformClient::callsOf('inventory'), 2));
    $this->assertArrayHasKey('help', $this->reconciler()->lastRuns());

    // The worker deletes the orphan and sends the stale document again.
    $this->container->get('webassistant_connector.sync_processor')->process(100, 0);
    $this->assertSame([['help', $orphan]], ScriptedPlatformClient::callsOf('deleteDocument'));
    $this->assertSame([$spanish], array_column(ScriptedPlatformClient::ingestedDocuments(), 'source_id'));
    $this->assertSame('queued', $this->mapRows()[$spanish]['remote_status']);
  }

  /**
   * Different versions or ACLs and failed documents are sent again.
   */
  public function testOutdatedDocuments(): void {
    $english = $this->remoteId($this->node, 'en');
    $spanish = $this->remoteId($this->node, 'es');
    ScriptedPlatformClient::$inventory['help'][''] = [
      'items' => [
        $this->inventoryItem($english, ['version' => 'm1.0000000000000000']),
        $this->inventoryItem($spanish, ['status' => 'failed']),
      ],
      'next_cursor' => NULL,
    ];
    $report = $this->reconciler()->reconcile('help');
    $this->assertSame([$english, $spanish], $report['outdated']);
    $this->assertSame([], $report['missing']);
    $this->container->get('webassistant_connector.sync_processor')->process(100, 0);
    $this->assertSame([$english, $spanish], array_column(ScriptedPlatformClient::ingestedDocuments(), 'source_id'));

    // ACL differences count too.
    ScriptedPlatformClient::$inventory['help']['']['items'] = [
      $this->inventoryItem($english, ['acl' => ['authenticated']]),
      $this->inventoryItem($spanish),
    ];
    $report = $this->reconciler()->reconcile('help');
    $this->assertSame([$english], $report['outdated']);
  }

  /**
   * Queued reconciliations resume from their cursor after an error.
   */
  public function testResumableStepAndWorker(): void {
    $english = $this->remoteId($this->node, 'en');
    $spanish = $this->remoteId($this->node, 'es');
    ScriptedPlatformClient::$inventory['help'] = [
      '' => ['items' => [$this->inventoryItem($english)], 'next_cursor' => $english],
      $english => new PlatformException('WebAssistant API error 503', 503, 'unavailable', TRUE),
    ];
    try {
      $this->reconciler()->step('help', 60);
      $this->fail('The second page fails.');
    }
    catch (PlatformException $e) {
      $this->assertTrue($e->isRetryable());
    }
    $run = $this->container->get('state')->get(Reconciler::STATE_RUN_PREFIX . 'help');
    $this->assertSame($english, $run['cursor'], 'The cursor is kept for the next attempt.');
    $this->assertSame(1, $run['remote']);
    $this->assertSame([], array_filter($this->outboxRows(), static fn (OutboxItem $item): bool => $item->status !== OutboxItem::STATUS_DONE), 'Nothing is recorded on errors.');

    ScriptedPlatformClient::$inventory['help'][$english] = [
      'items' => [$this->inventoryItem($spanish)],
      'next_cursor' => NULL,
    ];
    $this->config('webassistant_connector.settings')->set('sync.reconcile_interval', 3600)->save();
    $this->assertSame(1, $this->reconciler()->enqueueDue());
    $this->assertSame(0, $this->reconciler()->enqueueDue(), 'Not due again within the interval.');
    $queue = $this->container->get('queue')->get(Reconciler::RECONCILE_QUEUE);
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertIsObject($item);
    $this->container->get('plugin.manager.queue_worker')->createInstance(Reconciler::RECONCILE_QUEUE)->processItem($item->data);
    $this->assertSame([
      ['help', NULL, Reconciler::PAGE_SIZE],
      ['help', $english, Reconciler::PAGE_SIZE],
      ['help', $english, Reconciler::PAGE_SIZE],
    ], ScriptedPlatformClient::callsOf('inventory'));
    $this->assertNull($this->container->get('state')->get(Reconciler::STATE_RUN_PREFIX . 'help'));
    $this->assertSame([], array_filter($this->outboxRows(), static fn (OutboxItem $item): bool => $item->status !== OutboxItem::STATUS_DONE), 'Everything was in sync.');

    // A step limited to one page is not finished; the next one resumes.
    $this->assertSame(['help'], $this->reconciler()->knowledgeBases());
    ScriptedPlatformClient::$calls = [];
    $result = $this->reconciler()->step('help', 0, 1);
    $this->assertFalse($result['finished']);
    $this->assertSame(1, $result['report']['remote']);
    $result = $this->reconciler()->step('help', 0);
    $this->assertTrue($result['finished']);
    $this->assertSame(2, $result['report']['remote']);
    $this->assertSame([['help', NULL, Reconciler::PAGE_SIZE], ['help', $english, Reconciler::PAGE_SIZE]], ScriptedPlatformClient::callsOf('inventory'));

    // Non-retryable errors discard the run; the worker logs and finishes.
    ScriptedPlatformClient::$inventory['help'][''] = new PlatformException('WebAssistant API error 404 (not_found)', 404, 'not_found');
    $this->container->get('plugin.manager.queue_worker')->createInstance(Reconciler::RECONCILE_QUEUE)->processItem(['knowledge_base' => 'help']);
    $this->assertNull($this->container->get('state')->get(Reconciler::STATE_RUN_PREFIX . 'help'));

    // Retryable errors delay the queue item.
    ScriptedPlatformClient::$inventory['help'][''] = new PlatformException('WebAssistant API error 503', 503, 'unavailable', TRUE, 60);
    $this->expectException(DelayedRequeueException::class);
    $this->container->get('plugin.manager.queue_worker')->createInstance(Reconciler::RECONCILE_QUEUE)->processItem(['knowledge_base' => 'help']);
  }

  /**
   * Cron purges, adds tickets, schedules reconciliations and checks access.
   */
  public function testCronAndAccessEpoch(): void {
    $this->config('webassistant_connector.settings')
      ->set('sync.retention_days', 7)
      ->set('sync.reconcile_interval', 3600)
      ->save();
    $this->updateOutbox(['changed' => time() - 8 * 86400], $this->key($this->node, 'en'));
    $this->node->setTitle('Updated')->save();
    $this->clearQueues();

    $cron = $this->container->get(SyncCronHooks::class);
    $cron->cron();
    $this->assertSame(1, $this->queueCount(Outbox::DISPATCH_QUEUE), 'Due rows get a ticket.');
    $this->assertSame(1, $this->queueCount(Reconciler::RECONCILE_QUEUE));
    $this->assertCount(2, $this->outboxRows(), 'Rows captured again are not purged.');
    $this->assertNotNull($this->container->get('state')->get(AccessEpoch::STATE_KEY));

    $this->container->get('webassistant_connector.sync_processor')->process(100, 0);
    $this->updateOutbox(['changed' => time() - 8 * 86400], $this->key($this->node, 'es'));
    $cron->cron();
    $this->assertSame([$this->key($this->node, 'en')], array_keys($this->outboxRows()));

    // Permission changes reindex only the sources with computed ACLs.
    $this->assertSame(0, $this->queueCount(Indexer::INDEX_QUEUE));
    Role::load('anonymous')->revokePermission('access content')->save();
    $this->assertSame(0, $this->queueCount(Indexer::INDEX_QUEUE), 'The articles source has a fixed ACL.');
    $this->source->set('acl_mode', 'access')->save();
    $this->clearQueues();
    Role::load('anonymous')->grantPermission('access content')->save();
    $this->assertSame(1, $this->queueCount(Indexer::INDEX_QUEUE));
    $this->assertFalse($this->container->get('webassistant_connector.access_epoch')->check(), 'Nothing changed since.');
  }

  /**
   * The status report shows credentials, dead events and the backlog.
   */
  public function testRequirementsAndStatus(): void {
    $requirements = $this->container->get(RequirementsHooks::class)->runtimeRequirements();
    $this->assertSame('OK', $requirements['webassistant_connector_credentials']['severity']->name);
    $this->assertArrayNotHasKey('webassistant_connector_https', $requirements, 'http://localhost needs no https.');
    $this->assertArrayHasKey('webassistant_connector_outbox', $requirements);

    $this->updateOutbox(['status' => OutboxItem::STATUS_DEAD], $this->key($this->node, 'en'));
    $this->updateOutbox(['status' => OutboxItem::STATUS_PENDING, 'created' => time() - 7200], $this->key($this->node, 'es'));
    putenv('WEBASSISTANT_CLIENT_SECRET');
    $this->config('webassistant_connector.settings')->set('api.base_url', 'http://api.example.com')->save();
    $requirements = $this->container->get(RequirementsHooks::class)->runtimeRequirements();
    $this->assertSame('Error', $requirements['webassistant_connector_credentials']['severity']->name);
    $this->assertStringContainsString('client_secret', (string) $requirements['webassistant_connector_credentials']['value']);
    $this->assertSame('Warning', $requirements['webassistant_connector_https']['severity']->name);
    $this->assertSame('Warning', $requirements['webassistant_connector_dead']['severity']->name);
    $this->assertSame('Warning', $requirements['webassistant_connector_backlog']['severity']->name);

    $summary = $this->container->get('webassistant_connector.sync_status')->summary();
    $this->assertSame(1, $summary['counts'][OutboxItem::STATUS_DEAD]);
    $this->assertSame(1, $summary['backlog']);
    $this->assertSame(2, $summary['documents']);
    $this->assertSame($this->key($this->node, 'en'), implode(':', [
      $summary['recent_errors'][0]['source'],
      $summary['recent_errors'][0]['entity_type'],
      $summary['recent_errors'][0]['entity_id'],
      $summary['recent_errors'][0]['langcode'],
    ]));
  }

}
