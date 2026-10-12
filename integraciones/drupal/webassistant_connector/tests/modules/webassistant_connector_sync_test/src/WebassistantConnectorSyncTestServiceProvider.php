<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_sync_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Replaces the platform client with ScriptedPlatformClient (no network).
 */
final class WebassistantConnectorSyncTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('webassistant_connector.platform_client')) {
      return;
    }
    $container->getDefinition('webassistant_connector.platform_client')
      ->setClass(ScriptedPlatformClient::class)
      ->setAutowired(FALSE)
      ->setArguments([]);
  }

}
