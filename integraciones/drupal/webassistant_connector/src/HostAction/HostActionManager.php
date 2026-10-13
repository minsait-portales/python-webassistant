<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\webassistant_connector\Attribute\HostAction;

/**
 * Plugin manager of host actions (design section 7).
 *
 * Service: plugin.manager.webassistant_connector.host_action. Plugins live in
 * Plugin/WebAssistant/HostAction and use the #[HostAction] attribute.
 * Definitions can be altered with hook_webassistant_host_action_info_alter().
 *
 * @method \Drupal\webassistant_connector\HostAction\HostActionInterface createInstance($plugin_id, array $configuration = [])
 */
final class HostActionManager extends DefaultPluginManager {

  /**
   * Constructs a HostActionManager.
   *
   * @param \Traversable<string, string> $namespaces
   *   The namespaces to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/WebAssistant/HostAction', $namespaces, $module_handler, HostActionInterface::class, HostAction::class);
    $this->alterInfo('webassistant_host_action_info');
    // The tag also marks the pages whose widget lists the host actions
    // (HostActionRegistry): clearing the definitions invalidates them.
    $this->setCacheBackend($cache_backend, 'webassistant_connector_host_action_plugins', ['webassistant_connector_host_action_plugins']);
  }

}
