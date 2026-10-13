<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Tells the other hooks when the connector itself is being uninstalled.
 *
 * Core removes the configuration of a module after hook_uninstall(): the
 * deleted sources and the roles losing the connector permissions would
 * schedule reindexing (queue items, state) after the cleanup of
 * webassistant_connector_uninstall(). Those hooks do nothing meanwhile.
 */
final class UninstallHooks {

  /**
   * The connector being uninstalled.
   */
  private const MODULE = 'webassistant_connector';

  /**
   * Whether the connector is being uninstalled in this process.
   */
  private static bool $uninstalling = FALSE;

  /**
   * Implements hook_module_preuninstall().
   */
  #[Hook('module_preuninstall')]
  public function modulePreuninstall(string $module): void {
    if ($module === self::MODULE) {
      self::$uninstalling = TRUE;
    }
  }

  /**
   * Implements hook_modules_installed().
   *
   * @param list<string> $modules
   *   The installed modules.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules): void {
    if (in_array(self::MODULE, $modules, TRUE)) {
      // Installed again in the same process (e.g. a long Drush run).
      self::$uninstalling = FALSE;
    }
  }

  /**
   * Whether the connector is being uninstalled in this process.
   */
  public static function isUninstalling(): bool {
    return self::$uninstalling;
  }

}
