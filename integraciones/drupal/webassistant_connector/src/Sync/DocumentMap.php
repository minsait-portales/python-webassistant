<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Map of documents sent per entity and translation (design 9.1, DR-05.7).
 *
 * Rows are arrays with the table columns; "acl" is decoded to a list:
 * array{knowledge_base: string, remote_id: string, source: string,
 * entity_type: string, entity_id: string, langcode: string,
 * attachment: string|null, version: string, content_hash: string,
 * acl: list<string>, remote_status: string, synced: int}.
 *
 * Service: webassistant_connector.document_map. Table: TABLE.
 */
final class DocumentMap {

  /**
   * The map table.
   */
  public const TABLE = 'webassistant_connector_document';

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
    throw new \LogicException('Not implemented yet.');
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
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Inserts or updates a row (merge on knowledge_base + remote_id).
   *
   * @param array<string, mixed> $row
   *   The columns; acl as a list (encoded to JSON); synced defaults to now.
   */
  public function save(array $row): void {
    throw new \LogicException('Not implemented yet.');
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
    throw new \LogicException('Not implemented yet.');
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
    throw new \LogicException('Not implemented yet.');
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
    throw new \LogicException('Not implemented yet.');
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
    throw new \LogicException('Not implemented yet.');
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
    throw new \LogicException('Not implemented yet.');
  }

}
