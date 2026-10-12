<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_source_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Access rules used by the source tests.
 *
 * - Nodes whose title starts with "Restricted" can only be viewed by users
 *   with the "reviewer" role.
 * - The field field_secret can only be viewed by users with the "reviewer"
 *   role.
 */
final class SourceTestHooks {

  /**
   * Role that can view restricted content.
   */
  public const REVIEWER = 'reviewer';

  /**
   * Implements hook_ENTITY_TYPE_access() for nodes.
   */
  #[Hook('node_access')]
  public function nodeAccess(NodeInterface $node, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view' || !str_starts_with((string) $node->getTitle(), 'Restricted')) {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!in_array(self::REVIEWER, $account->getRoles(), TRUE))->cachePerPermissions();
  }

  /**
   * Implements hook_entity_field_access().
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($operation !== 'view' || $field_definition->getName() !== 'field_secret') {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!in_array(self::REVIEWER, $account->getRoles(), TRUE))->cachePerPermissions();
  }

}
