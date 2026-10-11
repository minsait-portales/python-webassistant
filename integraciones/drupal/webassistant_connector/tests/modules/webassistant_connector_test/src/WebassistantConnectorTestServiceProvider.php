<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces the platform client with FakePlatformClient (no network).
 */
final class WebassistantConnectorTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('webassistant_connector.platform_client')) {
      return;
    }
    $container->getDefinition('webassistant_connector.platform_client')
      ->setClass(FakePlatformClient::class)
      ->setAutowired(FALSE)
      ->setArguments([new Reference('state'), new Reference('datetime.time')]);
  }

}
