<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Transactional outbox of synchronization events (design sections 9.1-9.3).
 *
 * Service: webassistant_connector.outbox. Table: TABLE.
 */
final class Outbox {

  /**
   * The outbox table.
   */
  public const TABLE = 'webassistant_connector_outbox';

  /**
   * The queue that processes the outbox.
   */
  public const DISPATCH_QUEUE = 'webassistant_connector_dispatch';

  /**
   * Constructs an Outbox.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuid,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Records an event, coalescing with a pending/failed one of the same key.
   *
   * Key: source, entity type, entity ID, langcode. An existing pending or
   * failed event is updated (operation, revision, attempts = 0,
   * next_attempt = now); otherwise a pending event is inserted. Runs inside
   * the entity transaction. Then enqueueDispatch() is called.
   *
   * @param string $operation
   *   OutboxItem::OPERATION_UPSERT or OutboxItem::OPERATION_DELETE.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param string $langcode
   *   The language code of the affected translation.
   *
   * @return int
   *   The outbox item ID.
   */
  public function record(string $operation, SourceInterface $source, ContentEntityInterface $entity, string $langcode): int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Adds a ticket to the dispatch queue unless one is already waiting.
   */
  public function enqueueDispatch(): void {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Claims due pending/failed items (status becomes processing).
   *
   * @param int $limit
   *   Maximum number of items.
   *
   * @return list<\Drupal\webassistant_connector\Sync\OutboxItem>
   *   The claimed items, oldest first.
   */
  public function claim(int $limit): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Loads an item.
   *
   * @param int $id
   *   The item ID.
   *
   * @return \Drupal\webassistant_connector\Sync\OutboxItem|null
   *   The item, or NULL.
   */
  public function load(int $id): ?OutboxItem {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Marks an item as done.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The item.
   * @param array<string, mixed> $remote
   *   Summarized remote result.
   * @param string|null $correlationId
   *   The X-Request-Id of the last request.
   */
  public function markDone(OutboxItem $item, array $remote = [], ?string $correlationId = NULL): void {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Marks an item as failed (retryable); attempts is incremented.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The item.
   * @param string $error
   *   The error (no secrets).
   * @param int $nextAttempt
   *   Timestamp of the next attempt.
   * @param string|null $correlationId
   *   The X-Request-Id of the failed request.
   */
  public function markFailed(OutboxItem $item, string $error, int $nextAttempt, ?string $correlationId = NULL): void {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Moves an item to the dead letter; attempts is incremented.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The item.
   * @param string $error
   *   The error (no secrets).
   * @param array<string, mixed> $remote
   *   Summarized remote result (e.g. rejection errors).
   * @param string|null $correlationId
   *   The X-Request-Id of the failed request.
   */
  public function markDead(OutboxItem $item, string $error, array $remote = [], ?string $correlationId = NULL): void {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Moves failed/dead items back to pending (attempts = 0, due now).
   *
   * @param list<string> $statuses
   *   Statuses to retry (failed and/or dead).
   * @param int|null $id
   *   Restrict to one item.
   *
   * @return int
   *   Number of items moved.
   */
  public function retry(array $statuses = [OutboxItem::STATUS_FAILED], ?int $id = NULL): int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Moves items stuck in processing back to pending.
   *
   * @param int $olderThan
   *   Timestamp: items whose "changed" is older are released.
   *
   * @return int
   *   Number of items released.
   */
  public function releaseStale(int $olderThan): int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Deletes done items older than a timestamp (sync.retention_days).
   *
   * @param int $before
   *   Timestamp.
   *
   * @return int
   *   Number of items deleted.
   */
  public function purge(int $before): int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Counts items per status.
   *
   * @return array<string, int>
   *   Counts keyed by every OutboxItem::STATUSES value (0 when none).
   */
  public function countByStatus(): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Returns the most recently changed items.
   *
   * @param int $limit
   *   Maximum number of items.
   * @param list<string> $statuses
   *   Restrict to these statuses (empty: all).
   *
   * @return list<\Drupal\webassistant_connector\Sync\OutboxItem>
   *   Items, most recent first.
   */
  public function recent(int $limit = 20, array $statuses = []): array {
    throw new \LogicException('Not implemented yet.');
  }

}
