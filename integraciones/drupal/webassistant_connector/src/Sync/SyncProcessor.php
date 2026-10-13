<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Processes the outbox (design section 9.3, amendments A1-A5, A8, A11).
 *
 * Used by the dispatch queue, Drush, the Batch API and the status page.
 * HTTP calls never run inside a database transaction.
 *
 * Service: webassistant_connector.sync_processor.
 */
final class SyncProcessor {

  /**
   * Name of the lock held while claiming events (kept for compatibility).
   *
   * Unused since amendment A3: claims use leases.
   */
  public const LOCK_NAME = 'webassistant_connector.sync';

  /**
   * State key with the timestamp of the last successful delivery.
   */
  public const STATE_LAST_SUCCESS = 'webassistant_connector.last_success';

  /**
   * Rows claimed at once (the rest of the limit is claimed later).
   */
  public const CLAIM_CHUNK = 25;

  /**
   * Upper bound of the exponential backoff in seconds.
   */
  public const MAX_BACKOFF = 3600;

  /**
   * Base of the exponential backoff in seconds (30 * 2^attempts).
   */
  public const BASE_BACKOFF = 30;

  /**
   * Minimum delay honoured from a Retry-After header.
   */
  public const MIN_RETRY_AFTER = 5;

  /**
   * Maximum delay honoured from a Retry-After header (one day).
   */
  public const MAX_RETRY_AFTER = 86400;

  /**
   * The handlers of pseudo-source rows.
   *
   * @var iterable<\Drupal\webassistant_connector\Sync\OutboxHandlerInterface>
   */
  private iterable $handlers;

  /**
   * Constructs a SyncProcessor.
   *
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\webassistant_connector\Sync\DocumentBuilder $documentBuilder
   *   The document builder.
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials (processing waits while they are missing).
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher (orphan checks).
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator (orphan checks).
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (loads sources, resets caches).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   * @param iterable<\Drupal\webassistant_connector\Sync\OutboxHandlerInterface> $handlers
   *   Services tagged webassistant_connector.outbox_handler.
   */
  public function __construct(
    private readonly Outbox $outbox,
    private readonly DocumentMap $documentMap,
    private readonly DocumentBuilder $documentBuilder,
    private readonly PlatformClientInterface $platformClient,
    private readonly Credentials $credentials,
    private readonly SourceMatcher $sourceMatcher,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
    iterable $handlers = [],
  ) {
    $this->handlers = $handlers;
  }

  /**
   * Whether processing is enabled (enabled and sync.enabled, A15).
   *
   * Captures are recorded either way, so re-enabling catches up.
   */
  public function isEnabled(): bool {
    $config = $this->configFactory->get('webassistant_connector.settings');
    return (bool) $config->get('enabled') && (bool) $config->get('sync.enabled');
  }

