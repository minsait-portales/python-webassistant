<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;
use Drupal\webassistant_connector\Sync\Indexer;

/**
 * Reindexes sources in the background (amendments A9 and A12).
 *
 * Item data: a job built by Indexer::scheduleReindex(). Each item records
 * the events of one page and queues the next page; a newer job for the same
 * source supersedes the pending one.
 */
#[QueueWorker(
  id: 'webassistant_connector_index',
  title: new TranslatableMarkup('WebAssistant source reindexing'),
  cron: ['time' => 60],
)]
final class IndexWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

  /**
   * Constructs an IndexWorker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\webassistant_connector\Sync\Indexer $indexer
   *   The indexer.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Indexer $indexer,
    protected QueueFactory $queueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      return;
    }
    $next = $this->indexer->processJob($data);
    if ($next !== NULL) {
      $this->queueFactory->get(Indexer::INDEX_QUEUE)->createItem($next);
    }
  }

}
