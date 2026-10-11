<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Installs the connector and checks its contracts are wired.
 *
 * @group webassistant_connector
 */
#[RunTestsInSeparateProcesses]
final class ModuleInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'webassistant_connector'];

  /**
   * Tables, default configuration, plugins and routes are available.
   */
  public function testInstall(): void {
    $this->installConfig(['webassistant_connector']);
    $this->installSchema('webassistant_connector', [
      'webassistant_connector_outbox',
      'webassistant_connector_document',
    ]);
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('webassistant_connector_outbox'));
    $this->assertTrue($schema->tableExists('webassistant_connector_document'));

    $config = $this->config('webassistant_connector.settings');
    $this->assertTrue($config->get('enabled'));
    $this->assertContains('knowledge:read', $config->get('identity.base_scopes'));

    $hostActions = $this->container->get('plugin.manager.webassistant_connector.host_action')->getDefinitions();
    $this->assertArrayHasKey('content.create', $hostActions);
    $this->assertArrayHasKey('user.profile', $hostActions);
    $normalizers = $this->container->get('plugin.manager.webassistant_connector.normalizer')->getDefinitions();
    $this->assertArrayHasKey('content_entity', $normalizers);

    $routes = $this->container->get('router.route_provider');
    $this->assertSame('/webassistant/token', $routes->getRouteByName('webassistant_connector.token')->getPath());
    $this->assertNotNull($routes->getRouteByName('entity.webassistant_source.collection'));
    $this->assertNotNull($routes->getRouteByName('entity.webassistant_source.preview_form'));
  }

}
