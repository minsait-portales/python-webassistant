<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reconciles the remote inventory with the map (9.4, amendment A11).
 *
 * Walks the remote inventory of a knowledge base page by page (cursor) and
 * only considers source IDs with the own prefix (documents of other
 * installations are never touched):
 * - remote documents absent from the map: an orphan row is recorded; the
 *   worker deletes the document unless an enabled source produces it;
 * - remote version or ACL different from the map, or remote status failed:
 *   the map row is marked stale and an event is recorded (resend);
 * - map rows absent remotely: marked stale and an event is recorded.
 * The only HTTP request is the inventory read: every change goes through
 * the outbox, and nothing is changed when a request fails. Runs are
 * resumable (cursor in state), so the queue worker processes a large
 * knowledge base over several runs; "drush webassistant:reconcile" runs it
 * at once and is recommended from system cron on big sites.
 *
 * Service: webassistant_connector.reconciler.
 */
final class Reconciler {

  /**
   * The queue that runs reconciliations (one item per knowledge base).
   */
  public const RECONCILE_QUEUE = 'webassistant_connector_reconcile';

  /**
   * State key: last reconciliation timestamp keyed by knowledge base.
   */
  public const STATE_LAST_RUN = 'webassistant_connector.reconcile_last_run';

  /**
   * State key prefix of a resumable run (suffix: knowledge base).
   */
  public const STATE_RUN_PREFIX = 'webassistant_connector.reconcile_run.';

  /**
   * Inventory and map page size.
   */
  public const PAGE_SIZE = 500;

  /**
   * Maximum source IDs listed per category in a report.
   */
  public const MAX_REPORT_IDS = 100;

  /**
   * Remote statuses that make a document outdated.
   */
  private const STALE_REMOTE_STATUSES = ['failed'];

  /**
   * Constructs a Reconciler.
   *
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend (one run per knowledge base at a time).
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly PlatformClientInterface $platformClient,
    private readonly DocumentMap $documentMap,
    private readonly Outbox $outbox,
    private readonly SourceMatcher $sourceMatcher,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reconciles one knowledge base at once.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param bool $dryRun
   *   Report only, record nothing.
   *
   * @return array{knowledge_base: string, dry_run: bool, remote: int, local: int, orphans: list<string>, outdated: list<string>, missing: list<string>, recorded: int, errors: list<string>, orphan_count: int, outdated_count: int, missing_count: int, finished: bool}
   *   The report: counts of own remote documents and local map rows, source
   *   IDs found in each category (at most MAX_REPORT_IDS each, the *_count
   *   keys hold the totals), events recorded (0 in dry run) and errors.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the inventory cannot be read (nothing is recorded for the pages
   *   not read; recorded events are harmless).
   */
  public function reconcile(string $knowledgeBase, bool $dryRun = FALSE): array {
    $run = $this->newRun($knowledgeBase, $dryRun);
    $lockName = $this->lockName($knowledgeBase);
    if (!$dryRun && !$this->lock->acquire($lockName, 3600.0)) {
      $run['errors'][] = 'A reconciliation of this knowledge base is already running.';
      return $this->report($run, FALSE);
    }
    try {
      while (!$this->walkPage($run)) {
        // Keep walking until the inventory and the map are exhausted.
      }
    }
    finally {
      if (!$dryRun) {
        $this->lock->release($lockName);
      }
    }
    if (!$dryRun) {
      $this->finishRun($run);
    }
    return $this->report($run, TRUE);
  }