  /**
   * Processes due outbox events.
   *
   * 1. Claims due events in chunks (leases, amendment A3).
   * 2. upsert or delete (the operation is a hint, A1): DocumentBuilder
   *    decides from the current state; a skip reason deletes the mapped
   *    documents; otherwise changed documents are sent in batches of at most
   *    sync.batch_size (unchanged ones are skipped, A8) and mapped documents
   *    no longer generated are deleted.
   * 3. Per item: accepted results update the map and mark the event done;
   *    rejected marks it dead; a retryable PlatformException marks it failed
   *    with next_attempt = now + min(3600, 30 * 2^attempts) or Retry-After;
   *    non-retryable errors or attempts >= sync.max_attempts: dead.
   * 4. Network errors, 5xx, 429 or 401 stop the run (circuit breaker, A5):
   *    the remaining claimed events are released and the report is
   *    suspended. A 401 or missing configuration does not count as an
   *    attempt of the event (it never dies of it). The time budget is
   *    checked before every event; events whose lease ran out meanwhile are
   *    skipped.
   *
   * @param int $limit
   *   Maximum number of events.
   * @param int $timeBudget
   *   Maximum seconds to spend (0: no limit).
   *
   * @return \Drupal\webassistant_connector\Sync\SyncReport
   *   The counters.
   */
  public function process(int $limit, int $timeBudget): SyncReport {
    $report = new SyncReport();
    if (!$this->isEnabled()) {
      return $report;
    }
    if (!$this->credentials->isConfigured()) {
      $report->addError('The platform credentials or API URL are not configured.');
      $report->suspend();
      return $report;
    }
    $start = microtime(TRUE);
    $remaining = max(0, $limit);
    while ($remaining > 0 && !$report->isSuspended() && !$this->budgetExhausted($start, $timeBudget)) {
      $items = $this->outbox->claim(min(self::CLAIM_CHUNK, $remaining));
      if ($items === []) {
        break;
      }
      foreach ($items as $index => $item) {
        if ($report->isSuspended() || $this->budgetExhausted($start, $timeBudget)) {
          foreach (array_slice($items, $index) as $left) {
            $this->outbox->release($left);
            $report->addReleased();
          }
          break 2;
        }
        if ($item->leaseUntil <= $this->time->getCurrentTime()) {
          // The lease ran out while earlier items were processed: another
          // worker may have claimed the row, which is left alone (a later
          // claim takes it if nobody did).
          $report->addReleased();
          continue;
        }
        $this->processItem($item, $report);
        $remaining--;
      }
    }
    if ($report->getProcessed() > 0) {
      $this->logger->info('Outbox run: @processed events, @upserted documents sent, @unchanged unchanged, @deleted deleted, @failed failed, @dead dead.', [
        '@processed' => $report->getProcessed(),
        '@upserted' => $report->getUpserted(),
        '@unchanged' => $report->getUnchanged(),
        '@deleted' => $report->getDeleted(),
        '@failed' => $report->getFailed(),
        '@dead' => $report->getDead(),
      ]);
    }
    return $report;
  }

  /**
   * Processes one claimed event and records its outcome in the report.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   An item in processing status (claimed by this process).
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report to update.
   */
  public function processItem(OutboxItem $item, SyncReport $report): void {
    $report->addProcessed();
    try {
      if ($item->isPseudoSource()) {
        $remote = $item->source === Outbox::SOURCE_ORPHAN
          ? $this->processOrphan($item, $report)
          : $this->processHandled($item);
        $this->finishDone($item, $remote, NULL);
        return;
      }
      $outcome = $this->synchronize($item, $report);
      if ($outcome['rejected'] !== []) {
        $message = $this->rejectionMessage($outcome['rejected']);
        $this->outbox->markDead($item, $message, $outcome['remote'] + ['rejected' => $outcome['rejected']]);
        $report->addDead()->addError($message, $item->id);
        $this->logger->warning('Outbox event @id (@key) rejected by the platform: @message', [
          '@id' => $item->id,
          '@key' => $item->getKey(),
          '@message' => $message,
        ]);
        return;
      }
      $this->finishDone($item, $outcome['remote'], NULL);
    }
    catch (PlatformException $e) {
      $this->handlePlatformError($item, $e, $report);
    }
    catch (\Throwable $e) {
      $this->handleUnexpectedError($item, $e, $report);
    }
    finally {
      $this->resetCaches($item);
    }
  }

  /**
   * Deletes every remote document of this installation (amendment A15).
   *
   * Used by drush webassistant:purge before uninstalling the module or
   * changing the source ID prefix: every mapped document is deleted
   * remotely and from the map, and the outbox is cleared so no pending
   * event sends documents again. Documents whose deletion fails stay in the
   * map; the run stops at the first outage (network error, 5xx, 429, 401).
   *
   * @return array{deleted: int, failed: int, errors: list<string>}
   *   Documents deleted, deletions that failed and their errors (at most
   *   20).
   */
  public function purgeRemote(): array {
    $deleted = 0;
    $failed = 0;
    $errors = [];
    $after = NULL;
    do {
      $rows = $this->documentMap->page($after, 200);
      foreach ($rows as $row) {
        $after = [(string) $row['knowledge_base'], (string) $row['remote_id']];
        try {
          $this->deleteRemote($after[0], $after[1]);
          $this->documentMap->delete($after[0], $after[1]);
          $deleted++;
        }
        catch (PlatformException $e) {
          $failed++;
          if (count($errors) < 20) {
            $errors[] = $after[1] . ': ' . $e->getMessage();
          }
          $status = $e->getStatus();
          if ($status === NULL || $status === 401 || $status === 429 || $status >= 500) {
            break 2;
          }
        }
      }
    } while (count($rows) === 200);
    $this->outbox->clear();
    $this->logger->notice('Purge: @deleted remote documents deleted, @failed failed.', [
      '@deleted' => $deleted,
      '@failed' => $failed,
    ]);
    return ['deleted' => $deleted, 'failed' => $failed, 'errors' => $errors];
  }

