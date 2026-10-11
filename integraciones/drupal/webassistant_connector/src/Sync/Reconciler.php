<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reconciles the remote inventory with Drupal (design section 9.4).
 *
 * Service: webassistant_connector.reconciler.
 */
final class Reconciler {

  /**
   * The queue that runs reconciliations (one item per knowledge base).
   */
  public const RECONCILE_QUEUE = 'webassistant_connector_reconcile';

  /**
   * State key: last reconciliation timestamp keyed by knowledge base.
   */
  public const STATE_LAST_RUN = 'webassistant_connector.reconcile_last_run';

  /**
   * Constructs a Reconciler.
   *
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator.
   * @param \Drupal\webassistant_connector\Sync\DocumentBuilder $documentBuilder
   *   The document builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly PlatformClientInterface $platformClient,
    private readonly DocumentMap $documentMap,
    private readonly Outbox $outbox,
    private readonly SourceMatcher $sourceMatcher,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly DocumentBuilder $documentBuilder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reconciles one knowledge base.
   *
   * Walks the remote inventory (cursor) and only considers source IDs with
   * the own prefix. Orphans (no indexable entity/translation): delete.
   * Different version, different ACL or remote status failed: upsert.
   * Local translations without a remote document: upsert.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param bool $dryRun
   *   Report only, record nothing.
   *
   * @return array{knowledge_base: string, dry_run: bool, remote: int, local: int, orphans: list<string>, outdated: list<string>, missing: list<string>, recorded: int, errors: list<string>}
   *   The report: counts of remote and local documents, source IDs found in
   *   each category, events recorded (0 in dry run) and errors.
   */
  public function reconcile(string $knowledgeBase, bool $dryRun = FALSE): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * Enqueues the knowledge bases whose reconcile_interval has elapsed.
   *
   * Called from cron; does nothing when sync.reconcile_interval is 0.
   *
   * @return int
   *   Number of knowledge bases enqueued in RECONCILE_QUEUE.
   */
  public function enqueueDue(): int {
    throw new \LogicException('Not implemented yet.');
  }

}
