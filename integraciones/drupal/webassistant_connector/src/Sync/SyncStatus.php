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
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly Outbox $outbox,
    private readonly DocumentMap $documentMap,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns every metric.
   *
   * @return array{counts: array<string, int>, last_success: int|null, average_latency: float|null, backlog: int, recent_errors: list<array<string, mixed>>, documents: int, due: int}
   *   Counts per status, last successful delivery timestamp, average latency
   *   in seconds over 24 h, overdue events, recent errors, mapped documents
   *   and events a worker could claim now.
   */
  public function summary(): array {
    return [
      'counts' => $this->counts(),
      'last_success' => $this->lastSuccess(),
      'average_latency' => $this->averageLatency(),
      'backlog' => $this->backlog(),
      'recent_errors' => $this->recentErrors(),
      'documents' => $this->documentMap->count(),
      'due' => $this->outbox->countDue(),
    ];
  }

  /**
   * Returns the number of events per status.
   *
   * @return array<string, int>
   *   Counts keyed by every OutboxItem::STATUSES value.
   */
  public function counts(): array {
    return $this->outbox->countByStatus();
  }

  /**
   * Returns the timestamp of the last successful delivery, if any.
   */
  public function lastSuccess(): ?int {
    $value = $this->state->get(SyncProcessor::STATE_LAST_SUCCESS);
    return is_numeric($value) ? (int) $value : NULL;
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
    $query = $this->database->select(Outbox::TABLE, 'o')
      ->condition('status', OutboxItem::STATUS_DONE)
      ->condition('changed', $this->time->getCurrentTime() - $window, '>=');
    $query->addExpression('AVG([changed] - [created])', 'latency');
    $query->addExpression('COUNT(*)', 'total');
    $row = $query->execute()->fetchAssoc();
    if (!is_array($row) || (int) ($row['total'] ?? 0) === 0) {
      return NULL;
    }
    return round(max(0.0, (float) $row['latency']), 1);
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
    return (int) $this->database->select(Outbox::TABLE, 'o')
      ->condition('status', [OutboxItem::STATUS_PENDING, OutboxItem::STATUS_FAILED, OutboxItem::STATUS_PROCESSING], 'IN')
      ->condition('created', $this->time->getCurrentTime() - $olderThan, '<')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the most recent failed/dead events.
   *
   * @param int $limit
   *   Maximum number of entries.
   *
   * @return list<array{id: int, source: string, entity_type: string, entity_id: string, langcode: string, operation: string, status: string, attempts: int, last_error: string|null, correlation_id: string|null, changed: int, next_attempt: int}>
   *   The entries, most recent first.
   */
  public function recentErrors(int $limit = 10): array {
    $entries = [];
    foreach ($this->outbox->recent($limit, [OutboxItem::STATUS_FAILED, OutboxItem::STATUS_DEAD]) as $item) {
      $entries[] = [
        'id' => $item->id,
        'source' => $item->source,
        'entity_type' => $item->entityType,
        'entity_id' => $item->entityId,
        'langcode' => $item->langcode,
        'operation' => $item->operation,
        'status' => $item->status,
        'attempts' => $item->attempts,
        'last_error' => $item->lastError,
        'correlation_id' => $item->correlationId,
        'changed' => $item->changed,
        'next_attempt' => $item->nextAttempt,
      ];
    }
    return $entries;
  }

}
