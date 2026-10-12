<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\SyncProcessor;

/**
 * Processes the outbox (design section 9.5, amendment A5).
 *
 * Items are tickets (their data is ignored): each run calls
 * SyncProcessor::process(sync.process_limit, TIME_BUDGET). When the platform
 * is unavailable the queue is suspended; when due events remain the ticket
 * is requeued so cron (or drush queue:run) keeps going while its time
 * allows.
 */
#[QueueWorker(
  id: 'webassistant_connector_dispatch',
  title: new TranslatableMarkup('WebAssistant outbox dispatch'),
  cron: ['time' => 60],
)]
final class DispatchWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

  /**
   * Seconds a run may spend (below the cron lease of 60 seconds).
   */
  public const TIME_BUDGET = 45;

  /**
   * Constructs a DispatchWorker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\webassistant_connector\Sync\SyncProcessor $syncProcessor
   *   The sync processor.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected SyncProcessor $syncProcessor,
    protected ConfigFactoryInterface $configFactory,
    protected Outbox $outbox,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // While processing is disabled the ticket is consumed; cron adds a new
    // one once it is enabled again and events are due.
    if (!$this->syncProcessor->isEnabled()) {
      return;
    }
    $limit = max(1, (int) ($this->configFactory->get('webassistant_connector.settings')->get('sync.process_limit') ?: 200));
    $report = $this->syncProcessor->process($limit, self::TIME_BUDGET);
    if ($report->isSuspended()) {
      $delay = $report->getSuspendDelay();
      throw new SuspendQueueException('The WebAssistant platform is unavailable: outbox processing is suspended.', 0, NULL, $delay === NULL ? NULL : (float) $delay);
    }
    if ($this->outbox->hasDue()) {
      throw new RequeueException('Due outbox events remain.');
    }
  }

}
