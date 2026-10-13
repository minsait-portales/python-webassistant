<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\HostAction;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Drupal\webassistant_connector\HostAction\HostActionRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Registry of available host actions; content actions without node.
 *
 * @group webassistant_connector
 */
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class HostActionRegistryTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The node module is deliberately not installed.
   */
  protected static $modules = ['system', 'user', 'webassistant_connector'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user', 'webassistant_connector']);
    $this->container->get('router.builder')->rebuild();
    // User 1 must not be one of the users under test.
    $this->createUser();
  }

  /**
   * Only actions whose access is allowed are listed, with cacheability.
   */
  public function testAvailableFor(): void {
    /** @var \Drupal\webassistant_connector\HostAction\HostActionRegistry $registry */
    $registry = $this->container->get('webassistant_connector.host_action_registry');

    $full = $this->createUser([
      'use webassistant connector',
      'use webassistant content actions',
      'use webassistant profile actions',
    ]);
    $this->assertInstanceOf(UserInterface::class, $full);
    // Content actions are denied: the node module is missing.
    $this->assertSame(['user.profile'], $registry->availableFor($full));
    $available = $registry->availableForWithCacheability($full);
    $this->assertSame(['user.profile'], $available['names']);
    $this->assertContains('user.permissions', $available['cacheability']->getCacheContexts());
    $this->assertContains('webassistant_connector_host_action_plugins', $available['cacheability']->getCacheTags());
    // Clearing the plugin definitions (e.g. a module adds a host action)
    // invalidates the pages that list them.
    $checksum = $this->container->get('cache_tags.invalidator.checksum');
    $before = $checksum->getCurrentChecksum(['webassistant_connector_host_action_plugins']);
    $this->container->get('plugin.manager.webassistant_connector.host_action')->clearCachedDefinitions();
    $this->assertNotSame($before, $checksum->getCurrentChecksum(['webassistant_connector_host_action_plugins']));

    // Without the endpoint permission nothing is offered.
    $noEndpoint = $this->createUser(['use webassistant profile actions', 'use webassistant content actions']);
    $this->assertInstanceOf(UserInterface::class, $noEndpoint);
    $this->assertSame([], $registry->availableFor($noEndpoint));
    $this->assertContains('user.permissions', $registry->availableForWithCacheability($noEndpoint)['cacheability']->getCacheContexts());

    // Without the action permission the action is not offered.
    $endpointOnly = $this->createUser(['use webassistant connector']);
    $this->assertInstanceOf(UserInterface::class, $endpointOnly);
    $this->assertSame([], $registry->availableFor($endpointOnly));
  }

  /**
   * Content actions are denied cleanly when the node module is missing.
   */
  public function testContentActionsWithoutNode(): void {
    $user = $this->createUser(['use webassistant connector', 'use webassistant content actions']);
    $this->assertInstanceOf(UserInterface::class, $user);
    $this->setCurrentUser($user);
    $request = Request::create('http://localhost/webassistant/host-action/content.create', 'POST');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('webassistant_connector.host_action_dispatcher')->dispatch('content.create', [
      'version' => '1',
      'name' => 'content.create',
      'execution_id' => 'act_1',
      'correlation_id' => 'act_1',
      'params' => ['content_type' => 'article'],
    ], $user, $request);
  }

  /**
   * The registry constant is the endpoint permission.
   */
  public function testPermissionConstant(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey(HostActionRegistry::PERMISSION, $permissions);
  }

}
