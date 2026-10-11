<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Platform;

/**
 * Immutable request for a widget token (design section 4).
 *
 * Note: an empty scope list makes the platform grant every allowed scope, so
 * callers always send an explicit list (ScopeResolver includes base scopes).
 */
final class WidgetTokenRequest {

  /**
   * Minimum token lifetime accepted by the platform.
   */
  public const MIN_TTL = 60;

  /**
   * Maximum token lifetime accepted by the platform.
   */
  public const MAX_TTL = 3600;

  /**
   * Maximum number of roles, groups or scopes accepted by the platform.
   */
  public const MAX_ITEMS = 50;

  /**
   * Opaque user ID (IdentityMapper::userId()).
   */
  public readonly string $userId;

  /**
   * Role IDs.
   *
   * @var list<string>
   */
  public readonly array $roles;

  /**
   * Group IDs.
   *
   * @var list<string>
   */
  public readonly array $groups;

  /**
   * Requested scopes.
   *
   * @var list<string>
   */
  public readonly array $scopes;

  /**
   * Constructs a WidgetTokenRequest.
   *
   * @param string $userId
   *   Opaque user ID, 1..128 characters matching [A-Za-z0-9_.@-]+.
   * @param array<int|string, string> $roles
   *   Role IDs (deduplicated, at most 50).
   * @param array<int|string, string> $groups
   *   Group IDs (deduplicated, at most 50).
   * @param string|null $locale
   *   Platform locale (e.g. es, pt-BR) or NULL.
   * @param array<int|string, string> $scopes
   *   Requested scopes (deduplicated and sorted, at most 50).
   * @param string|null $origin
   *   Page origin (scheme://host[:port]) to bind the token to, or NULL.
   * @param int $ttl
   *   Lifetime in seconds (60..3600).
   * @param string|null $assistantId
   *   Assistant to restrict the token to, or NULL for the site default.
   *
   * @throws \InvalidArgumentException
   *   When a value is out of the platform limits.
   */
  public function __construct(
    string $userId,
    array $roles = [],
    array $groups = [],
    public readonly ?string $locale = NULL,
    array $scopes = [],
    public readonly ?string $origin = NULL,
    public readonly int $ttl = 900,
    public readonly ?string $assistantId = NULL,
  ) {
    if (!preg_match('/^[A-Za-z0-9_.@-]{1,128}$/', $userId)) {
      throw new \InvalidArgumentException('The user ID must be 1..128 characters of [A-Za-z0-9_.@-].');
    }
    if ($ttl < self::MIN_TTL || $ttl > self::MAX_TTL) {
      throw new \InvalidArgumentException(sprintf('The token TTL must be between %d and %d seconds.', self::MIN_TTL, self::MAX_TTL));
    }
    $this->userId = $userId;
    $this->roles = self::normalizeList($roles, 'roles');
    $this->groups = self::normalizeList($groups, 'groups');
    $scopes = self::normalizeList($scopes, 'scopes');
    sort($scopes);
    $this->scopes = $scopes;
  }

  /**
   * Returns the widget_token fields of the POST /v1/token body.
   *
   * The platform client adds client_id, client_secret and site_id.
   *
   * @return array<string, mixed>
   *   The body fragment: grant_type, user {id, roles, groups, locale?},
   *   scopes, ttl_seconds, origin? and assistant_id?.
   */
  public function toArray(): array {
    $user = [
      'id' => $this->userId,
      'roles' => $this->roles,
      'groups' => $this->groups,
    ];
    if ($this->locale !== NULL && $this->locale !== '') {
      $user['locale'] = $this->locale;
    }
    $body = [
      'grant_type' => 'widget_token',
      'user' => $user,
      'scopes' => $this->scopes,
      'ttl_seconds' => $this->ttl,
    ];
    if ($this->origin !== NULL && $this->origin !== '') {
      $body['origin'] = $this->origin;
    }
    if ($this->assistantId !== NULL && $this->assistantId !== '') {
      $body['assistant_id'] = $this->assistantId;
    }
    return $body;
  }

  /**
   * Returns a stable fingerprint of the identity carried by the request.
   *
   * Used to decide whether a token cached in the session can be reused.
   *
   * @return string
   *   A SHA-256 hex digest.
   */
  public function fingerprint(): string {
    $data = $this->toArray();
    unset($data['ttl_seconds']);
    return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * Normalizes a list of identifiers.
   *
   * @param array<int|string, mixed> $values
   *   The values.
   * @param string $name
   *   The list name (for the error message).
   *
   * @return list<string>
   *   Unique non-empty strings, in their original order.
   */
  private static function normalizeList(array $values, string $name): array {
    $list = [];
    foreach ($values as $value) {
      if (!is_string($value) || trim($value) === '') {
        throw new \InvalidArgumentException(sprintf('The %s list must contain non-empty strings.', $name));
      }
      $list[trim($value)] = TRUE;
    }
    if (count($list) > self::MAX_ITEMS) {
      throw new \InvalidArgumentException(sprintf('The %s list may contain at most %d items.', $name, self::MAX_ITEMS));
    }
    return array_map('strval', array_keys($list));
  }

}