  /**
   * Computes the next attempt delay (design 9.3).
   *
   * @param int $attempts
   *   Failed attempts before this one.
   * @param int|null $retryAfter
   *   Seconds from a Retry-After header, if any.
   *
   * @return int
   *   Seconds: Retry-After (bounded) or min(3600, 30 * 2^attempts).
   */
  public static function backoff(int $attempts, ?int $retryAfter = NULL): int {
    if ($retryAfter !== NULL) {
      return max(self::MIN_RETRY_AFTER, min(self::MAX_RETRY_AFTER, $retryAfter));
    }
    return (int) min(self::MAX_BACKOFF, self::BASE_BACKOFF * (2 ** max(0, min($attempts, 20))));
  }

  /**
   * Synchronizes the documents of an entity translation.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   *
   * @return array{remote: array<string, mixed>, rejected: list<array<string, mixed>>}
   *   The remote summary and the rejected documents.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function synchronize(OutboxItem $item, SyncReport $report): array {
    $source = $this->loadSource($item->source);
    $mapped = [];
    foreach ($this->documentMap->find($item->source, $item->entityType, $item->entityId, $item->langcode) as $row) {
      $mapped[$row['knowledge_base'] . '|' . $row['remote_id']] = $row;
    }
    $result = $source === NULL
      ? DocumentBuildResult::skip(DocumentBuildResult::SKIP_NOT_APPLICABLE)
      : $this->documentBuilder->build($source, $item->entityType, $item->entityId, $item->langcode);

    if ($result->isSkipped()) {
      $report->addSkipped();
      $deleted = $this->deleteRows(array_values($mapped), $report);
      return [
        'remote' => ['skip_reason' => (string) $result->getSkipReason(), 'deleted' => $deleted],
        'rejected' => [],
      ];
    }

    $documents = [];
    foreach ($result->getDocuments() as $document) {
      $array = $document->toArray();
      $documents[$document->knowledgeBase . '|' . $document->sourceId] = [
        'document' => $document,
        'array' => $array,
        'hash' => DocumentMap::payloadHash($array),
      ];
    }
    $toSend = array_filter($documents, static fn (array $entry, string $key): bool => !DocumentMap::isUpToDate($mapped[$key] ?? NULL, $entry['hash']), ARRAY_FILTER_USE_BOTH);
    $report->addUnchanged(count($documents) - count($toSend));

    // Attachments are mapped before the ingest request, so the platform can
    // download them as soon as it receives the reference (amendment A14).
    foreach ($toSend as $entry) {
      if ($entry['document']->attachment !== NULL) {
        $this->documentMap->save($this->mapRow($item, $entry['document'], $entry['hash'], DocumentMap::STATUS_SENDING));
      }
    }

    $rejected = [];
    $sent = 0;
    $batchSize = max(1, min(100, (int) ($this->configFactory->get('webassistant_connector.settings')->get('sync.batch_size') ?: 50)));
    foreach (array_chunk($toSend, $batchSize, TRUE) as $batch) {
      $results = [];
      foreach ($this->platformClient->ingest(array_values(array_map(static fn (array $entry): array => $entry['array'], $batch))) as $ingested) {
        $results[($ingested['knowledge_base'] ?? '') . '|' . ($ingested['source_id'] ?? '')] = $ingested;
      }
      foreach ($batch as $key => $entry) {
        $document = $entry['document'];
        if (!isset($results[$key])) {
          throw new PlatformException(
            message: 'The platform did not report the result of a document.',
            errorCode: PlatformException::CODE_INVALID_RESPONSE,
            retryable: TRUE,
          );
        }
        $status = is_string($results[$key]['status'] ?? NULL) ? $results[$key]['status'] : '';
        if ($status === 'rejected') {
          $rejected[] = [
            'knowledge_base' => $document->knowledgeBase,
            'source_id' => $document->sourceId,
            'errors' => self::errorList($results[$key]['errors'] ?? []),
          ];
          if (isset($mapped[$key]) || $document->attachment !== NULL) {
            $this->documentMap->setRemoteStatus($document->knowledgeBase, $document->sourceId, 'rejected');
          }
          continue;
        }
        $this->documentMap->save($this->mapRow($item, $document, $entry['hash'], $status !== '' ? substr($status, 0, 16) : 'queued'));
        $report->addUpserted();
        $sent++;
      }
    }

    // Documents no longer generated (e.g. an attachment was removed).
    $deleted = $this->deleteRows(array_values(array_diff_key($mapped, $documents)), $report);

    return [
      'remote' => [
        'documents' => count($documents),
        'sent' => $sent,
        'unchanged' => count($documents) - count($toSend),
        'deleted' => $deleted,
      ],
      'rejected' => $rejected,
    ];
  }

  /**
   * Processes an orphan: deletes it unless a source produces it (A11).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed orphan row.
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   *
   * @return array<string, mixed>
   *   The remote summary.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function processOrphan(OutboxItem $item, SyncReport $report): array {
    $knowledgeBase = is_string($item->remote['knowledge_base'] ?? NULL) ? $item->remote['knowledge_base'] : $item->entityType;
    $remoteId = is_string($item->remote['remote_id'] ?? NULL) ? $item->remote['remote_id'] : '';
    if ($knowledgeBase === '' || $remoteId === '') {
      return ['skipped' => 'invalid'];
    }
    // Never touch documents of another installation (other prefix).
    if (!$this->sourceIdGenerator->isOwn($remoteId)) {
      return ['skipped' => 'foreign'];
    }
    if ($this->documentMap->load($knowledgeBase, $remoteId) !== NULL) {
      return ['skipped' => 'mapped'];
    }
    $producer = $this->findProducer($knowledgeBase, $remoteId);
    if ($producer !== NULL) {
      $this->outbox->recordKey(OutboxItem::OPERATION_UPSERT, ...$producer);
      return ['recorded' => $producer[0]];
    }
    $this->deleteRemote($knowledgeBase, $remoteId);
    $report->addDeleted();
    return ['deleted' => 1, 'knowledge_base' => $knowledgeBase, 'remote_id' => $remoteId];
  }

  /**
   * Finds the enabled source that currently produces a remote document.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $remoteId
   *   The remote source_id.
   *
   * @return array{0: string, 1: string, 2: string, 3: string}|null
   *   [source ID, entity type, entity ID, langcode], or NULL.
   */
  private function findProducer(string $knowledgeBase, string $remoteId): ?array {
    $parts = $this->sourceIdGenerator->parse($remoteId);
    if ($parts === NULL) {
      return NULL;
    }
    foreach ($this->sourceMatcher->getActiveSources() as $source) {
      if ($source->getTargetEntityTypeId() !== $parts['entity_type']) {
        continue;
      }
      $result = $this->documentBuilder->build($source, $parts['entity_type'], $parts['entity_id'], $parts['langcode']);
      foreach ($result->getDocuments() as $document) {
        if ($document->sourceId === $remoteId && $document->knowledgeBase === $knowledgeBase) {
          return [(string) $source->id(), $parts['entity_type'], $parts['entity_id'], $parts['langcode']];
        }
      }
    }
    return NULL;
  }