  /**
   * Runs part of a resumable reconciliation (queue worker).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param int $timeBudget
   *   Seconds to spend (0: until finished).
   * @param int $maxPages
   *   (optional) Maximum pages (of the inventory or the map) to walk in this
   *   step; 0 for no limit.
   *
   * @return array{finished: bool, report: array<string, mixed>}
   *   Whether the run finished and its report so far.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the inventory cannot be read: retryable errors keep the cursor
   *   for the next attempt, other errors discard the run.
   */
  public function step(string $knowledgeBase, int $timeBudget, int $maxPages = 0): array {
    $key = self::STATE_RUN_PREFIX . $knowledgeBase;
    $run = $this->state->get($key);
    if (!is_array($run) || ($run['knowledge_base'] ?? NULL) !== $knowledgeBase || !empty($run['dry_run'])) {
      $run = $this->newRun($knowledgeBase, FALSE);
    }
    $lockName = $this->lockName($knowledgeBase);
    if (!$this->lock->acquire($lockName, (float) max(60, $timeBudget + 60))) {
      $run['errors'][] = 'A reconciliation of this knowledge base is already running.';
      return ['finished' => TRUE, 'report' => $this->report($run, FALSE)];
    }
    $start = microtime(TRUE);
    $pages = 0;
    try {
      do {
        $finished = $this->walkPage($run);
        $pages++;
      } while (!$finished && ($timeBudget <= 0 || microtime(TRUE) - $start < $timeBudget) && ($maxPages <= 0 || $pages < $maxPages));
    }
    catch (PlatformException $e) {
      if ($e->isRetryable()) {
        $this->state->set($key, $run);
      }
      else {
        $this->state->delete($key);
      }
      throw $e;
    }
    finally {
      $this->lock->release($lockName);
    }
    if (!$finished) {
      $this->state->set($key, $run);
      return ['finished' => FALSE, 'report' => $this->report($run, FALSE)];
    }
    $this->state->delete($key);
    $this->finishRun($run);
    return ['finished' => TRUE, 'report' => $this->report($run, TRUE)];
  }

  /**
   * Enqueues the knowledge bases whose reconcile_interval has elapsed.
   *
   * Called from cron; does nothing when sync.reconcile_interval is 0.
   *
   * @return int
   *   Number of knowledge bases enqueued in RECONCILE_QUEUE.
   */
  public function enqueueDue(): int {
    $interval = (int) $this->configFactory->get('webassistant_connector.settings')->get('sync.reconcile_interval');
    if ($interval <= 0) {
      return 0;
    }
    $now = $this->time->getCurrentTime();
    $last = $this->state->get(self::STATE_LAST_RUN, []);
    $last = is_array($last) ? $last : [];
    $count = 0;
    foreach ($this->knowledgeBases() as $knowledgeBase) {
      if ($now - (int) ($last[$knowledgeBase] ?? 0) < $interval) {
        continue;
      }
      $this->enqueue($knowledgeBase);
      $last[$knowledgeBase] = $now;
      $count++;
    }
    if ($count > 0) {
      $this->state->set(self::STATE_LAST_RUN, $last);
    }
    return $count;
  }

  /**
   * Adds a reconciliation of a knowledge base to the queue.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   */
  public function enqueue(string $knowledgeBase): void {
    $this->queueFactory->get(self::RECONCILE_QUEUE)->createItem(['knowledge_base' => $knowledgeBase]);
  }

  /**
   * Returns the knowledge bases to reconcile.
   *
   * @return list<string>
   *   The distinct knowledge bases of the map and of the enabled sources.
   */
  public function knowledgeBases(): array {
    $keys = array_unique(array_merge($this->documentMap->knowledgeBases(), $this->sourceMatcher->getKnowledgeBases()));
    $keys = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
    sort($keys);
    return $keys;
  }

  /**
   * Returns the timestamp of the last reconciliation of each knowledge base.
   *
   * @return array<string, int>
   *   Timestamps keyed by knowledge base.
   */
  public function lastRuns(): array {
    $last = $this->state->get(self::STATE_LAST_RUN, []);
    return is_array($last) ? array_map('intval', $last) : [];
  }

