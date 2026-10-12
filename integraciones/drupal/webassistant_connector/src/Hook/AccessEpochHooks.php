<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Sync\AccessEpoch;

/**
 * Recomputes the access epoch when access rules may change (A12, E5).
 *
 * Role changes (permissions) and module (un)installation can change who may
 * view indexed entities; AccessEpoch reindexes the access-controlled sources
 * when the epoch changes. Cron checks it too (SyncCronHooks), which covers
 * node access rebuilds.
 */
final class AccessEpochHooks {

  /**
   * Constructs an AccessEpochHooks object.
   *
   * @param \Drupal\webassistant_connector\Sync\AccessEpoch $accessEpoch
   *   The access epoch.
   */
  public function __construct(
    private readonly AccessEpoch $accessEpoch,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for user_role.
   */
  #[Hook('user_role_insert')]
  public function roleInsert(): void {
    $this->accessEpoch->check();
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user_role.
   */
  #[Hook('user_role_update')]
  public function roleUpdate(): void {
    $this->accessEpoch->check();
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user_role.
   */
  #[Hook('user_role_delete')]
  public function roleDelete(): void {
    $this->accessEpoch->check();
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(): void {
    $this->accessEpoch->check();
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  #[Hook('modules_uninstalled')]
  public function modulesUninstalled(): void {
    $this->accessEpoch->check();
  }

}
