<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched before a widget token is requested (design section 5).
 *
 * Subscribers may adjust roles, groups and scopes (e.g. the Group module adds
 * group IDs). Values must match [A-Za-z0-9_.@-]+ to be usable in ACLs.
 */
final class WidgetTokenEvent extends Event {

  /**
   * Event name.
   */
  public const NAME = 'webassistant_connector.widget_token';

  /**
   * Role IDs sent to the platform.
   *
   * @var list<string>
   */
  private array $roles;

  /**
   * Group IDs sent to the platform.
   *
   * @var list<string>
   */
  private array $groups;

  /**
   * Scopes requested.
   *
   * @var list<string>
   */
  private array $scopes;

  /**
   * Constructs a WidgetTokenEvent.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the token is issued for.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The token request.
   * @param list<string> $roles
   *   Initial roles (IdentityMapper::roles(), or [] if send_roles is off).
   * @param list<string> $groups
   *   Initial groups (empty in the base module).
   * @param list<string> $scopes
   *   Initial scopes (ScopeResolver::resolve()).
   */
  public function __construct(
    private readonly AccountInterface $account,
    private readonly Request $request,
    array $roles = [],
    array $groups = [],
    array $scopes = [],
  ) {
    $this->setRoles($roles);
    $this->setGroups($groups);
    $this->setScopes($scopes);
  }

  /**
   * Returns the account.
   */
  public function getAccount(): AccountInterface {
    return $this->account;
  }

  /**
   * Returns the request.
   */
  public function getRequest(): Request {
    return $this->request;
  }

  /**
   * Returns the roles.
   *
   * @return list<string>
   *   Role IDs.
   */
  public function getRoles(): array {
    return $this->roles;
  }

  /**
   * Sets the roles.
   *
   * @param array<int|string, string> $roles
   *   Role IDs (deduplicated).
   *
   * @return $this
   */
  public function setRoles(array $roles): static {
    $this->roles = self::unique($roles);
    return $this;
  }

  /**
   * Returns the groups.
   *
   * @return list<string>
   *   Group IDs.
   */
  public function getGroups(): array {
    return $this->groups;
  }

  /**
   * Sets the groups.
   *
   * @param array<int|string, string> $groups
   *   Group IDs (deduplicated).
   *
   * @return $this
   */
  public function setGroups(array $groups): static {
    $this->groups = self::unique($groups);
    return $this;
  }

  /**
   * Returns the scopes.
   *
   * @return list<string>
   *   Scopes.
   */
  public function getScopes(): array {
    return $this->scopes;
  }

  /**
   * Sets the scopes (sorted and deduplicated).
   *
   * @param array<int|string, string> $scopes
   *   Scopes.
   *
   * @return $this
   */
  public function setScopes(array $scopes): static {
    $scopes = self::unique($scopes);
    sort($scopes);
    $this->scopes = $scopes;
    return $this;
  }

  /**
   * Deduplicates a list of strings, dropping empty values.
   *
   * @param array<int|string, mixed> $values
   *   The values.
   *
   * @return list<string>
   *   The strings.
   */
  private static function unique(array $values): array {
    $list = [];
    foreach ($values as $value) {
      if (is_scalar($value) && (string) $value !== '') {
        $list[(string) $value] = TRUE;
      }
    }
    return array_map('strval', array_keys($list));
  }

}
