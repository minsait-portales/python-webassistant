<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

/**
 * Validated host action object (host-action.v1, design section 7).
 */
final class HostActionRequest {

  /**
   * Pattern of host action names (also the route requirement).
   */
  public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

  /**
   * Pattern of execution and correlation IDs.
   */
  public const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/';

  /**
   * Seconds of tolerance applied to expires_at.
   */
  public const EXPIRY_TOLERANCE = 30;

  /**
   * Maximum length of skill, action and run_id.
   */
  public const MAX_LABEL_LENGTH = 128;

  /**
   * RFC 3339 date-time with a mandatory offset (e.g. Python isoformat()).
   */
  public const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d{1,9})?([Zz]|[+-]\d{2}:\d{2})$/';

  /**
   * Constructs a HostActionRequest. Use fromArray() for untrusted input.
   *
   * @param string $name
   *   The host action name.
   * @param string $executionId
   *   The execution ID (idempotency key).
   * @param string $correlationId
   *   The correlation ID.
   * @param array<string, mixed> $params
   *   The action parameters (untrusted, validated by the plugin).
   * @param string|null $skill
   *   The skill that requested the action.
   * @param string|null $action
   *   The skill action name.
   * @param string|null $runId
   *   The run ID.
   * @param int|null $expiresAt
   *   Expiration timestamp, if provided.
   * @param string $version
   *   The contract version (always "1").
   */
  public function __construct(
    public readonly string $name,
    public readonly string $executionId,
    public readonly string $correlationId,
    public readonly array $params = [],
    public readonly ?string $skill = NULL,
    public readonly ?string $action = NULL,
    public readonly ?string $runId = NULL,
    public readonly ?int $expiresAt = NULL,
    public readonly string $version = '1',
  ) {}

  /**
   * Validates an untrusted payload and builds the request.
   *
   * Rules: version === '1'; name matches NAME_PATTERN; execution_id and
   * correlation_id match ID_PATTERN; skill, action and run_id are optional
   * strings (run_id may be NULL) of at most 128 characters; params is a JSON
   * object; expires_at, if present and not NULL, is an RFC 3339 date-time
   * with offset (DATE_TIME_PATTERN) not older than now minus
   * EXPIRY_TOLERANCE seconds. Unknown keys are ignored.
   *
   * @param array<mixed> $payload
   *   The decoded JSON body.
   * @param int|null $now
   *   The current timestamp (defaults to time()).
   *
   * @return self
   *   The request.
   *
   * @throws \InvalidArgumentException
   *   When the payload is invalid or expired (the controller answers 400).
   */
  public static function fromArray(array $payload, ?int $now = NULL): self {
    if (($payload['version'] ?? NULL) !== '1') {
      throw new \InvalidArgumentException('Unsupported host action version.');
    }
    $name = $payload['name'] ?? NULL;
    if (!is_string($name) || strlen($name) > 128 || !preg_match(self::NAME_PATTERN, $name)) {
      throw new \InvalidArgumentException('Invalid host action name.');
    }
    $executionId = $payload['execution_id'] ?? NULL;
    if (!is_string($executionId) || !preg_match(self::ID_PATTERN, $executionId)) {
      throw new \InvalidArgumentException('Invalid execution_id.');
    }
    $correlationId = $payload['correlation_id'] ?? NULL;
    if (!is_string($correlationId) || !preg_match(self::ID_PATTERN, $correlationId)) {
      throw new \InvalidArgumentException('Invalid correlation_id.');
    }
    $skill = self::optionalString($payload, 'skill');
    $action = self::optionalString($payload, 'action');
    $runId = self::optionalString($payload, 'run_id');

    if (!array_key_exists('params', $payload) || !is_array($payload['params'])) {
      throw new \InvalidArgumentException('The params member must be an object.');
    }
    $params = $payload['params'];
    if ($params !== [] && array_is_list($params)) {
      throw new \InvalidArgumentException('The params member must be an object.');
    }

    $expiresAt = NULL;
    if (array_key_exists('expires_at', $payload) && $payload['expires_at'] !== NULL) {
      if (!is_string($payload['expires_at']) || !preg_match(self::DATE_TIME_PATTERN, $payload['expires_at'])) {
        throw new \InvalidArgumentException('Invalid expires_at.');
      }
      try {
        // Fractions beyond microseconds are not supported by PHP: trim them.
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $payload['expires_at']);
        $date = new \DateTimeImmutable($value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== FALSE && $errors['warning_count'] > 0) {
          // E.g. 2026-02-30: PHP would silently move it to March.
          throw new \InvalidArgumentException('Invalid expires_at.');
        }
        $expiresAt = $date->getTimestamp();
      }
      catch (\Exception $e) {
        throw new \InvalidArgumentException('Invalid expires_at.', 0, $e);
      }
      if ($expiresAt + self::EXPIRY_TOLERANCE < ($now ?? time())) {
        throw new \InvalidArgumentException('The host action has expired.');
      }
    }

    return new self(
      name: $name,
      executionId: $executionId,
      correlationId: $correlationId,
      params: $params,
      skill: $skill,
      action: $action,
      runId: $runId,
      expiresAt: $expiresAt,
    );
  }

  /**
   * Reads an optional short string member.
   *
   * @param array<mixed> $payload
   *   The payload.
   * @param string $key
   *   The member name.
   *
   * @return string|null
   *   The value or NULL.
   */
  private static function optionalString(array $payload, string $key): ?string {
    if (!isset($payload[$key])) {
      return NULL;
    }
    $value = $payload[$key];
    if (!is_string($value) || mb_strlen($value) > self::MAX_LABEL_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value)) {
      throw new \InvalidArgumentException(sprintf('Invalid %s.', $key));
    }
    return $value;
  }

}
