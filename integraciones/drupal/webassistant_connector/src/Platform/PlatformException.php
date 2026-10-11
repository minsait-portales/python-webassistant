<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Platform;

/**
 * Error returned by the WebAssistant API or by the transport (section 4).
 *
 * The message never contains secrets nor full response bodies; it is safe for
 * logs but not meant for end users.
 */
final class PlatformException extends \RuntimeException {

  /**
   * Error code for transport failures (DNS, TLS, timeouts, resets).
   */
  public const CODE_NETWORK_ERROR = 'network_error';

  /**
   * Error code for responses that cannot be understood.
   */
  public const CODE_INVALID_RESPONSE = 'invalid_response';

  /**
   * Error code when credentials or the API URL are missing.
   */
  public const CODE_NOT_CONFIGURED = 'not_configured';

  /**
   * HTTP statuses that are always retryable (plus every 5xx).
   */
  public const RETRYABLE_STATUSES = [408, 425, 429];

  /**
   * Constructs a PlatformException.
   *
   * @param string $message
   *   A safe message (no secrets, no full bodies).
   * @param int|null $status
   *   The HTTP status, or NULL for transport errors.
   * @param string $errorCode
   *   The problem+json "code", or network_error / invalid_response /
   *   not_configured.
   * @param bool $retryable
   *   Whether the operation may be retried later.
   * @param int|null $retryAfter
   *   Seconds from the Retry-After header, if any.
   * @param string|null $correlationId
   *   The X-Request-Id of the request.
   * @param list<array<string, mixed>> $errors
   *   The problem+json "errors" list (path/message pairs).
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(
    string $message,
    private readonly ?int $status = NULL,
    private readonly string $errorCode = self::CODE_INVALID_RESPONSE,
    private readonly bool $retryable = FALSE,
    private readonly ?int $retryAfter = NULL,
    private readonly ?string $correlationId = NULL,
    private readonly array $errors = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, $status ?? 0, $previous);
  }

  /**
   * Builds the exception from an HTTP error response.
   *
   * @param int $status
   *   The HTTP status.
   * @param array<string, mixed> $body
   *   The decoded problem+json body (may be empty).
   * @param string|null $retryAfter
   *   The raw Retry-After header value.
   * @param string|null $correlationId
   *   The X-Request-Id sent or received.
   *
   * @return self
   *   The exception.
   */
  public static function fromResponse(int $status, array $body = [], ?string $retryAfter = NULL, ?string $correlationId = NULL): self {
    $code = isset($body['code']) && is_string($body['code']) && $body['code'] !== '' ? $body['code'] : 'http_' . $status;
    $retryable = self::isRetryableStatus($status) || (isset($body['retryable']) && $body['retryable'] === TRUE);
    if ($correlationId === NULL && isset($body['correlation_id']) && is_string($body['correlation_id'])) {
      $correlationId = $body['correlation_id'];
    }
    $title = '';
    foreach (['title', 'detail'] as $key) {
      if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
        $title = $body[$key];
        break;
      }
    }
    $message = sprintf('WebAssistant API error %d (%s)', $status, $code);
    if ($title !== '') {
      $message .= ': ' . mb_substr(preg_replace('/\s+/', ' ', $title) ?? '', 0, 200);
    }
    $errors = [];
    if (isset($body['errors']) && is_array($body['errors'])) {
      foreach (array_slice(array_values($body['errors']), 0, 10) as $error) {
        if (is_array($error)) {
          $errors[] = $error;
        }
      }
    }
    return new self(
      message: $message,
      status: $status,
      errorCode: $code,
      retryable: $retryable,
      retryAfter: self::parseRetryAfter($retryAfter),
      correlationId: $correlationId,
      errors: $errors,
    );
  }

  /**
   * Builds a retryable transport error.
   *
   * @param string $message
   *   A safe message.
   * @param string|null $correlationId
   *   The X-Request-Id sent.
   * @param \Throwable|null $previous
   *   The transport exception.
   *
   * @return self
   *   The exception.
   */
  public static function networkError(string $message, ?string $correlationId = NULL, ?\Throwable $previous = NULL): self {
    return new self(
      message: $message,
      errorCode: self::CODE_NETWORK_ERROR,
      retryable: TRUE,
      correlationId: $correlationId,
      previous: $previous,
    );
  }

  /**
   * Builds an error for a response that cannot be understood.
   *
   * @param string $message
   *   A safe message.
   * @param int|null $status
   *   The HTTP status, if any.
   * @param string|null $correlationId
   *   The X-Request-Id.
   * @param \Throwable|null $previous
   *   The previous exception (e.g. a JSON error).
   *
   * @return self
   *   The exception (retryable only for 5xx statuses).
   */
  public static function invalidResponse(string $message, ?int $status = NULL, ?string $correlationId = NULL, ?\Throwable $previous = NULL): self {
    return new self(
      message: $message,
      status: $status,
      errorCode: self::CODE_INVALID_RESPONSE,
      retryable: $status !== NULL && self::isRetryableStatus($status),
      correlationId: $correlationId,
      previous: $previous,
    );
  }

  /**
   * Builds a non-retryable error for missing configuration.
   *
   * @param string $message
   *   A safe message naming what is missing (never its value).
   *
   * @return self
   *   The exception.
   */
  public static function notConfigured(string $message): self {
    return new self(message: $message, errorCode: self::CODE_NOT_CONFIGURED);
  }

  /**
   * Whether an HTTP status is retryable (408, 425, 429 or 5xx).
   *
   * @param int $status
   *   The HTTP status.
   *
   * @return bool
   *   TRUE if retryable.
   */
  public static function isRetryableStatus(int $status): bool {
    return in_array($status, self::RETRYABLE_STATUSES, TRUE) || $status >= 500;
  }

  /**
   * Parses a Retry-After header (seconds or HTTP date).
   *
   * @param string|null $value
   *   The header value.
   * @param int|null $now
   *   The current timestamp (defaults to time()).
   *
   * @return int|null
   *   Seconds to wait (>= 0), or NULL if absent or invalid.
   */
  public static function parseRetryAfter(?string $value, ?int $now = NULL): ?int {
    if ($value === NULL || trim($value) === '') {
      return NULL;
    }
    $value = trim($value);
    if (ctype_digit($value)) {
      return (int) $value;
    }
    $timestamp = strtotime($value);
    if ($timestamp === FALSE) {
      return NULL;
    }
    return max(0, $timestamp - ($now ?? time()));
  }

  /**
   * Returns the HTTP status, or NULL for transport errors.
   */
  public function getStatus(): ?int {
    return $this->status;
  }

  /**
   * Returns the problem+json code or the transport error code.
   */
  public function getErrorCode(): string {
    return $this->errorCode;
  }

  /**
   * Whether the operation may be retried later.
   */
  public function isRetryable(): bool {
    return $this->retryable;
  }

  /**
   * Returns the seconds from the Retry-After header, if any.
   */
  public function getRetryAfter(): ?int {
    return $this->retryAfter;
  }

  /**
   * Returns the X-Request-Id of the failed request, if known.
   */
  public function getCorrelationId(): ?string {
    return $this->correlationId;
  }

  /**
   * Returns the problem+json "errors" list (path/message pairs).
   *
   * @return list<array<string, mixed>>
   *   The errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

}
