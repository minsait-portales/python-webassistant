<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Synchronization metrics for the status page, requirements and Drush (9.4).
 *
 * Service: webassistant_connector.sync_status.
 */
final class SyncStatus {

  /**
   * Constructs a SyncStatus.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly Outbox $outbox,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns every metric.
   *
   * @return array{counts: array<string, int>, last_success: int|null, average_latency: float|null, backlog: int, recent_errors: list<array<string, mixed>>}
   *   Counts per status, last successful delivery timestamp, average latency
   *   in seconds over 24 h, overdue events and recent errors.
   */
  public function summary(): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Returns the number of events per status.
   *
   * @return array<string, int>
   *   Counts keyed by every OutboxItem::STATUSES value.
   */
  public function counts(): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Returns the timestamp of the last successful delivery, if any.
   */
  public function lastSuccess(): ?int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Returns the average latency (changed - created) of done events.
   *
   * @param int $window
   *   Seconds to look back (default 24 h).
   *
   * @return float|null
   *   Seconds, or NULL when there are no events in the window.
   */
  public function averageLatency(int $window = 86400): ?float {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Counts pending/failed events created more than $olderThan seconds ago.
   *
   * @param int $olderThan
   *   Age in seconds.
   *
   * @return int
   *   The number of overdue events.
   */
  public function backlog(int $olderThan = 3600): int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Returns the most recent failed/dead events.
   *
   * @param int $limit
   *   Maximum number of entries.
   *
   * @return list<array{id: int, source: string, entity_type: string, entity_id: string, langcode: string, operation: string, status: string, attempts: int, last_error: string|null, correlation_id: string|null, changed: int}>
   *   The entries, most recent first.
   */
  public function recentErrors(int $limit = 10): array {
    throw new \LogicException('Not implemented yet.');
  }

}
