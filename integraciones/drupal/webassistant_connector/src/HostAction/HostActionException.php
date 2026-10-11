<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

/**
 * Business or validation error of a host action.
 *
 * The message is safe to show to the end user (translated, without internal
 * details). The dispatcher converts it into HostActionResult::failed().
 */
final class HostActionException extends \RuntimeException {

  /**
   * Constructs a HostActionException.
   *
   * @param string|\Stringable $message
   *   A user-safe message (e.g. a TranslatableMarkup with @placeholders).
   * @param array<string, mixed> $data
   *   Extra data for the failed result (e.g. candidates).
   * @param \Throwable|null $previous
   *   The previous exception.
   */
  public function __construct(
    string|\Stringable $message,
    private readonly array $data = [],
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct((string) $message, 0, $previous);
  }

  /**
   * Returns the extra data for the failed result.
   *
   * @return array<string, mixed>
   *   The data.
   */
  public function getData(): array {
    return $this->data;
  }

}