  /**
   * Hands a pseudo-source row to its handler.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed row.
   *
   * @return array<string, mixed>
   *   The remote summary.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function processHandled(OutboxItem $item): array {
    foreach ($this->handlers as $handler) {
      if ($handler->applies($item)) {
        return $handler->process($item);
      }
    }
    $this->logger->warning('No handler processes outbox rows of the pseudo-source @source; row @id ignored.', [
      '@source' => $item->source,
      '@id' => $item->id,
    ]);
    return ['skipped' => 'no_handler'];
  }

  /**
   * Deletes mapped documents remotely and from the map.
   *
   * @param list<array<string, mixed>> $rows
   *   Map rows.
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   *
   * @return int
   *   Number of documents deleted.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function deleteRows(array $rows, SyncReport $report): int {
    $count = 0;
    foreach ($rows as $row) {
      $this->deleteRemote((string) $row['knowledge_base'], (string) $row['remote_id']);
      $this->documentMap->delete((string) $row['knowledge_base'], (string) $row['remote_id']);
      $report->addDeleted();
      $count++;
    }
    return $count;
  }

  /**
   * Deletes a remote document (unknown documents or bases are a success).
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $remoteId
   *   The remote source_id.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function deleteRemote(string $knowledgeBase, string $remoteId): void {
    try {
      $this->platformClient->deleteDocument($knowledgeBase, $remoteId);
    }
    catch (PlatformException $e) {
      if ($e->getStatus() !== 404) {
        throw $e;
      }
    }
  }

  /**
   * Builds a map row for a document.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The outbox item.
   * @param \Drupal\webassistant_connector\Normalizer\CanonicalDocument $document
   *   The document.
   * @param string $payloadHash
   *   The payload hash.
   * @param string $remoteStatus
   *   The remote status.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function mapRow(OutboxItem $item, CanonicalDocument $document, string $payloadHash, string $remoteStatus): array {
    return [
      'knowledge_base' => $document->knowledgeBase,
      'remote_id' => $document->sourceId,
      'source' => $item->source,
      'entity_type' => $item->entityType,
      'entity_id' => $item->entityId,
      'langcode' => $item->langcode,
      'attachment' => $document->attachment,
      'version' => mb_substr($document->version, 0, 64),
      'content_hash' => (string) $document->contentHash,
      'payload_hash' => $payloadHash,
      'acl' => $document->acl,
      'remote_status' => $remoteStatus,
      'synced' => $this->time->getCurrentTime(),
    ];
  }

  /**
   * Marks an item done and records the last success.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param array<string, mixed> $remote
   *   The remote summary.
   * @param string|null $correlationId
   *   The correlation ID, if any.
   */
  private function finishDone(OutboxItem $item, array $remote, ?string $correlationId): void {
    if ($this->outbox->markDone($item, $remote, $correlationId)) {
      $now = $this->time->getCurrentTime();
      $this->state->set(self::STATE_LAST_SUCCESS, $now);
      $this->logger->debug('Outbox event @id (@key) delivered in @latency s.', [
        '@id' => $item->id,
        '@key' => $item->getKey(),
        '@latency' => max(0, $now - $item->created),
      ]);
    }
  }

