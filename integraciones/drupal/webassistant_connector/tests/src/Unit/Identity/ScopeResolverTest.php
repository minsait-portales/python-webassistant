<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\Identity;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\PermissionHandlerInterface;
use Drupal\webassistant_connector\Identity\ScopeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the scopes granted to widget tokens.
 *
 * @group webassistant_connector
 */
#[CoversClass(ScopeResolver::class)]
#[Group('webassistant_connector')]
final class ScopeResolverTest extends UnitTestCase {

  /**
   * Builds a resolver with the default-like configuration.
   *
   * @return \Drupal\webassistant_connector\Identity\ScopeResolver
   *   The resolver.
   */
  private function resolver(): ScopeResolver {
    $configFactory = $this->getConfigFactoryStub([
      'webassistant_connector.settings' => [
        'identity' => [
          'base_scopes' => ['knowledge:read', 'chat:use', 'chat:use', 'Not a scope'],
          'scope_permissions' => [
            ['scope' => 'catalog:read', 'permission' => 'access content'],
            ['scope' => 'cart:read', 'permission' => 'use webassistant cart actions'],
            ['scope' => 'content:write', 'permission' => 'use webassistant content actions'],
            ['scope' => 'bad scope', 'permission' => 'access content'],
          ],
        ],
      ],
    ]);
    $permissionHandler = $this->createMock(PermissionHandlerInterface::class);
    $permissionHandler->expects($this->once())->method('getPermissions')->willReturn([
      'access content' => ['title' => 'Access content', 'provider' => 'node'],
      'use webassistant content actions' => ['title' => 'Content actions', 'provider' => 'webassistant_connector'],
    ]);
    return new ScopeResolver($configFactory, $permissionHandler);
  }

  /**
   * Builds an account that has the given permissions.
   *
   * @param list<string>|null $permissions
   *   The permissions, or NULL for an administrator (every permission).
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  private function account(?array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => $permissions === NULL || in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

  /**
   * Base scopes plus the scopes of granted, existing permissions.
   */
  public function testResolve(): void {
    $resolver = $this->resolver();
    $this->assertSame(
      ['catalog:read', 'chat:use', 'knowledge:read'],
      $resolver->resolve($this->account(['access content'])),
    );
    $this->assertSame(
      ['chat:use', 'knowledge:read'],
      $resolver->resolve($this->account([])),
    );
  }

  /**
   * Undefined permissions grant nothing, even to administrators.
   */
  public function testUnknownPermissionGrantsNothing(): void {
    $this->assertSame(
      ['catalog:read', 'chat:use', 'content:write', 'knowledge:read'],
      $this->resolver()->resolve($this->account(NULL)),
    );
  }

  /**
   * Scope validation.
   */
  public function testIsValidScope(): void {
    $this->assertTrue(ScopeResolver::isValidScope('actions:execute'));
    $this->assertTrue(ScopeResolver::isValidScope('ingest:*'));
    $this->assertFalse(ScopeResolver::isValidScope('chat'));
    $this->assertFalse(ScopeResolver::isValidScope('Chat:use'));
    $this->assertFalse(ScopeResolver::isValidScope(['chat:use']));
  }

}
