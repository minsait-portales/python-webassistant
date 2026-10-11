<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Identity;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\PermissionHandlerInterface;

/**
 * Resolves the widget token scopes of an account (design section 5).
 *
 * Service: webassistant_connector.scope_resolver.
 */
final class ScopeResolver {

  /**
   * Pattern of a platform scope (e.g. chat:use, cart:write).
   */
  public const SCOPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}:[a-z0-9_*]{1,31}$/';

  /**
   * Maximum number of scopes accepted by the platform.
   */
  public const MAX_SCOPES = 50;

  /**
   * Permissions defined on the site, keyed by name (memoized per request).
   *
   * @var array<string, mixed>|null
   */
  private ?array $permissions = NULL;

  /**
   * Constructs a ScopeResolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\user\PermissionHandlerInterface $permissionHandler
   *   The permission handler (unknown permissions grant nothing).
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PermissionHandlerInterface $permissionHandler,
  ) {}

  /**
   * Resolves the scopes of an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return list<string>
   *   identity.base_scopes plus the scopes of identity.scope_permissions whose
   *   permission exists and is granted, sorted and unique. Invalid scope
   *   strings are ignored.
   */
  public function resolve(AccountInterface $account): array {
    $identity = $this->configFactory->get('webassistant_connector.settings')->get('identity') ?? [];
    $scopes = [];
    foreach ((array) ($identity['base_scopes'] ?? []) as $scope) {
      if (is_string($scope) && self::isValidScope($scope)) {
        $scopes[$scope] = TRUE;
      }
    }
    foreach ((array) ($identity['scope_permissions'] ?? []) as $mapping) {
      $scope = is_array($mapping) ? ($mapping['scope'] ?? NULL) : NULL;
      $permission = is_array($mapping) ? ($mapping['permission'] ?? NULL) : NULL;
      if (!is_string($scope) || !self::isValidScope($scope) || !is_string($permission) || isset($scopes[$scope])) {
        continue;
      }
      // An administrator role "has" every permission, even those that do not
      // exist: only defined permissions may grant a scope.
      if ($this->permissionExists($permission) && $account->hasPermission($permission)) {
        $scopes[$scope] = TRUE;
      }
    }
    $scopes = array_map('strval', array_keys($scopes));
    sort($scopes);
    return array_slice($scopes, 0, self::MAX_SCOPES);
  }

  /**
   * Whether a value is a valid platform scope.
   *
   * @param mixed $scope
   *   The value.
   *
   * @return bool
   *   TRUE for strings such as "chat:use".
   */
  public static function isValidScope(mixed $scope): bool {
    return is_string($scope) && preg_match(self::SCOPE_PATTERN, $scope) === 1;
  }

  /**
   * Whether a permission is defined on the site.
   *
   * @param string $permission
   *   The permission name.
   *
   * @return bool
   *   TRUE if some module defines it.
   */
  private function permissionExists(string $permission): bool {
    $this->permissions ??= $this->permissionHandler->getPermissions();
    return isset($this->permissions[$permission]);
  }

}