  /**
   * Processes one page: of the inventory first, then of the map.
   *
   * @param array<string, mixed> $run
   *   The run state, updated in place.
   *
   * @return bool
   *   TRUE when the run is finished.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function walkPage(array &$run): bool {
    $knowledgeBase = (string) $run['knowledge_base'];
    if ($run['phase'] === 'inventory') {
      $page = $this->platformClient->inventory($knowledgeBase, $run['cursor'], self::PAGE_SIZE);
      $this->compareInventoryPage($run, $page['items']);
      $next = $page['next_cursor'];
      if (!is_string($next) || $next === '' || $page['items'] === [] || $next === $run['cursor']) {
        $run['phase'] = 'map';
        $run['cursor'] = NULL;
      }
      else {
        $run['cursor'] = $next;
      }
    }
    else {
      $rows = $run['dry_run']
        ? $this->documentMap->forKnowledgeBase($knowledgeBase, $run['map_cursor'], self::PAGE_SIZE)
        : $this->documentMap->findUnreconciled($knowledgeBase, (int) $run['started'], $run['map_cursor'], self::PAGE_SIZE);
      foreach ($rows as $row) {
        if ($run['dry_run'] && isset($run['seen'][$row['remote_id']])) {
          continue;
        }
        $this->markMissing($run, $row);
      }
      if (count($rows) < self::PAGE_SIZE) {
        return TRUE;
      }
      $run['map_cursor'] = (string) end($rows)['remote_id'];
    }
    return FALSE;
  }

  /**
   * Compares a page of the remote inventory with the map.
   *
   * @param array<string, mixed> $run
   *   The run state, updated in place.
   * @param array<int, mixed> $items
   *   The inventory items.
   */
  private function compareInventoryPage(array &$run, array $items): void {
    $knowledgeBase = (string) $run['knowledge_base'];
    $own = [];
    foreach ($items as $item) {
      $id = is_array($item) && is_string($item['source_id'] ?? NULL) ? $item['source_id'] : '';
      if ($id !== '' && $this->sourceIdGenerator->isOwn($id)) {
        $own[$id] = $item;
      }
    }
    if ($own === []) {
      return;
    }
    $rows = $this->documentMap->loadMultiple($knowledgeBase, array_keys($own));
    $seen = [];
    foreach ($own as $id => $item) {
      $id = (string) $id;
      $run['remote']++;
      $row = $rows[$id] ?? NULL;
      if ($row === NULL) {
        self::addToList($run, 'orphans', $id);
        if (!$run['dry_run']) {
          $this->outbox->recordOrphan($knowledgeBase, $id);
          $run['recorded']++;
        }
        continue;
      }
      $seen[] = $id;
      if ($run['dry_run']) {
        $run['seen'][$id] = TRUE;
      }
      $remoteStatus = is_string($item['status'] ?? NULL) ? $item['status'] : '';
      $remoteVersion = is_scalar($item['version'] ?? NULL) ? (string) $item['version'] : '';
      $remoteAcl = is_array($item['acl'] ?? NULL) ? $item['acl'] : [];
      if (in_array($remoteStatus, self::STALE_REMOTE_STATUSES, TRUE) || $remoteVersion !== $row['version'] || !self::sameAcl($remoteAcl, $row['acl'])) {
        self::addToList($run, 'outdated', $id);
        if (!$run['dry_run']) {
          $this->resend($row);
          $run['recorded']++;
        }
      }
    }
    if (!$run['dry_run'] && $seen !== []) {
      $this->documentMap->markReconciled($knowledgeBase, $seen, (int) $run['started']);
    }
  }

  /**
   * Handles a map row absent remotely.
   *
   * @param array<string, mixed> $run
   *   The run state, updated in place.
   * @param array<string, mixed> $row
   *   The map row.
   */
  private function markMissing(array &$run, array $row): void {
    self::addToList($run, 'missing', (string) $row['remote_id']);
    if (!$run['dry_run']) {
      $this->resend($row);
      $run['recorded']++;
    }
  }

  /**
   * Marks a map row stale and records an event for its translation.
   *
   * @param array<string, mixed> $row
   *   The map row.
   */
  private function resend(array $row): void {
    $this->documentMap->setRemoteStatus((string) $row['knowledge_base'], (string) $row['remote_id'], DocumentMap::STATUS_STALE);
    if ($row['source'] !== '' && $row['entity_type'] !== '' && $row['entity_id'] !== '') {
      $this->outbox->recordKey(OutboxItem::OPERATION_UPSERT, (string) $row['source'], (string) $row['entity_type'], (string) $row['entity_id'], (string) $row['langcode']);
    }
  }

