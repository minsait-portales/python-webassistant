<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_sync_test;

use Drupal\webassistant_connector\Sync\OutboxHandlerInterface;
use Drupal\webassistant_connector\Sync\OutboxItem;

/**
 * Handles rows of the "_test" pseudo-source.
 */
final class TestOutboxHandler implements OutboxHandlerInterface {

  /**
   * The rows processed.
   *
   * @var list<string>
   */
  public static array $processed = [];

  /**
   * {@inheritdoc}
   */
  public function applies(OutboxItem $item): bool {
    return $item->source === '_test';
  }

  /**
   * {@inheritdoc}
   */
  public function process(OutboxItem $item): array {
    self::$processed[] = $item->getKey();
    return ['handled' => TRUE];
  }

}
