<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

/**
 * One row of webassistant_connector_outbox (design section 9.1).
 */
final class OutboxItem {

  /**
   * Operation: build and send the documents of a translation.
   */
  public const OPERATION_UPSERT = 'upsert';

  /**
   * Operation: delete the mapped documents of a translation.
   */
  public const OPERATION_DELETE = 'delete';

  /**
   * Waiting to be processed.
   */
  public const STATUS_PENDING = 'pending';

  /**
   * Claimed by a worker.
   */
  public const STATUS_PROCESSING = 'processing';

  /**
   * Delivered.
   */
  public const STATUS_DONE = 'done';

  /**
   * Failed with a retryable error; retried at next_attempt.
   */
  public const STATUS_FAILED = 'failed';

  /**
   * Failed permanently (dead letter).
   */
  public const STATUS_DEAD = 'dead';

  /**
   * All statuses.
   */
  public const STATUSES = [
    self::STATUS_PENDING,
    self::STATUS_PROCESSING,
    self::STATUS_DONE,
    self::STATUS_FAILED,
    self::STATUS_DEAD,
  ];

  /**
   * Constructs an OutboxItem.
   *
   * @param int $id
   *   Serial ID (0 for an item not stored yet).
   * @param string $eventId
   *   Event UUID.
   * @param string $operation
   *   One of the OPERATION_* constants.
   * @param string $source
   *   Source config entity ID.
   * @param string $entityType
   *   Entity type ID.
   * @param string $entityId
   *   Entity ID.
   * @param string $entityUuid
   *   Entity UUID.
   * @param string $langcode
   *   Language code of the translation.
   * @param string|null $revisionId
   *   Revision ID at capture time (informative).
   * @param string $status
   *   One of the STATUS_* constants.
   * @param int $attempts
   *   Delivery attempts so far.
   * @param int $nextAttempt
   *   Timestamp of the next attempt.
   * @param string|null $lastError
   *   Last error (no secrets, at most 1024 characters).
   * @param string|null $correlationId
   *   X-Request-Id of the last platform request.
   * @param array<string, mixed> $remote
   *   Summarized remote result.
   * @param int $created
   *   Creation timestamp.
   * @param int $changed
   *   Last change timestamp.
   */
  public function __construct(
    public readonly int $id,
    public readonly string $eventId,
    public readonly string $operation,
    public readonly string $source,
    public readonly string $entityType,
    public readonly string $entityId,
    public readonly string $entityUuid,
    public readonly string $langcode,
    public readonly ?string $revisionId,
    public readonly string $status,
    public readonly int $attempts,
    public readonly int $nextAttempt,
    public readonly ?string $lastError,
    public readonly ?string $correlationId,
    public readonly array $remote,
    public readonly int $created,
    public readonly int $changed,
  ) {}

  /**
   * Builds an item from a database row.
   *
   * @param array<string, mixed>|object $row
   *   The row (array or stdClass) with the table column names.
   *
   * @return self
   *   The item.
   */
  public static function fromRow(array|object $row): self {
    $row = (array) $row;
    $remote = [];
    if (isset($row['remote']) && is_string($row['remote']) && $row['remote'] !== '') {
      $decoded = json_decode($row['remote'], TRUE);
      $remote = is_array($decoded) ? $decoded : [];
    }
    $nullable = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : NULL;
    return new self(
      id: (int) ($row['id'] ?? 0),
      eventId: (string) ($row['event_id'] ?? ''),
      operation: (string) ($row['operation'] ?? self::OPERATION_UPSERT),
      source: (string) ($row['source'] ?? ''),
      entityType: (string) ($row['entity_type'] ?? ''),
      entityId: (string) ($row['entity_id'] ?? ''),
      entityUuid: (string) ($row['entity_uuid'] ?? ''),
      langcode: (string) ($row['langcode'] ?? ''),
      revisionId: $nullable('revision_id'),
      status: (string) ($row['status'] ?? self::STATUS_PENDING),
      attempts: (int) ($row['attempts'] ?? 0),
      nextAttempt: (int) ($row['next_attempt'] ?? 0),
      lastError: $nullable('last_error'),
      correlationId: $nullable('correlation_id'),
      remote: $remote,
      created: (int) ($row['created'] ?? 0),
      changed: (int) ($row['changed'] ?? 0),
    );
  }

  /**
   * Returns the row for the database (without the serial ID when 0).
   *
   * @return array<string, mixed>
   *   Column values; remote is JSON-encoded (NULL when empty).
   */
  public function toRow(): array {
    $row = [
      'id' => $this->id,
      'event_id' => $this->eventId,
      'operation' => $this->operation,
      'source' => $this->source,
      'entity_type' => $this->entityType,
      'entity_id' => $this->entityId,
      'entity_uuid' => $this->entityUuid,
      'langcode' => $this->langcode,
      'revision_id' => $this->revisionId,
      'status' => $this->status,
      'attempts' => $this->attempts,
      'next_attempt' => $this->nextAttempt,
      'last_error' => $this->lastError === NULL ? NULL : mb_substr($this->lastError, 0, 1024),
      'correlation_id' => $this->correlationId,
      'remote' => $this->remote === [] ? NULL : json_encode($this->remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
      'created' => $this->created,
      'changed' => $this->changed,
    ];
    if ($this->id === 0) {
      unset($row['id']);
    }
    return $row;
  }

  /**
   * Whether the item is an upsert.
   */
  public function isUpsert(): bool {
    return $this->operation === self::OPERATION_UPSERT;
  }

  /**
   * Whether the item is a delete.
   */
  public function isDelete(): bool {
    return $this->operation === self::OPERATION_DELETE;
  }

  /**
   * Returns the coalescing key: source, entity type, entity ID, langcode.
   */
  public function getKey(): string {
    return implode(':', [$this->source, $this->entityType, $this->entityId, $this->langcode]);
  }

  /**
   * Returns the delivery latency in seconds (changed - created).
   */
  public function getLatency(): int {
    return max(0, $this->changed - $this->created);
  }

}