  /**
   * Records a platform error: failed with backoff or dead.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param \Drupal\webassistant_connector\Platform\PlatformException $e
   *   The error.
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   */
  private function handlePlatformError(OutboxItem $item, PlatformException $e, SyncReport $report): void {
    $correlationId = $e->getCorrelationId();
    $message = $e->getMessage();
    // A 401 after the client renewed its token, or missing configuration,
    // affects every event and is not the event's fault: retry later without
    // counting the attempt, so fixing the credentials resumes delivery
    // without a manual retry of the dead letter.
    $global = $e->getStatus() === 401 || $e->getErrorCode() === PlatformException::CODE_NOT_CONFIGURED;
    $outage = $global || $e->getStatus() === 429 || ($e->getStatus() ?? 0) >= 500 || ($e->getStatus() === NULL && $e->getErrorCode() === PlatformException::CODE_NETWORK_ERROR);
    $delay = self::backoff($item->attempts, $e->getRetryAfter());
    if ($global) {
      $this->outbox->markFailed($item, $message, $this->time->getCurrentTime() + $delay, $correlationId, FALSE);
      $report->addFailed();
      $level = 'error';
    }
    elseif ($e->isRetryable() && $item->attempts + 1 < $this->maxAttempts()) {
      $this->outbox->markFailed($item, $message, $this->time->getCurrentTime() + $delay, $correlationId);
      $report->addFailed();
      $level = 'warning';
    }
    else {
      $this->outbox->markDead($item, $message, $e->getErrors() !== [] ? ['errors' => self::errorList($e->getErrors())] : [], $correlationId);
      $report->addDead();
      $level = 'error';
    }
    $report->addError($message, $item->id, $correlationId);
    $this->logger->log($level, 'Outbox event @id (@key) failed: @message (correlation ID: @correlation).', [
      '@id' => $item->id,
      '@key' => $item->getKey(),
      '@message' => $message,
      '@correlation' => $correlationId ?? '-',
    ]);
    if ($outage) {
      $report->suspend($delay);
    }
  }

