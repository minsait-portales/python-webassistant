<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\webassistant_connector\Entity\Source;
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
    $this->assertSame('/admin/config/services/webassistant-connector/sources', $routes->getRouteByName('entity.webassistant_source.collection')->getPath());
    $this->assertSame('/admin/config/services/webassistant-connector/sources/{webassistant_source}/preview', $routes->getRouteByName('entity.webassistant_source.preview_form')->getPath());
  }

  /**
   * Uninstalling leaves no queue item nor state entry behind.
   *
   * Core deletes the sources and updates the roles after hook_uninstall():
   * their hooks must not schedule work again.
   */
  public function testUninstallLeavesNothing(): void {
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user', 'webassistant_connector']);
    $this->installSchema('webassistant_connector', [
      'webassistant_connector_outbox',
      'webassistant_connector_document',
    ]);
    Source::create([
      'id' => 'people',
      'label' => 'People',
      'status' => TRUE,
      'entity_type' => 'user',
      'knowledge_base' => 'help',
      'document_type' => 'page',
      'acl_mode' => 'public',
    ])->save();
    Role::create(['id' => 'member', 'label' => 'Member'])->grantPermission('use webassistant connector')->save();
    $this->assertGreaterThan(0, $this->container->get('queue')->get('webassistant_connector_index')->numberOfItems());

    $this->container->get('module_installer')->uninstall(['webassistant_connector']);

    foreach (['webassistant_connector_dispatch', 'webassistant_connector_reconcile', 'webassistant_connector_index'] as $queue) {
      $this->assertSame(0, $this->container->get('queue')->get($queue)->numberOfItems(), $queue);
    }
    $state = array_filter(
      array_keys($this->container->get('keyvalue')->get('state')->getAll()),
      static fn (string $name): bool => str_starts_with($name, 'webassistant_connector.'),
    );
    $this->assertSame([], array_values($state));
    $this->assertSame([], Role::load('member')->getPermissions());
  }

}
