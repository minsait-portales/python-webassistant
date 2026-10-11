<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

/**
 * Counters of a SyncProcessor run (design section 9.3).
 *
 * A mutable accumulator: the processor increments it while it works and
 * returns it at the end. Errors are capped to MAX_ERRORS entries.
 */
final class SyncReport {

  /**
   * Maximum number of error entries kept.
   */
  public const MAX_ERRORS = 50;

  /**
   * Outbox events processed.
   */
  private int $processed = 0;

  /**
   * Documents sent (upserts accepted by the platform).
   */
  private int $upserted = 0;

  /**
   * Documents deleted remotely.
   */
  private int $deleted = 0;

  /**
   * Events left in failed status (will be retried).
   */
  private int $failed = 0;

  /**
   * Events moved to the dead letter.
   */
  private int $dead = 0;

  /**
   * Translations skipped by the builder (skipReason).
   */
  private int $skipped = 0;

  /**
   * Error entries.
   *
   * @var list<array{message: string, item_id: int|null, correlation_id: string|null}>
   */
  private array $errors = [];

  /**
   * Adds processed events.
   */
  public function addProcessed(int $count = 1): self {
    $this->processed += $count;
    return $this;
  }

  /**
   * Adds upserted documents.
   */
  public function addUpserted(int $count = 1): self {
    $this->upserted += $count;
    return $this;
  }

  /**
   * Adds deleted documents.
   */
  public function addDeleted(int $count = 1): self {
    $this->deleted += $count;
    return $this;
  }

  /**
   * Adds failed (retryable) events.
   */
  public function addFailed(int $count = 1): self {
    $this->failed += $count;
    return $this;
  }

  /**
   * Adds dead events.
   */
  public function addDead(int $count = 1): self {
    $this->dead += $count;
    return $this;
  }

  /**
   * Adds skipped translations.
   */
  public function addSkipped(int $count = 1): self {
    $this->skipped += $count;
    return $this;
  }

  /**
   * Records an error (no secrets).
   *
   * @param string $message
   *   The error message.
   * @param int|null $itemId
   *   The outbox item ID.
   * @param string|null $correlationId
   *   The X-Request-Id.
   *
   * @return $this
   */
  public function addError(string $message, ?int $itemId = NULL, ?string $correlationId = NULL): self {
    if (count($this->errors) < self::MAX_ERRORS) {
      $this->errors[] = [
        'message' => $message,
        'item_id' => $itemId,
        'correlation_id' => $correlationId,
      ];
    }
    return $this;
  }

  /**
   * Adds the counters and errors of another report.
   */
  public function merge(SyncReport $other): self {
    $this->processed += $other->getProcessed();
    $this->upserted += $other->getUpserted();
    $this->deleted += $other->getDeleted();
    $this->failed += $other->getFailed();
    $this->dead += $other->getDead();
    $this->skipped += $other->getSkipped();
    foreach ($other->getErrors() as $error) {
      $this->addError($error['message'], $error['item_id'], $error['correlation_id']);
    }
    return $this;
  }

  /**
   * Returns the processed events.
   */
  public function getProcessed(): int {
    return $this->processed;
  }

  /**
   * Returns the upserted documents.
   */
  public function getUpserted(): int {
    return $this->upserted;
  }

  /**
   * Returns the deleted documents.
   */
  public function getDeleted(): int {
    return $this->deleted;
  }

  /**
   * Returns the failed events.
   */
  public function getFailed(): int {
    return $this->failed;
  }

  /**
   * Returns the dead events.
   */
  public function getDead(): int {
    return $this->dead;
  }

  /**
   * Returns the skipped translations.
   */
  public function getSkipped(): int {
    return $this->skipped;
  }

  /**
   * Returns the error entries.
   *
   * @return list<array{message: string, item_id: int|null, correlation_id: string|null}>
   *   The errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Whether any event failed or died.
   */
  public function hasFailures(): bool {
    return $this->failed > 0 || $this->dead > 0;
  }

  /**
   * Returns the counters and errors.
   *
   * @return array{processed: int, upserted: int, deleted: int, failed: int, dead: int, skipped: int, errors: list<array{message: string, item_id: int|null, correlation_id: string|null}>}
   *   The report.
   */
  public function toArray(): array {
    return [
      'processed' => $this->processed,
      'upserted' => $this->upserted,
      'deleted' => $this->deleted,
      'failed' => $this->failed,
      'dead' => $this->dead,
      'skipped' => $this->skipped,
      'errors' => $this->errors,
    ];
  }

}
