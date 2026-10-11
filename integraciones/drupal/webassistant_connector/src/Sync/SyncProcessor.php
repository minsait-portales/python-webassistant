<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Processes the outbox (design section 9.3). Used by the queue, cron, Drush
 * and the status page.
 *
 * Service: webassistant_connector.sync_processor.
 */
final class SyncProcessor {

  /**
   * Name of the lock held while claiming events.
   */
  public const LOCK_NAME = 'webassistant_connector.sync';

  /**
   * State key with the timestamp of the last successful delivery.
   */
  public const STATE_LAST_SUCCESS = 'webassistant_connector.last_success';

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (loads sources).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly Outbox $outbox,
    private readonly DocumentMap $documentMap,
    private readonly DocumentBuilder $documentBuilder,
    private readonly PlatformClientInterface $platformClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Processes due outbox events.
   *
   * 1. Claims due pending/failed events under a lock.
   * 2. upsert: DocumentBuilder::build(); a skipReason becomes a delete of the
   *    mapped documents; otherwise documents are sent in batches of at most
   *    sync.batch_size and mapped documents no longer generated are deleted.
   * 3. delete: DELETE each mapped document (404/unchanged is success) and
   *    remove the map rows.
   * 4. Per item: queued/ready/... updates the map and marks the event done;
   *    rejected marks it dead; a retryable PlatformException marks it failed
   *    with next_attempt = now + min(3600, 30 * 2^attempts) or Retry-After;
   *    non-retryable (401/403/422) or attempts >= sync.max_attempts: dead.
   * 5. Logs latency (changed - created) and correlation IDs.
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
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Processes one claimed event and records its outcome in the report.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   An item in processing status.
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report to update.
   */
  public function processItem(OutboxItem $item, SyncReport $report): void {
    throw new \LogicException('Not implemented yet.');
  }

}
