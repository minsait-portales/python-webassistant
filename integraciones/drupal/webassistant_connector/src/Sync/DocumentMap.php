<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Map of documents sent per entity and translation (design 9.1, DR-05.7).
 *
 * Rows are arrays with the table columns; "acl" is decoded to a list:
 * array{knowledge_base: string, remote_id: string, source: string,
 * entity_type: string, entity_id: string, langcode: string,
 * attachment: string|null, version: string, content_hash: string,
 * payload_hash: string, acl: list<string>, remote_status: string,
 * synced: int, reconciled: int}.
 *
 * Service: webassistant_connector.document_map. Table: TABLE.
 */
final class DocumentMap {

  /**
   * The map table.
   */
  public const TABLE = 'webassistant_connector_document';

  /**
   * Remote status of a row written before its ingest request (A14).
   */
  public const STATUS_SENDING = 'sending';

  /**
   * Remote status of a row the reconciler found outdated (resend it).
   */
  public const STATUS_STALE = 'stale';

  /**
   * Remote statuses after which an unchanged document is not sent again.
   */
  public const SETTLED_STATUSES = ['queued', 'processing', 'ready'];

  /**
   * Columns accepted by save().
   */
  private const COLUMNS = [
    'knowledge_base',
    'remote_id',
    'source',
    'entity_type',
    'entity_id',
    'langcode',
    'attachment',
    'version',
    'content_hash',
    'payload_hash',
    'acl',
    'remote_status',
    'synced',
    'reconciled',
  ];

  /**
   * Columns holding strings.
   */
  private const STRING_COLUMNS = [
    'knowledge_base',
    'remote_id',
    'source',
    'entity_type',
    'entity_id',
    'langcode',
    'version',
    'content_hash',
    'payload_hash',
    'remote_status',
  ];

  /**
   * Reference members that change on every build (signed URL, expiry).
   */
  private const VOLATILE_REFERENCE_KEYS = ['download_url', 'expires_at', 'access_token'];

  /**
   * Constructs a DocumentMap.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the rows of an entity (optionally one translation).
   *
   * @param string $source
   *   The source ID.
   * @param string $entityType
   *   The entity type ID.
   * @param string $entityId
   *   The entity ID.
   * @param string|null $langcode
   *   The language code, or NULL for every translation.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  public function find(string $source, string $entityType, string $entityId, ?string $langcode = NULL): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('source', $source)
      ->condition('entity_type', $entityType)
      ->condition('entity_id', $entityId)
      ->orderBy('knowledge_base')
      ->orderBy('remote_id');
    if ($langcode !== NULL) {
      $query->condition('langcode', $langcode);
    }
    return $this->decodeAll($query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Loads one row.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $remoteId
   *   The remote source_id.
   *
   * @return array<string, mixed>|null
   *   The row, or NULL.
   */
  public function load(string $knowledgeBase, string $remoteId): ?array {
    $row = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('knowledge_base', $knowledgeBase)
      ->condition('remote_id', $remoteId)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? self::decode($row) : NULL;
  }