  /**
   * Builds a new run state.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param bool $dryRun
   *   Whether nothing is recorded.
   *
   * @return array<string, mixed>
   *   The run state.
   */
  private function newRun(string $knowledgeBase, bool $dryRun): array {
    return [
      'knowledge_base' => $knowledgeBase,
      'dry_run' => $dryRun,
      'started' => $this->time->getCurrentTime(),
      'phase' => 'inventory',
      'cursor' => NULL,
      'map_cursor' => NULL,
      'remote' => 0,
      'orphans' => [],
      'outdated' => [],
      'missing' => [],
      'orphan_count' => 0,
      'outdated_count' => 0,
      'missing_count' => 0,
      'recorded' => 0,
      'errors' => [],
      'seen' => [],
    ];
  }

  /**
   * Records the end of a run.
   *
   * @param array<string, mixed> $run
   *   The finished run.
   */
  private function finishRun(array $run): void {
    $last = $this->state->get(self::STATE_LAST_RUN, []);
    $last = is_array($last) ? $last : [];
    $last[(string) $run['knowledge_base']] = $this->time->getCurrentTime();
    $this->state->set(self::STATE_LAST_RUN, $last);
    $this->logger->info('Reconciliation of @kb: @remote remote documents, @orphans orphans, @outdated outdated, @missing missing, @recorded events recorded.', [
      '@kb' => $run['knowledge_base'],
      '@remote' => $run['remote'],
      '@orphans' => $run['orphan_count'],
      '@outdated' => $run['outdated_count'],
      '@missing' => $run['missing_count'],
      '@recorded' => $run['recorded'],
    ]);
  }

  /**
   * Builds the report of a run.
   *
   * @param array<string, mixed> $run
   *   The run state.
   * @param bool $finished
   *   Whether the run finished.
   *
   * @return array{knowledge_base: string, dry_run: bool, remote: int, local: int, orphans: list<string>, outdated: list<string>, missing: list<string>, recorded: int, errors: list<string>, orphan_count: int, outdated_count: int, missing_count: int, finished: bool}
   *   The report.
   */
  private function report(array $run, bool $finished): array {
    return [
      'knowledge_base' => (string) $run['knowledge_base'],
      'dry_run' => (bool) $run['dry_run'],
      'remote' => (int) $run['remote'],
      'local' => $this->documentMap->count((string) $run['knowledge_base']),
      'orphans' => array_values($run['orphans']),
      'outdated' => array_values($run['outdated']),
      'missing' => array_values($run['missing']),
      'recorded' => (int) $run['recorded'],
      'errors' => array_values($run['errors']),
      'orphan_count' => (int) $run['orphan_count'],
      'outdated_count' => (int) $run['outdated_count'],
      'missing_count' => (int) $run['missing_count'],
      'finished' => $finished,
    ];
  }

  /**
   * Adds a source ID to a report category.
   *
   * @param array<string, mixed> $run
   *   The run state, updated in place.
   * @param string $category
   *   The category: orphans, outdated or missing.
   * @param string $id
   *   The source ID.
   */
  private static function addToList(array &$run, string $category, string $id): void {
    $counter = match ($category) {
      'orphans' => 'orphan_count',
      'outdated' => 'outdated_count',
      default => 'missing_count',
    };
    $run[$counter]++;
    if (count($run[$category]) < self::MAX_REPORT_IDS) {
      $run[$category][] = $id;
    }
  }

  /**
   * Whether two ACLs hold the same principals.
   *
   * @param array<int, mixed> $remote
   *   The remote ACL.
   * @param array<int, mixed> $local
   *   The ACL in the map.
   *
   * @return bool
   *   TRUE if equal as sets.
   */
  private static function sameAcl(array $remote, array $local): bool {
    $normalize = static function (array $acl): array {
      $acl = array_values(array_unique(array_map('strval', array_filter($acl, 'is_scalar'))));
      sort($acl);
      return $acl;
    };
    return $normalize($remote) === $normalize($local);
  }

  /**
   * Returns the lock name of a knowledge base.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   *
   * @return string
   *   The lock name.
   */
  private function lockName(string $knowledgeBase): string {
    return 'webassistant_connector.reconcile:' . $knowledgeBase;
  }

}
