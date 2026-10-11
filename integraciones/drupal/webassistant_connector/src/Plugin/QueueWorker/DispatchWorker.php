<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;
use Drupal\webassistant_connector\Sync\SyncProcessor;

/**
 * Processes the outbox (design section 9.5).
 *
 * Items are tickets (their data is ignored): each run calls
 * SyncProcessor::process(sync.process_limit, time budget).
 */
#[QueueWorker(
  id: 'webassistant_connector_dispatch',
  title: new TranslatableMarkup('WebAssistant outbox dispatch'),
  cron: ['time' => 60],
)]
final class DispatchWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SyncProcessor $syncProcessor,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    throw new \LogicException('Not implemented yet.');
  }

}
