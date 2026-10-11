<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Initial indexing: records upserts for existing entities (section 9.4).
 *
 * Used by the Batch API (status page) and by drush webassistant:index.
 *
 * Service: webassistant_connector.indexer.
 */
final class Indexer {

  /**
   * Constructs an Indexer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Outbox $outbox,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Records upsert events for one page of entities, ordered by ID.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string|null $bundle
   *   Restrict to a bundle (must be covered by the source), or NULL.
   * @param string|null $langcode
   *   Restrict to a language, or NULL for every covered translation.
   * @param int $lastId
   *   Exclusive lower bound of entity IDs (0 to start).
   * @param int $limit
   *   Maximum number of entities.
   *
   * @return array{count: int, last_id: int}
   *   Events recorded and the last entity ID seen (equal to $lastId when
   *   there are no more entities).
   */
  public function enqueue(SourceInterface $source, ?string $bundle, ?string $langcode, int $lastId, int $limit): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Counts the entities covered by a source (for progress reporting).
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string|null $bundle
   *   Restrict to a bundle, or NULL.
   *
   * @return int
   *   The number of entities.
   */
  public function count(SourceInterface $source, ?string $bundle = NULL): int {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Returns a Batch API definition that indexes a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string|null $bundle
   *   Restrict to a bundle, or NULL.
   * @param string|null $langcode
   *   Restrict to a language, or NULL.
   * @param bool $process
   *   Also process the outbox after each page.
   *
   * @return array<string, mixed>
   *   The batch definition (BatchBuilder::toArray()), operations calling
   *   self::batchOperation() and finished calling self::batchFinished().
   */
  public function createBatch(SourceInterface $source, ?string $bundle = NULL, ?string $langcode = NULL, bool $process = FALSE): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Batch API operation callback.
   *
   * @param string $sourceId
   *   The source ID.
   * @param string|null $bundle
   *   Restrict to a bundle, or NULL.
   * @param string|null $langcode
   *   Restrict to a language, or NULL.
   * @param bool $process
   *   Also process the outbox after each page.
   * @param array<string, mixed>|\ArrayAccess<string, mixed> $context
   *   The batch context.
   */
  public static function batchOperation(string $sourceId, ?string $bundle, ?string $langcode, bool $process, array|\ArrayAccess &$context): void {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Batch API finished callback.
   *
   * @param bool $success
   *   Whether the batch finished without errors.
   * @param array<string, mixed> $results
   *   The results collected by the operations.
   * @param array<int, mixed> $operations
   *   The operations left when an error happened.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    throw new \LogicException('Not implemented yet.');
  }

}