  /**
   * Loads the rows of several remote IDs of a knowledge base.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param list<string> $remoteIds
   *   The remote source_ids.
   *
   * @return array<string, array<string, mixed>>
   *   The rows keyed by remote_id (missing IDs are absent).
   */
  public function loadMultiple(string $knowledgeBase, array $remoteIds): array {
    $rows = [];
    foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
      $result = $this->database->select(self::TABLE, 'm')
        ->fields('m')
        ->condition('knowledge_base', $knowledgeBase)
        ->condition('remote_id', $chunk, 'IN')
        ->execute()
        ->fetchAll(FetchAs::Associative);
      foreach ($result as $row) {
        $rows[(string) $row['remote_id']] = self::decode($row);
      }
    }
    return $rows;
  }

  /**
   * Inserts or updates a row (merge on knowledge_base + remote_id).
   *
   * @param array<string, mixed> $row
   *   The columns; acl as a list (encoded to JSON); synced defaults to now.
   *   Unknown columns are ignored.
   *
   * @throws \InvalidArgumentException
   *   When knowledge_base or remote_id is missing.
   */
  public function save(array $row): void {
    $knowledgeBase = (string) ($row['knowledge_base'] ?? '');
    $remoteId = (string) ($row['remote_id'] ?? '');
    if ($knowledgeBase === '' || $remoteId === '') {
      throw new \InvalidArgumentException('A map row needs a knowledge base and a remote ID.');
    }
    $fields = array_intersect_key($row, array_flip(self::COLUMNS));
    unset($fields['knowledge_base'], $fields['remote_id']);
    if (array_key_exists('acl', $fields)) {
      $acl = is_array($fields['acl']) ? array_values(array_map('strval', $fields['acl'])) : [];
      $fields['acl'] = json_encode($acl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    foreach (self::STRING_COLUMNS as $column) {
      if ($column !== 'knowledge_base' && $column !== 'remote_id' && array_key_exists($column, $fields)) {
        $fields[$column] = (string) $fields[$column];
      }
    }
    if (array_key_exists('attachment', $fields) && ($fields['attachment'] === '' || $fields['attachment'] === NULL)) {
      $fields['attachment'] = NULL;
    }
    $fields['synced'] = (int) ($fields['synced'] ?? $this->time->getCurrentTime());
    $this->database->merge(self::TABLE)
      ->keys(['knowledge_base' => $knowledgeBase, 'remote_id' => $remoteId])
      ->fields($fields)
      ->execute();
  }

  /**
   * Sets the remote status of a row (e.g. STATUS_STALE).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $remoteId
   *   The remote source_id.
   * @param string $status
   *   The status.
   */
  public function setRemoteStatus(string $knowledgeBase, string $remoteId, string $status): void {
    $this->database->update(self::TABLE)
      ->fields(['remote_status' => $status])
      ->condition('knowledge_base', $knowledgeBase)
      ->condition('remote_id', $remoteId)
      ->execute();
  }

  /**
   * Records that a reconciliation found documents remotely.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param list<string> $remoteIds
   *   The remote source_ids found.
   * @param int $timestamp
   *   The start timestamp of the reconciliation.
   */
  public function markReconciled(string $knowledgeBase, array $remoteIds, int $timestamp): void {
    foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
      $this->database->update(self::TABLE)
        ->fields(['reconciled' => $timestamp])
        ->condition('knowledge_base', $knowledgeBase)
        ->condition('remote_id', $chunk, 'IN')
        ->execute();
    }
  }

  /**
   * Returns rows a reconciliation did not find remotely.
   *
   * Rows synchronized after the reconciliation started are excluded: they
   * may have been sent after the inventory page that would list them.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param int $before
   *   The start timestamp of the reconciliation.
   * @param string|null $afterRemoteId
   *   Exclusive cursor.
   * @param int $limit
   *   Page size.
   *
   * @return list<array<string, mixed>>
   *   The rows ordered by remote_id.
   */
  public function findUnreconciled(string $knowledgeBase, int $before, ?string $afterRemoteId = NULL, int $limit = 500): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('knowledge_base', $knowledgeBase)
      ->condition('reconciled', $before, '<')
      ->condition('synced', $before, '<')
      ->orderBy('remote_id')
      ->range(0, max(1, $limit));
    if ($afterRemoteId !== NULL) {
      $query->condition('remote_id', $afterRemoteId, '>');
    }
    return $this->decodeAll($query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Deletes a row.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $remoteId
   *   The remote source_id.
   */
  public function delete(string $knowledgeBase, string $remoteId): void {
    $this->database->delete(self::TABLE)
      ->condition('knowledge_base', $knowledgeBase)
      ->condition('remote_id', $remoteId)
      ->execute();
  }

  /**
   * Returns the rows of documents generated from a file.
   *
   * @param string $fileUuid
   *   The file UUID (column attachment).
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  public function findByAttachment(string $fileUuid): array {
    if ($fileUuid === '') {
      return [];
    }
    $rows = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('attachment', $fileUuid)
      ->orderBy('knowledge_base')
      ->orderBy('remote_id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    return $this->decodeAll($rows);
  }

  /**
   * Returns a page of rows of a knowledge base ordered by remote_id.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string|null $afterRemoteId
   *   Exclusive cursor.
   * @param int $limit
   *   Page size.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  public function forKnowledgeBase(string $knowledgeBase, ?string $afterRemoteId = NULL, int $limit = 500): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('knowledge_base', $knowledgeBase)
      ->orderBy('remote_id')
      ->range(0, max(1, $limit));
    if ($afterRemoteId !== NULL) {
      $query->condition('remote_id', $afterRemoteId, '>');
    }
    return $this->decodeAll($query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Returns a page of rows of a source ordered by remote_id.
   *
   * @param string $source
   *   The source ID.
   * @param string|null $afterRemoteId
   *   Exclusive cursor (remote IDs are unique within a source).
   * @param int $limit
   *   Page size.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  public function forSource(string $source, ?string $afterRemoteId = NULL, int $limit = 500): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('source', $source)
      ->orderBy('remote_id')
      ->orderBy('knowledge_base')
      ->range(0, max(1, $limit));
    if ($afterRemoteId !== NULL) {
      $query->condition('remote_id', $afterRemoteId, '>');
    }
    return $this->decodeAll($query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Returns a page of every row ordered by knowledge base and remote ID.
   *
   * @param array{0: string, 1: string}|null $after
   *   Exclusive cursor [knowledge_base, remote_id], or NULL to start.
   * @param int $limit
   *   Page size.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  public function page(?array $after = NULL, int $limit = 500): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->orderBy('knowledge_base')
      ->orderBy('remote_id')
      ->range(0, max(1, $limit));
    if ($after !== NULL) {
      $query->condition($this->database->condition('OR')
        ->condition('knowledge_base', $after[0], '>')
        ->condition($this->database->condition('AND')
          ->condition('knowledge_base', $after[0])
          ->condition('remote_id', $after[1], '>')));
    }
    return $this->decodeAll($query->execute()->fetchAll(FetchAs::Associative));
  }

  /**
   * Returns the distinct knowledge bases present in the map.
   *
   * @return list<string>
   *   Sorted knowledge base keys.
   */
  public function knowledgeBases(): array {
    $keys = $this->database->select(self::TABLE, 'm')
      ->fields('m', ['knowledge_base'])
      ->distinct()
      ->orderBy('knowledge_base')
      ->execute()
      ->fetchCol();
    return array_values(array_map('strval', $keys));
  }

  /**
   * Deletes every row of a source (e.g. when the source is deleted).
   *
   * @param string $source
   *   The source ID.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function deleteBySource(string $source): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('source', $source)
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
   * Counts rows, optionally for one knowledge base.
   *
   * @param string|null $knowledgeBase
   *   The knowledge base key, or NULL for all.
   *
   * @return int
   *   The number of rows.
   */
  public function count(?string $knowledgeBase = NULL): int {
    $query = $this->database->select(self::TABLE, 'm');
    if ($knowledgeBase !== NULL) {
      $query->condition('knowledge_base', $knowledgeBase);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Whether a row needs no new ingest for a document (amendment A8).
   *
   * @param array<string, mixed>|null $row
   *   The map row, or NULL.
   * @param string $payloadHash
   *   The payload hash of the document to send.
   *
   * @return bool
   *   TRUE when the row has the same payload hash and a settled remote
   *   status (queued, processing or ready).
   */
  public static function isUpToDate(?array $row, string $payloadHash): bool {
    return $row !== NULL
      && ($row['payload_hash'] ?? '') === $payloadHash
      && in_array($row['remote_status'] ?? '', self::SETTLED_STATUSES, TRUE);
  }

  /**
   * Returns the payload hash of a canonical document (amendment A8).
   *
   * SHA-256 of the canonical-document.v1 array without the volatile members
   * of "reference" (download_url, expires_at, access_token): a signed URL
   * minted again does not make the document look changed.
   *
   * @param array<string, mixed> $document
   *   The canonical document (CanonicalDocument::toArray()).
   *
   * @return string
   *   The hexadecimal hash.
   */
  public static function payloadHash(array $document): string {
    if (isset($document['reference']) && is_array($document['reference'])) {
      $document['reference'] = array_diff_key($document['reference'], array_flip(self::VOLATILE_REFERENCE_KEYS));
    }
    return hash('sha256', (string) json_encode(self::sortKeys($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
  }

  /**
   * Sorts associative arrays by key recursively (lists keep their order).
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   The sorted value.
   */
  private static function sortKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value);
    }
    return array_map([self::class, 'sortKeys'], $value);
  }

  /**
   * Decodes rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Raw rows.
   *
   * @return list<array<string, mixed>>
   *   Decoded rows.
   */
  private function decodeAll(array $rows): array {
    return array_values(array_map([self::class, 'decode'], $rows));
  }

  /**
   * Decodes a raw row (acl JSON to list, integers cast).
   *
   * @param array<string, mixed> $row
   *   The raw row.
   *
   * @return array<string, mixed>
   *   The decoded row.
   */
  private static function decode(array $row): array {
    $acl = [];
    if (isset($row['acl']) && is_string($row['acl']) && $row['acl'] !== '') {
      $decoded = json_decode($row['acl'], TRUE);
      $acl = is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
    }
    $row['acl'] = $acl;
    foreach (self::STRING_COLUMNS as $column) {
      $row[$column] = (string) ($row[$column] ?? '');
    }
    $row['attachment'] = isset($row['attachment']) && $row['attachment'] !== '' ? (string) $row['attachment'] : NULL;
    $row['synced'] = (int) ($row['synced'] ?? 0);
    $row['reconciled'] = (int) ($row['reconciled'] ?? 0);
    return $row;
  }

}
