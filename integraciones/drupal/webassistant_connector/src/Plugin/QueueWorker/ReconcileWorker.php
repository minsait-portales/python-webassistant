<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SyncProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reconciles one knowledge base (design section 9.5, amendment A11).
 *
 * Item data: ['knowledge_base' => string]. A run spends at most TIME_BUDGET
 * seconds; unfinished runs keep their cursor in state and the item is
 * requeued. Retryable platform errors delay the item; other errors discard
 * the run (it starts again on the next schedule).
 */
#[QueueWorker(
  id: 'webassistant_connector_reconcile',
  title: new TranslatableMarkup('WebAssistant reconciliation'),
  cron: ['time' => 120],
)]
final class ReconcileWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

  /**
   * Seconds a run may spend (below the cron lease of 120 seconds).
   */
  public const TIME_BUDGET = 90;

  /**
   * Constructs a ReconcileWorker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\webassistant_connector\Sync\Reconciler $reconciler
   *   The reconciler.
   * @param \Drupal\webassistant_connector\Sync\SyncProcessor $syncProcessor
   *   The sync processor (enabled state).
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Reconciler $reconciler,
    protected SyncProcessor $syncProcessor,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $knowledgeBase = is_array($data) && is_string($data['knowledge_base'] ?? NULL) ? $data['knowledge_base'] : '';
    if (!preg_match('/^[a-z][a-z0-9_-]{1,62}$/', $knowledgeBase) || !$this->syncProcessor->isEnabled()) {
      return;
    }
    try {
      $result = $this->reconciler->step($knowledgeBase, self::TIME_BUDGET);
    }
    catch (PlatformException $e) {
      $this->logger->warning('Reconciliation of @kb failed: @message (correlation ID: @correlation).', [
        '@kb' => $knowledgeBase,
        '@message' => $e->getMessage(),
        '@correlation' => $e->getCorrelationId() ?? '-',
      ]);
      if ($e->isRetryable()) {
        throw new DelayedRequeueException(SyncProcessor::backoff(3, $e->getRetryAfter()), 'Platform unavailable.');
      }
      return;
    }
    if (!$result['finished']) {
      throw new RequeueException('Reconciliation not finished yet.');
    }
  }

}
