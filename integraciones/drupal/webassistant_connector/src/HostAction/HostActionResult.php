<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

/**
 * Immutable result of a host action (design section 7).
 *
 * Serialized as {status, data?, message?, redirect?}; the widget handler
 * returns {status, data, message} to the platform and navigates to redirect
 * (same-origin URL) afterwards. Messages given by plugins are HTML-safe text
 * (usually t() output, whose placeholders are escaped); the dispatcher turns
 * them into plain text before answering.
 */
final class HostActionResult {

  /**
   * Status of a successful action.
   */
  public const STATUS_SUCCEEDED = 'succeeded';

  /**
   * Status of a failed action.
   */
  public const STATUS_FAILED = 'failed';

  /**
   * Constructs a HostActionResult. Use the named constructors.
   *
   * @param string $status
   *   One of the STATUS_* constants.
   * @param array<string, mixed> $data
   *   Result data (no personal data: the platform stores it).
   * @param string|null $message
   *   A user-facing message.
   * @param string|null $redirect
   *   A same-origin URL to navigate to after returning the result.
   */
  private function __construct(
    public readonly string $status,
    public readonly array $data = [],
    public readonly ?string $message = NULL,
    public readonly ?string $redirect = NULL,
  ) {}

  /**
   * Builds a successful result.
   *
   * @param array<string, mixed> $data
   *   Result data.
   * @param string|\Stringable|null $message
   *   A user-facing message.
   * @param string|null $redirect
   *   A same-origin URL to navigate to.
   *
   * @return self
   *   The result.
   */
  public static function succeeded(array $data = [], string|\Stringable|null $message = NULL, ?string $redirect = NULL): self {
    return new self(self::STATUS_SUCCEEDED, $data, $message === NULL ? NULL : (string) $message, $redirect);
  }

  /**
   * Builds a failed result.
   *
   * @param string|\Stringable $message
   *   A user-safe message.
   * @param array<string, mixed> $data
   *   Extra data (e.g. candidates).
   *
   * @return self
   *   The result.
   */
  public static function failed(string|\Stringable $message, array $data = []): self {
    return new self(self::STATUS_FAILED, $data, (string) $message);
  }

  /**
   * Rebuilds a result stored with toArray() (idempotency replay).
   *
   * @param array<string, mixed> $values
   *   The stored values.
   *
   * @return self
   *   The result.
   */
  public static function fromArray(array $values): self {
    $status = ($values['status'] ?? NULL) === self::STATUS_SUCCEEDED ? self::STATUS_SUCCEEDED : self::STATUS_FAILED;
    $data = isset($values['data']) && is_array($values['data']) ? $values['data'] : [];
    $message = isset($values['message']) && is_string($values['message']) ? $values['message'] : NULL;
    $redirect = isset($values['redirect']) && is_string($values['redirect']) ? $values['redirect'] : NULL;
    return new self($status, $data, $message, $redirect);
  }

  /**
   * Returns a copy with extra data merged (e.g. ['replayed' => TRUE]).
   *
   * @param array<string, mixed> $data
   *   Data merged over the current data.
   *
   * @return self
   *   The new result.
   */
  public function withData(array $data): self {
    return new self($this->status, array_replace($this->data, $data), $this->message, $this->redirect);
  }

  /**
   * Returns a copy with another message (NULL removes it).
   *
   * @param string|null $message
   *   The user-facing message.
   *
   * @return self
   *   The new result.
   */
  public function withMessage(?string $message): self {
    return new self($this->status, $this->data, $message, $this->redirect);
  }

  /**
   * Returns a copy with another redirect (NULL removes it).
   *
   * @param string|null $redirect
   *   A same-origin URL, or NULL.
   *
   * @return self
   *   The new result.
   */
  public function withRedirect(?string $redirect): self {
    return new self($this->status, $this->data, $this->message, $redirect);
  }

  /**
   * Whether the action succeeded.
   */
  public function isSucceeded(): bool {
    return $this->status === self::STATUS_SUCCEEDED;
  }

  /**
   * Returns the response body, omitting NULL members and empty data.
   *
   * @return array<string, mixed>
   *   {status, data?, message?, redirect?}.
   */
  public function toArray(): array {
    $result = ['status' => $this->status];
    if ($this->data !== []) {
      $result['data'] = $this->data;
    }
    if ($this->message !== NULL) {
      $result['message'] = $this->message;
    }
    if ($this->redirect !== NULL) {
      $result['redirect'] = $this->redirect;
    }
    return $result;
  }

}
