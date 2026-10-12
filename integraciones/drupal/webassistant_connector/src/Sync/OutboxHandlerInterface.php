<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

/**
 * Processes outbox rows of a pseudo-source recorded by another module.
 *
 * Rows whose source starts with "_" are not tied to a source config entity.
 * The Documents submodule, for example, records one lightweight row per
 * changed file or media entity (source "_attachment", amendment A14) and
 * fans it out to the referencing entities by recording their events with
 * Outbox::record() or Outbox::recordKey().
 *
 * Implementations are services tagged webassistant_connector.outbox_handler.
 * SyncProcessor calls the first handler whose applies() accepts the row and
 * marks the row done when process() returns. A PlatformException thrown by
 * process() is handled like any delivery error (failed with backoff or
 * dead); other exceptions mark the row failed.
 */
interface OutboxHandlerInterface {

  /**
   * Whether this handler processes a row.
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   A claimed row of a pseudo-source.
   *
   * @return bool
   *   TRUE to process it.
   */
  public function applies(OutboxItem $item): bool;

  /**
   * Processes a row (no transaction is open; HTTP is allowed).
   *
   * @param \Drupal\webassistant_connector\Sync\OutboxItem $item
   *   The claimed row.
   *
   * @return array<string, mixed>
   *   A summary stored in the "remote" column (e.g. ['recorded' => 3]).
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  public function process(OutboxItem $item): array;

}
