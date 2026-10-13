<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * State-based transactional outbox (design 9.1-9.3, amendments A1-A4).
 *
 * One row per source, entity type, entity ID and langcode. A capture merges
 * into the row (status pending, seq + 1, attempts 0, due now) without reading
 * anything else, inside the entity transaction: if the save is rolled back,
 * so is the capture. A dispatch ticket is added to the queue after the
 * transaction commits. Workers claim due rows with a lease and finish them
 * only if no capture happened meanwhile (seq = claimed_seq); otherwise the
 * row goes back to pending and is processed again from the fresh state.
 *
 * Rows whose source starts with "_" are pseudo-sources: SOURCE_ORPHAN
 * (a remote document to delete, recorded by the Reconciler) and rows
 * recorded by submodules (e.g. "_attachment") that an
 * OutboxHandlerInterface service fans out.
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
   * Seconds a claim stays valid (amendment A3).
   */
  public const LEASE = 300;

  /**
   * Pseudo-source of remote documents to delete (amendment A11).
   *
   * Rows: entity_type = knowledge base, entity_id = sha256 of the remote
   * source_id, remote = {knowledge_base, remote_id}.
   */
  public const SOURCE_ORPHAN = '_orphan';

  /**
   * Pseudo-source of attachment changes (amendment A14, Documents submodule).
   */
  public const SOURCE_ATTACHMENT = '_attachment';

  /**
   * Maximum length of an entity ID (column length).
   */
  public const MAX_ENTITY_ID_LENGTH = 128;

  /**
   * Maximum length of the stored error (column length).
   */
  public const MAX_ERROR_LENGTH = 1024;

  /**
   * Whether a dispatch ticket was ensured (or scheduled) in this process.
   *
   * Reset by claim(): once a worker runs in this process, later captures may
   * need a new ticket.
   */
  private bool $dispatchEnsured = FALSE;

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
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuid,
    private readonly QueueFactory $queueFactory,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records a change of an entity translation (amendments A1, A2, E1).
   *
   * Delegates to recordKey(): one merge on the unique key, no other read. The
   * operation is only a hint; the worker decides from the current state.
   *
   * @param string $operation
   *   OutboxItem::OPERATION_UPSERT or OutboxItem::OPERATION_DELETE.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param string $langcode
   *   The language code of the affected translation.
   */
  public function record(string $operation, SourceInterface $source, ContentEntityInterface $entity, string $langcode): void {
    $revisionId = $entity->getRevisionId() !== NULL ? (string) $entity->getRevisionId() : NULL;
    $this->recordKey($operation, (string) $source->id(), $entity->getEntityTypeId(), (string) $entity->id(), $langcode, (string) $entity->uuid(), $revisionId);
  }

  /**
   * Records a change by key: the low-level capture (amendments A2 and E1).
   *
   * Merges on (source, entity_type, entity_id, langcode): status pending
   * (processing rows stay processing and are re-queued by their worker),
   * seq + 1, attempts 0, next_attempt now. The dispatch ticket is added
   * after the current transaction commits, at most once per process.
   *
   * @param string $operation
   *   OutboxItem::OPERATION_UPSERT or OutboxItem::OPERATION_DELETE.
   * @param string $source
   *   The source ID, or a pseudo-source starting with "_".
   * @param string $entityType
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID (at most MAX_ENTITY_ID_LENGTH characters).
   * @param string $langcode
   *   The language code ("und" for language-less rows).
   * @param string $entityUuid
   *   (optional) The entity UUID.
   * @param string|null $revisionId
   *   (optional) The revision ID (informative).
   *
   * @throws \InvalidArgumentException
   *   For an unknown operation or an empty key part.
   */
  public function recordKey(string $operation, string $source, string $entityType, string $entityId, string $langcode, string $entityUuid = '', ?string $revisionId = NULL): void {
    if (!in_array($operation, [OutboxItem::OPERATION_UPSERT, OutboxItem::OPERATION_DELETE], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown outbox operation "%s".', $operation));
    }
    if ($source === '' || $entityType === '' || $entityId === '') {
      throw new \InvalidArgumentException('Outbox keys need a source, an entity type and an entity ID.');
    }
    if (mb_strlen($entityId) > self::MAX_ENTITY_ID_LENGTH || strlen($entityType) > 64 || strlen($source) > 64 || strlen($langcode) > 12) {
      // Never break an entity save: such keys cannot be stored.
      $this->logger->warning('Change of @type @id not recorded: the key is too long for the outbox.', [
        '@type' => mb_substr($entityType, 0, 64),
        '@id' => mb_substr($entityId, 0, 64),
      ]);
      return;
    }
    $update = [];
    if ($entityUuid !== '') {
      $update['entity_uuid'] = substr($entityUuid, 0, 36);
    }
    if ($revisionId !== NULL) {
      $update['revision_id'] = substr($revisionId, 0, 32);
    }
    $this->merge([
      'source' => $source,
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'langcode' => $langcode,
    ], $operation, [
      'entity_uuid' => substr($entityUuid, 0, 36),
      'revision_id' => $revisionId === NULL ? NULL : substr($revisionId, 0, 32),
    ], $update);
  }

  /**
   * Records the deletion of a remote document unknown locally (A11).
   *
   * Used by the Reconciler for own documents that are not in the map. The
   * worker checks again that nothing maps or would produce the document
   * before deleting it.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $remoteId
   *   The remote source_id.
   */
  public function recordOrphan(string $knowledgeBase, string $remoteId): void {
    $remote = json_encode(['knowledge_base' => $knowledgeBase, 'remote_id' => $remoteId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $this->merge([
      'source' => self::SOURCE_ORPHAN,
      'entity_type' => substr($knowledgeBase, 0, 64),
      'entity_id' => hash('sha256', $remoteId),
      'langcode' => '',
    ], OutboxItem::OPERATION_DELETE, ['remote' => $remote], ['remote' => $remote]);
  }

  /**
   * Schedules a dispatch ticket after commit (or now outside transactions).
   *
   * At most once per process until the next claim() (amendment A2).
   */
  public function scheduleDispatch(): void {
    if ($this->dispatchEnsured) {
      return;
    }
    $this->dispatchEnsured = TRUE;
    $transactions = $this->database->transactionManager();
    if ($transactions->inTransaction()) {
      $transactions->addPostTransactionCallback(function (bool $success): void {
        if ($success) {
          $this->ensureTicket();
        }
        else {
          $this->dispatchEnsured = FALSE;
        }
      });
      return;
    }
    $this->ensureTicket();
  }

  /**
   * Adds a ticket to the dispatch queue unless one is already waiting.
   */
  public function enqueueDispatch(): void {
    $this->dispatchEnsured = TRUE;
    $queue = $this->queueFactory->get(self::DISPATCH_QUEUE);
    if ($queue->numberOfItems() === 0) {
      $queue->createItem(['created' => $this->time->getCurrentTime()]);
    }
  }

  /**
   * Claims due rows (amendment A3).
   *
   * Candidates: pending/failed rows with next_attempt <= now, and processing
   * rows whose lease expired. They become processing with a new claim
   * token, lease_until = now + LEASE and claimed_seq = seq.
   *
   * @param int $limit
   *   Maximum number of items.
   *
   * @return list<\Drupal\webassistant_connector\Sync\OutboxItem>
   *   The claimed items, oldest due first.
   */
  public function claim(int $limit): array {
    $this->dispatchEnsured = FALSE;
    if ($limit < 1) {
      return [];
    }
    $now = $this->time->getCurrentTime();
    $ids = $this->database->select(self::TABLE, 'o')
      ->fields('o', ['id'])
      ->condition($this->dueCondition($now))
      ->orderBy('next_attempt')
      ->orderBy('id')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();
    if ($ids === []) {
      return [];
    }
    $token = $this->uuid->generate();
    $this->database->update(self::TABLE)
      ->fields([
        'status' => OutboxItem::STATUS_PROCESSING,
        'claim_token' => $token,
        'lease_until' => $now + self::LEASE,
      ])
      ->expression('claimed_seq', '[seq]')
      ->condition('id', array_map('intval', $ids), 'IN')
      ->condition($this->dueCondition($now))
      ->execute();
    $rows = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('claim_token', $token)
      ->orderBy('next_attempt')
      ->orderBy('id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    return array_map([OutboxItem::class, 'fromRow'], $rows);
  }

  /**
   * Gives a claimed item back without processing it (lease cleared).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   An item claimed by this process.
   * @param int|null $nextAttempt
   *   (optional) A new due timestamp; by default it stays due.
   */
  public function release(OutboxItem $item, ?int $nextAttempt = NULL): void {
    $fields = [
      'status' => $item->attempts > 0 ? OutboxItem::STATUS_FAILED : OutboxItem::STATUS_PENDING,
      'claim_token' => NULL,
      'lease_until' => 0,
    ];
    if ($nextAttempt !== NULL) {
      $fields['next_attempt'] = $nextAttempt;
    }
    $this->database->update(self::TABLE)
      ->fields($fields)
      ->condition('id', $item->id)
      ->condition('claim_token', (string) $item->claimToken)
      ->execute();
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
    $row = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? OutboxItem::fromRow($row) : NULL;
  }

  /**
   * Loads the row of a key.
   *
   * @param string $source
   *   The source ID.
   * @param string $entityType
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID.
   * @param string $langcode
   *   The language code.
   *
   * @return \Drupal\webassistant_connector\Sync\OutboxItem|null
   *   The item, or NULL.
   */
  public function loadByKey(string $source, string $entityType, string $entityId, string $langcode): ?OutboxItem {
    $row = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('source', $source)
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? OutboxItem::fromRow($row) : NULL;
  }

  /**
   * Marks a claimed item as done (amendment A4).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param array<string, mixed> $remote
   *   Summarized remote result.
   * @param string|null $correlationId
   *   The X-Request-Id of the last request.
   *
   * @return bool
   *   TRUE if the row is done; FALSE if a capture happened meanwhile and the
   *   row went back to pending (or the claim was lost).
   */
  public function markDone(OutboxItem $item, array $remote = [], ?string $correlationId = NULL): bool {
    return $this->finish($item, [
      'status' => OutboxItem::STATUS_DONE,
      'last_error' => NULL,
      'remote' => self::encodeRemote($remote),
      'correlation_id' => $correlationId === NULL ? $item->correlationId : substr($correlationId, 0, 64),
    ]);
  }

  /**
   * Marks a claimed item as failed (retryable).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param string $error
   *   The error (no secrets).
   * @param int $nextAttempt
   *   Timestamp of the next attempt.
   * @param string|null $correlationId
   *   The X-Request-Id of the failed request.
   * @param bool $countAttempt
   *   (optional) FALSE for errors that are not caused by the item (invalid
   *   credentials, missing configuration): attempts is not incremented, so
   *   the item never reaches sync.max_attempts because of them.
   *
   * @return bool
   *   FALSE if a capture happened meanwhile (the row went back to pending).
   */
  public function markFailed(OutboxItem $item, string $error, int $nextAttempt, ?string $correlationId = NULL, bool $countAttempt = TRUE): bool {
    return $this->finish($item, [
      'status' => OutboxItem::STATUS_FAILED,
      'attempts' => $item->attempts + ($countAttempt ? 1 : 0),
      'next_attempt' => $nextAttempt,
      'last_error' => self::truncateError($error),
      'correlation_id' => $correlationId === NULL ? NULL : substr($correlationId, 0, 64),
    ]);
  }

  /**
   * Moves a claimed item to the dead letter; attempts is incremented.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param string $error
   *   The error (no secrets).
   * @param array<string, mixed> $remote
   *   Summarized remote result (e.g. rejection errors).
   * @param string|null $correlationId
   *   The X-Request-Id of the failed request.
   *
   * @return bool
   *   FALSE if a capture happened meanwhile (the row went back to pending).
   */
  public function markDead(OutboxItem $item, string $error, array $remote = [], ?string $correlationId = NULL): bool {
    return $this->finish($item, [
      'status' => OutboxItem::STATUS_DEAD,
      'attempts' => $item->attempts + 1,
      'last_error' => self::truncateError($error),
      'remote' => self::encodeRemote($remote),
      'correlation_id' => $correlationId === NULL ? NULL : substr($correlationId, 0, 64),
    ]);
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
    $statuses = array_values(array_intersect($statuses, OutboxItem::RETRYABLE_STATUSES));
    if ($statuses === []) {
      return 0;
    }
    $query = $this->database->update(self::TABLE)
      ->fields([
        'status' => OutboxItem::STATUS_PENDING,
        'attempts' => 0,
        'next_attempt' => $this->time->getCurrentTime(),
        'claim_token' => NULL,
        'lease_until' => 0,
      ])
      ->condition('status', $statuses, 'IN');
    if ($id !== NULL) {
      $query->condition('id', $id);
    }
    $count = (int) $query->execute();
    if ($count > 0) {
      $this->scheduleDispatch();
    }
    return $count;
  }

  /**
   * Moves processing items whose lease expired before a time to pending.
   *
   * Claims already take expired leases; this makes them visible as pending
   * (e.g. after a worker crash) for the status page.
   *
   * @param int $olderThan
   *   Timestamp: items whose lease ended before it are released.
   *
   * @return int
   *   Number of items released.
   */
  public function releaseStale(int $olderThan): int {
    return (int) $this->database->update(self::TABLE)
      ->fields([
        'status' => OutboxItem::STATUS_PENDING,
        'claim_token' => NULL,
        'lease_until' => 0,
      ])
      ->condition('status', OutboxItem::STATUS_PROCESSING)
      ->condition('lease_until', $olderThan, '<')
      ->execute();
  }

  /**
   * Deletes done items changed before a timestamp (sync.retention_days).
   *
   * @param int $before
   *   Timestamp.
   *
   * @return int
   *   Number of items deleted.
   */
  public function purge(int $before): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('status', OutboxItem::STATUS_DONE)
      ->condition('changed', $before, '<')
      ->execute();
  }

  /**
   * Deletes every row (drush webassistant:purge).
   *
   * @return int
   *   Number of rows deleted.
   */
  public function clear(): int {
    return (int) $this->database->delete(self::TABLE)->execute();
  }

  /**
   * Counts items per status.
   *
   * @return array<string, int>
   *   Counts keyed by every OutboxItem::STATUSES value (0 when none).
   */
  public function countByStatus(): array {
    $counts = array_fill_keys(OutboxItem::STATUSES, 0);
    $query = $this->database->select(self::TABLE, 'o')->fields('o', ['status']);
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('o.status');
    foreach ($query->execute()->fetchAllKeyed() as $status => $total) {
      $counts[(string) $status] = (int) $total;
    }
    return $counts;
  }

  /**
   * Counts the rows a worker could claim now (amendment A6).
   */
  public function countDue(): int {
    return (int) $this->database->select(self::TABLE, 'o')
      ->condition($this->dueCondition($this->time->getCurrentTime()))
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Whether a worker could claim a row now.
   */
  public function hasDue(): bool {
    return (bool) $this->database->select(self::TABLE, 'o')
      ->fields('o', ['id'])
      ->condition($this->dueCondition($this->time->getCurrentTime()))
      ->range(0, 1)
      ->execute()
      ->fetchField();
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
    $query = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->orderBy('changed', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, max(1, $limit));
    if ($statuses !== []) {
      $query->condition('status', $statuses, 'IN');
    }
    return array_map([OutboxItem::class, 'fromRow'], $query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Merges a capture on the unique key (amendment A2).
   *
   * @param array<string, string> $keys
   *   The key columns.
   * @param string $operation
   *   The operation hint.
   * @param array<string, mixed> $insert
   *   Extra columns for a new row.
   * @param array<string, mixed> $update
   *   Extra columns for an existing row.
   */
  private function merge(array $keys, string $operation, array $insert, array $update): void {
    $now = $this->time->getCurrentTime();
    $this->database->merge(self::TABLE)
      ->keys($keys)
      ->insertFields($keys + $insert + [
        'event_id' => $this->uuid->generate(),
        'operation' => $operation,
        'status' => OutboxItem::STATUS_PENDING,
        'seq' => 1,
        'claimed_seq' => 0,
        'attempts' => 0,
        'next_attempt' => $now,
        'created' => $now,
        'changed' => $now,
      ])
      ->updateFields($update + [
        'operation' => $operation,
        'attempts' => 0,
        'next_attempt' => $now,
        'last_error' => NULL,
        'changed' => $now,
      ])
      // Expressions run before the plain fields and in this order, so every
      // CASE reads the previous status (also on MySQL, which evaluates SET
      // assignments from left to right). A row that was done or dead starts
      // a new delivery cycle: its latency is measured from now. A row being
      // processed stays processing: its worker sees seq != claimed_seq and
      // puts it back to pending when it finishes.
      ->expression('created', 'CASE WHEN [status] IN (:wa_created_done, :wa_created_dead) THEN :wa_created_now ELSE [created] END', [
        ':wa_created_done' => OutboxItem::STATUS_DONE,
        ':wa_created_dead' => OutboxItem::STATUS_DEAD,
        ':wa_created_now' => $now,
      ])
      ->expression('status', 'CASE WHEN [status] = :wa_status_processing THEN :wa_status_keep ELSE :wa_status_pending END', [
        ':wa_status_processing' => OutboxItem::STATUS_PROCESSING,
        ':wa_status_keep' => OutboxItem::STATUS_PROCESSING,
        ':wa_status_pending' => OutboxItem::STATUS_PENDING,
      ])
      ->expression('seq', '[seq] + 1')
      ->execute();
    $this->scheduleDispatch();
  }

  /**
   * Finishes a claimed row guarded by its claim token and seq (A4).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param array<string, mixed> $fields
   *   The columns to set.
   *
   * @return bool
   *   TRUE if finished; FALSE if the row was put back to pending.
   */
  private function finish(OutboxItem $item, array $fields): bool {
    $now = $this->time->getCurrentTime();
    $updated = (int) $this->database->update(self::TABLE)
      ->fields($fields + [
        'claim_token' => NULL,
        'lease_until' => 0,
        'changed' => $now,
      ])
      ->condition('id', $item->id)
      ->condition('claim_token', (string) $item->claimToken)
      ->where('[seq] = [claimed_seq]')
      ->execute();
    if ($updated > 0) {
      return TRUE;
    }
    // A capture happened while the row was processed: process it again from
    // the fresh state (the attempts were reset by the capture).
    $this->database->update(self::TABLE)
      ->fields([
        'status' => OutboxItem::STATUS_PENDING,
        'claim_token' => NULL,
        'lease_until' => 0,
        'next_attempt' => $now,
      ])
      ->condition('id', $item->id)
      ->condition('claim_token', (string) $item->claimToken)
      ->execute();
    return FALSE;
  }

  /**
   * Adds the dispatch ticket, logging instead of failing.
   */
  private function ensureTicket(): void {
    try {
      $this->enqueueDispatch();
    }
    catch (\Throwable $e) {
      $this->dispatchEnsured = FALSE;
      $this->logger->error('Could not add the WebAssistant dispatch ticket: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Builds the condition of claimable rows.
   *
   * @param int $now
   *   The current timestamp.
   *
   * @return \Drupal\Core\Database\Query\ConditionInterface
   *   (pending or failed, due) or (processing with an expired lease).
   */
  private function dueCondition(int $now): ConditionInterface {
    return $this->database->condition('OR')
      ->condition($this->database->condition('AND')
        ->condition('status', [OutboxItem::STATUS_PENDING, OutboxItem::STATUS_FAILED], 'IN')
        ->condition('next_attempt', $now, '<='))
      ->condition($this->database->condition('AND')
        ->condition('status', OutboxItem::STATUS_PROCESSING)
        ->condition('lease_until', $now, '<'));
  }

  /**
   * Encodes the remote summary (NULL when empty).
   *
   * @param array<string, mixed> $remote
   *   The summary.
   *
   * @return string|null
   *   JSON, or NULL.
   */
  private static function encodeRemote(array $remote): ?string {
    if ($remote === []) {
      return NULL;
    }
    $json = json_encode($remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $json === FALSE ? NULL : $json;
  }

  /**
   * Truncates an error to the column length.
   *
   * @param string $error
   *   The error.
   *
   * @return string
   *   At most MAX_ERROR_LENGTH characters.
   */
  private static function truncateError(string $error): string {
    return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
  }

}
