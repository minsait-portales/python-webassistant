<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_sync_test\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Sync\Outbox;

/**
 * Makes entity saves fail after the connector recorded its capture.
 */
final class SyncTestHooks {

  /**
   * When TRUE, inserting a node throws after the capture.
   */
  public static bool $failNodeInsert = FALSE;

  /**
   * Outbox rows seen inside the failing transaction.
   */
  public static int $rowsSeen = 0;

  /**
   * Constructs a SyncTestHooks object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Implements hook_entity_bundle_info_alter().
   *
   * Makes articles translatable without the content_translation module.
   */
  #[Hook('entity_bundle_info_alter')]
  public function entityBundleInfoAlter(array &$bundles): void {
    if (isset($bundles['node']['article'])) {
      $bundles['node']['article']['translatable'] = TRUE;
    }
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if (!self::$failNodeInsert || $entity->getEntityTypeId() !== 'node') {
      return;
    }
    self::$rowsSeen = (int) $this->database->select(Outbox::TABLE)->countQuery()->execute()->fetchField();
    throw new \RuntimeException('Simulated failure after the capture.');
  }

}