  /**
   * Records an unexpected error: failed with backoff, dead at max attempts.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed item.
   * @param \Throwable $e
   *   The error.
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   */
  private function handleUnexpectedError(OutboxItem $item, \Throwable $e, SyncReport $report): void {
    $class = (new \ReflectionClass($e))->getShortName();
    $message = sprintf('Unexpected error (%s): %s', $class, mb_substr($e->getMessage(), 0, 500));
    try {
      if ($item->attempts + 1 < $this->maxAttempts()) {
        $this->outbox->markFailed($item, $message, $this->time->getCurrentTime() + self::backoff($item->attempts));
        $report->addFailed();
      }
      else {
        $this->outbox->markDead($item, $message);
        $report->addDead();
      }
    }
    catch (\Throwable $inner) {
      // The lease expires and the row is claimed again later.
      $this->logger->error('Could not record the failure of outbox event @id: @message', [
        '@id' => $item->id,
        '@message' => $inner->getMessage(),
      ]);
    }
    $report->addError($message, $item->id);
    $this->logger->error('Outbox event @id (@key) failed: @message', [
      '@id' => $item->id,
      '@key' => $item->getKey(),
      '@message' => $message,
      'exception' => $e,
    ]);
  }

  /**
   * Loads a source config entity.
   *
   * @param string $id
   *   The source ID.
   *
   * @return \Drupal\webassistant_connector\Entity\SourceInterface|null
   *   The source (enabled or not), or NULL if it does not exist.
   */
  private function loadSource(string $id): ?SourceInterface {
    $source = $this->entityTypeManager->getStorage('webassistant_source')->load($id);
    return $source instanceof SourceInterface ? $source : NULL;
  }

  /**
   * Resets the static caches of the processed entity (amendment A13).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The item.
   */
  private function resetCaches(OutboxItem $item): void {
    if ($item->isPseudoSource() || !$this->entityTypeManager->hasDefinition($item->entityType)) {
      return;
    }
    try {
      $this->entityTypeManager->getStorage($item->entityType)->resetCache([$item->entityId]);
    }
    catch (\Throwable) {
      // Nothing to reset.
    }
  }

  /**
   * Returns sync.max_attempts (at least 1).
   */
  private function maxAttempts(): int {
    return max(1, (int) ($this->configFactory->get('webassistant_connector.settings')->get('sync.max_attempts') ?: 8));
  }

  /**
   * Whether the time budget is spent.
   *
   * @param float $start
   *   The start time (microtime).
   * @param int $timeBudget
   *   The budget in seconds (0: none).
   *
   * @return bool
   *   TRUE when spent.
   */
  private function budgetExhausted(float $start, int $timeBudget): bool {
    return $timeBudget > 0 && (microtime(TRUE) - $start) >= $timeBudget;
  }

  /**
   * Summarizes rejections for last_error.
   *
   * @param list<array<string, mixed>> $rejected
   *   The rejected documents.
   *
   * @return string
   *   The message.
   */
  private function rejectionMessage(array $rejected): string {
    $parts = [];
    foreach (array_slice($rejected, 0, 3) as $entry) {
      $errors = [];
      foreach (array_slice($entry['errors'], 0, 3) as $error) {
        $errors[] = trim(($error['path'] ?? '') . ' ' . ($error['message'] ?? ''));
      }
      $parts[] = $entry['source_id'] . ($errors !== [] ? ' (' . implode('; ', $errors) . ')' : '');
    }
    return 'Rejected by the platform: ' . implode(', ', $parts);
  }

  /**
   * Normalizes a problem+json errors list (path/message strings, max 10).
   *
   * @param mixed $errors
   *   The raw list.
   *
   * @return list<array{path: string, message: string}>
   *   The errors.
   */
  private static function errorList(mixed $errors): array {
    $list = [];
    foreach (is_array($errors) ? array_slice(array_values($errors), 0, 10) : [] as $error) {
      if (is_array($error)) {
        $list[] = [
          'path' => mb_substr(is_scalar($error['path'] ?? NULL) ? (string) $error['path'] : '', 0, 200),
          'message' => mb_substr(is_scalar($error['message'] ?? NULL) ? (string) $error['message'] : '', 0, 300),
        ];
      }
    }
    return $list;
  }

}
