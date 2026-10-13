<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Initial indexing and reindexing through the outbox (design section 9.4).
 *
 * Records upsert events for existing entities, one page at a time ordered by
 * ID (integer or string IDs, amendment A13). Used by the Batch API (status
 * page), drush webassistant:index and the index queue, which reindexes a
 * source in the background when its scope changes (amendment A9) or when the
 * access epoch changes (amendment A12).
 *
 * Service: webassistant_connector.indexer.
 */
final class Indexer {

  /**
   * The queue that reindexes sources in the background.
   */
  public const INDEX_QUEUE = 'webassistant_connector_index';

  /**
   * State key: current job token per "source:mode".
   *
   * A newer job supersedes the older jobs of the same source and mode.
   */
  public const STATE_JOBS = 'webassistant_connector.index_jobs';

  /**
   * Queue job mode: record events for every entity covered by the source.
   */
  public const MODE_ENTITIES = 'entities';

  /**
   * Queue job mode: record events for every mapped document of the source.
   */
  public const MODE_MAP = 'map';

  /**
   * Entities per page.
   */
  public const PAGE_SIZE = 100;

  /**
   * Constructs an Indexer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Outbox $outbox,
    private readonly DocumentMap $documentMap,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
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
   * @param int|string $lastId
   *   Exclusive lower bound of entity IDs (0 or '' to start).
   * @param int $limit
   *   Maximum number of entities.
   *
   * @return array{count: int, last_id: int|string, entities: int}
   *   Events recorded, the last entity ID seen (equal to $lastId when there
   *   are no more entities) and the number of entities in the page.
   */
  public function enqueue(SourceInterface $source, ?string $bundle, ?string $langcode, int|string $lastId, int $limit): array {
    $ids = $this->pageIds($source, $bundle, $lastId, $limit);
    if ($ids === []) {
      return ['count' => 0, 'last_id' => $lastId, 'entities' => 0];
    }
    $storage = $this->entityTypeManager->getStorage($source->getTargetEntityTypeId());
    $count = 0;
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof ContentEntityInterface || !$source->appliesTo($entity)) {
        continue;
      }
      foreach (array_keys($entity->getTranslationLanguages()) as $translationLangcode) {
        $translationLangcode = (string) $translationLangcode;
        if (($langcode !== NULL && $langcode !== $translationLangcode) || !$source->appliesToLangcode($translationLangcode)) {
          continue;
        }
        $this->outbox->record(OutboxItem::OPERATION_UPSERT, $source, $entity, $translationLangcode);
        $count++;
      }
    }
    $storage->resetCache($ids);
    $last = end($ids);
    return [
      'count' => $count,
      'last_id' => is_int($lastId) && is_numeric($last) ? (int) $last : (string) $last,
      'entities' => count($ids),
    ];
  }

  /**
   * Records upsert events for one page of mapped documents of a source.
   *
   * The worker deletes what is out of scope (amendment A9): this is how a
   * disabled, deleted or narrowed source removes its documents.
   *
   * @param string $sourceId
   *   The source ID (it may not exist anymore).
   * @param string|null $afterRemoteId
   *   Exclusive cursor, or NULL to start.
   * @param int $limit
   *   Maximum number of map rows.
   *
   * @return array{count: int, after: string|null}
   *   Rows seen and the cursor of the next page (NULL when done).
   */
  public function enqueueMapped(string $sourceId, ?string $afterRemoteId, int $limit): array {
    $rows = $this->documentMap->forSource($sourceId, $afterRemoteId, $limit);
    foreach ($rows as $row) {
      $this->outbox->recordKey(OutboxItem::OPERATION_UPSERT, $sourceId, (string) $row['entity_type'], (string) $row['entity_id'], (string) $row['langcode']);
    }
    $after = $rows === [] ? NULL : (string) end($rows)['remote_id'];
    return ['count' => count($rows), 'after' => count($rows) < $limit ? NULL : $after];
  }

  /**
   * Schedules a background reindex of a source (index queue).
   *
   * Records events for every covered entity and, when $includeMapped, for
   * every mapped document (so out-of-scope documents are deleted). A newer
   * job for the same source and mode supersedes the pending one.
   *
   * @param string $sourceId
   *   The source ID.
   * @param bool $includeEntities
   *   Index the entities covered by the source (if it is enabled).
   * @param bool $includeMapped
   *   Refresh the mapped documents of the source.
   * @param string $reason
   *   (optional) Why, for the logs.
   */
  public function scheduleReindex(string $sourceId, bool $includeEntities = TRUE, bool $includeMapped = TRUE, string $reason = ''): void {
    $jobs = $this->state->get(self::STATE_JOBS, []);
    $jobs = is_array($jobs) ? $jobs : [];
    $queue = $this->queueFactory->get(self::INDEX_QUEUE);
    $base = ['source' => $sourceId, 'reason' => $reason, 'created' => $this->time->getCurrentTime()];
    if ($includeMapped) {
      $token = $this->uuid->generate();
      $jobs[$sourceId . ':' . self::MODE_MAP] = $token;
      $queue->createItem($base + ['mode' => self::MODE_MAP, 'token' => $token, 'after' => NULL]);
    }
    if ($includeEntities) {
      $token = $this->uuid->generate();
      $jobs[$sourceId . ':' . self::MODE_ENTITIES] = $token;
      $queue->createItem($base + [
        'mode' => self::MODE_ENTITIES,
        'token' => $token,
        'bundle' => NULL,
        'langcode' => NULL,
        'last_id' => 0,
      ]);
    }
    $this->state->set(self::STATE_JOBS, $jobs);
  }

  /**
   * Processes one page of a background reindex job (index queue item).
   *
   * @param array<string, mixed> $job
   *   The queue item data.
   *
   * @return array<string, mixed>|null
   *   The job data of the next page, or NULL when the job is finished or
   *   superseded.
   */
  public function processJob(array $job): ?array {
    $sourceId = is_string($job['source'] ?? NULL) ? $job['source'] : '';
    $mode = ($job['mode'] ?? '') === self::MODE_MAP ? self::MODE_MAP : self::MODE_ENTITIES;
    $jobs = $this->state->get(self::STATE_JOBS, []);
    if ($sourceId === '' || !is_array($jobs) || ($jobs[$sourceId . ':' . $mode] ?? NULL) !== ($job['token'] ?? NULL)) {
      return NULL;
    }
    if ($mode === self::MODE_MAP) {
      $result = $this->enqueueMapped($sourceId, isset($job['after']) && is_string($job['after']) ? $job['after'] : NULL, self::PAGE_SIZE * 5);
      return $result['after'] === NULL ? NULL : ['after' => $result['after']] + $job;
    }
    $source = $this->entityTypeManager->getStorage('webassistant_source')->load($sourceId);
    if (!$source instanceof SourceInterface || !$source->status() || !$this->entityTypeManager->hasDefinition($source->getTargetEntityTypeId())) {
      return NULL;
    }
    $lastId = $job['last_id'] ?? 0;
    $lastId = is_int($lastId) || is_string($lastId) ? $lastId : 0;
    $bundle = isset($job['bundle']) && is_string($job['bundle']) ? $job['bundle'] : NULL;
    $langcode = isset($job['langcode']) && is_string($job['langcode']) ? $job['langcode'] : NULL;
    $result = $this->enqueue($source, $bundle, $langcode, $lastId, self::PAGE_SIZE);
    return $result['entities'] < self::PAGE_SIZE ? NULL : ['last_id' => $result['last_id']] + $job;
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
    $query = $this->baseQuery($source, $bundle);
    return $query === NULL ? 0 : (int) $query->count()->execute();
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
    return (new BatchBuilder())
      ->setTitle(new TranslatableMarkup('Indexing the source %source', ['%source' => $source->label()]))
      ->setInitMessage(new TranslatableMarkup('Recording the entities of the source.'))
      ->setProgressMessage(new TranslatableMarkup('Processed @current of @total.'))
      ->setErrorMessage(new TranslatableMarkup('The indexing stopped because of an error.'))
      ->addOperation([self::class, 'batchOperation'], [(string) $source->id(), $bundle, $langcode, $process])
      ->setFinishCallback([self::class, 'batchFinished'])
      ->toArray();
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
    // Batch callbacks are static: services come from the container.
    $indexer = \Drupal::service('webassistant_connector.indexer');
    $source = \Drupal::entityTypeManager()->getStorage('webassistant_source')->load($sourceId);
    if (!$source instanceof SourceInterface) {
      $context['finished'] = 1;
      return;
    }
    $sandbox = $context['sandbox'] ?? [];
    if (!isset($sandbox['total'])) {
      $sandbox = ['total' => $indexer->count($source, $bundle), 'progress' => 0, 'last_id' => 0];
      $context['results'] = [
        'source' => $sourceId,
        'recorded' => 0,
        'upserted' => 0,
        'deleted' => 0,
        'failed' => 0,
        'dead' => 0,
      ];
    }
    $page = $indexer->enqueue($source, $bundle, $langcode, $sandbox['last_id'], self::PAGE_SIZE);
    $results = $context['results'];
    $results['recorded'] += $page['count'];
    if ($process && $page['count'] > 0) {
      $report = \Drupal::service('webassistant_connector.sync_processor')->process(self::PAGE_SIZE * 2, 20);
      $results['upserted'] += $report->getUpserted();
      $results['deleted'] += $report->getDeleted();
      $results['failed'] += $report->getFailed();
      $results['dead'] += $report->getDead();
      $results['suspended'] = !empty($results['suspended']) || $report->isSuspended();
    }
    $context['results'] = $results;
    $sandbox['progress'] += $page['entities'];
    $sandbox['last_id'] = $page['last_id'];
    $done = $page['entities'] < self::PAGE_SIZE || $sandbox['total'] === 0;
    $context['sandbox'] = $sandbox;
    $context['message'] = new TranslatableMarkup('Recorded @count events for @progress of @total entities.', [
      '@count' => $results['recorded'],
      '@progress' => $sandbox['progress'],
      '@total' => $sandbox['total'],
    ]);
    $context['finished'] = $done ? 1 : min(0.99, $sandbox['progress'] / max(1, $sandbox['total']));
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
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The indexing did not finish. Events recorded so far stay in the outbox.'));
      return;
    }
    $messenger->addStatus(new TranslatableMarkup('@count events recorded in the outbox.', ['@count' => (int) ($results['recorded'] ?? 0)]));
    if (!empty($results['upserted']) || !empty($results['deleted'])) {
      $messenger->addStatus(new TranslatableMarkup('@upserted documents sent and @deleted deleted.', [
        '@upserted' => (int) $results['upserted'],
        '@deleted' => (int) $results['deleted'],
      ]));
    }
    if (!empty($results['failed']) || !empty($results['dead'])) {
      $messenger->addWarning(new TranslatableMarkup('@failed events failed and will be retried; @dead events are in the dead letter.', [
        '@failed' => (int) $results['failed'],
        '@dead' => (int) $results['dead'],
      ]));
    }
    if (!empty($results['suspended'])) {
      $messenger->addWarning(new TranslatableMarkup('Sending stopped: the platform is not available or the connector is not fully configured (credentials, public base URL). Cron sends the recorded events once the status page shows no errors.'));
    }
  }

  /**
   * Returns the IDs of a page of entities.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string|null $bundle
   *   Restrict to a bundle, or NULL.
   * @param int|string $lastId
   *   Exclusive lower bound.
   * @param int $limit
   *   Page size.
   *
   * @return list<int|string>
   *   The IDs in ascending order.
   */
  private function pageIds(SourceInterface $source, ?string $bundle, int|string $lastId, int $limit): array {
    $query = $this->baseQuery($source, $bundle);
    if ($query === NULL) {
      return [];
    }
    $idKey = (string) $this->entityTypeManager->getDefinition($source->getTargetEntityTypeId())->getKey('id');
    if ($lastId !== 0 && $lastId !== '' && $lastId !== '0') {
      $query->condition($idKey, $lastId, '>');
    }
    $ids = $query->sort($idKey)->range(0, max(1, $limit))->execute();
    return array_values($ids);
  }

  /**
   * Builds the entity query of a source.
   *
   * No access check: indexing is a system task and DocumentBuilder computes
   * the ACL of every document.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string|null $bundle
   *   Restrict to a bundle, or NULL.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   The query, or NULL when nothing can match.
   */
  private function baseQuery(SourceInterface $source, ?string $bundle): ?QueryInterface {
    $entityTypeId = $source->getTargetEntityTypeId();
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if ($definition === NULL || !$definition->entityClassImplements(ContentEntityInterface::class)) {
      return NULL;
    }
    $bundles = $source->getBundles();
    if ($bundle !== NULL && $bundle !== '') {
      if ($bundles !== [] && !in_array($bundle, $bundles, TRUE)) {
        return NULL;
      }
      $bundles = [$bundle];
    }
    $query = $this->entityTypeManager->getStorage($entityTypeId)->getQuery()->accessCheck(FALSE);
    $bundleKey = $definition->getKey('bundle');
    if ($bundles !== [] && is_string($bundleKey) && $bundleKey !== '') {
      $query->condition($bundleKey, $bundles, 'IN');
    }
    return $query;
  }

}
