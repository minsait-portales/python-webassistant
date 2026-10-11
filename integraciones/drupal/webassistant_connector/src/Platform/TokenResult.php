<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Platform;

/**
 * Immutable token issued by POST /v1/token (design section 4).
 */
final class TokenResult {

  /**
   * Scopes granted by the platform (sorted).
   *
   * @var list<string>
   */
  public readonly array $scopes;

  /**
   * Constructs a TokenResult.
   *
   * @param string $accessToken
   *   The bearer token (JWT). Never log it.
   * @param int $expiresAt
   *   Expiration as a Unix timestamp.
   * @param array<int|string, string> $scopes
   *   Scopes granted by the platform.
   */
  public function __construct(
    #[\SensitiveParameter]
    public readonly string $accessToken,
    public readonly int $expiresAt,
    array $scopes = [],
  ) {
    $this->scopes = array_values(array_filter($scopes, 'is_string'));
  }

  /**
   * Builds the result from a /v1/token response body.
   *
   * @param array<string, mixed> $body
   *   The decoded body: access_token, expires_at (ISO 8601) and/or
   *   expires_in (seconds), scopes.
   * @param int $now
   *   The current timestamp (used with expires_in).
   * @param string|null $correlationId
   *   The X-Request-Id, for the exception.
   *
   * @return self
   *   The token.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the body has no token or no expiration.
   */
  public static function fromResponse(array $body, int $now, ?string $correlationId = NULL): self {
    $token = $body['access_token'] ?? NULL;
    if (!is_string($token) || $token === '') {
      throw PlatformException::invalidResponse('The token response has no access_token.', NULL, $correlationId);
    }
    $expiresAt = NULL;
    if (isset($body['expires_at']) && is_string($body['expires_at'])) {
      $parsed = strtotime($body['expires_at']);
      $expiresAt = $parsed === FALSE ? NULL : $parsed;
    }
    if ($expiresAt === NULL && isset($body['expires_in']) && is_numeric($body['expires_in'])) {
      $expiresAt = $now + (int) $body['expires_in'];
    }
    if ($expiresAt === NULL) {
      throw PlatformException::invalidResponse('The token response has no expiration.', NULL, $correlationId);
    }
    $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : [];
    return new self($token, $expiresAt, $scopes);
  }

  /**
   * Returns the seconds left before expiration (negative if expired).
   *
   * @param int $now
   *   The current timestamp.
   *
   * @return int
   *   Seconds left.
   */
  public function expiresIn(int $now): int {
    return $this->expiresAt - $now;
  }

  /**
   * Whether the token is still valid with a safety margin.
   *
   * @param int $now
   *   The current timestamp.
   * @param int $margin
   *   Seconds that must remain (e.g. 30 for server tokens, 60 for widget
   *   tokens reused from the session).
   *
   * @return bool
   *   TRUE if more than $margin seconds remain.
   */
  public function isValid(int $now, int $margin = 0): bool {
    return $this->expiresAt - $margin > $now;
  }

  /**
   * Returns the expiration as ISO 8601 in UTC (e.g. 2026-10-10T18:15:00+00:00).
   *
   * @return string
   *   The formatted date.
   */
  public function getExpiresAtIso(): string {
    return gmdate('Y-m-d\TH:i:s', $this->expiresAt) . '+00:00';
  }

  /**
   * Hides the token from var_dump() and debuggers.
   *
   * @return array<string, mixed>
   *   The debug representation.
   */
  public function __debugInfo(): array {
    return [
      'accessToken' => '***',
      'expiresAt' => $this->expiresAt,
      'scopes' => $this->scopes,
    ];
  }

}
