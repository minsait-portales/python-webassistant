<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;
use Drupal\webassistant_connector\Sync\Reconciler;

/**
 * Reconciles one knowledge base (design section 9.5).
 *
 * Item data: ['knowledge_base' => string].
 */
#[QueueWorker(
  id: 'webassistant_connector_reconcile',
  title: new TranslatableMarkup('WebAssistant reconciliation'),
  cron: ['time' => 120],
)]
final class ReconcileWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Reconciler $reconciler,
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
