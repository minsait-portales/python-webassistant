<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Sync\SyncStatus;

/**
 * Diagnostics and outbox page (route webassistant_connector.status, sec. 10).
 *
 * Shows connectivity (health), credentials (source only, never values),
 * site/origin (bootstrap), queue counters, recent errors and the
 * StatusActionsForm buttons.
 */
final class StatusController extends ControllerBase {

  /**
   * Constructs a StatusController.
   *
   * @param \Drupal\webassistant_connector\Sync\SyncStatus $syncStatus
   *   The synchronization status.
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials.
   */
  public function __construct(
    private readonly SyncStatus $syncStatus,
    private readonly PlatformClientInterface $platformClient,
    private readonly Credentials $credentials,
  ) {}

  /**
   * Builds the status page.
   *
   * @return array<string, mixed>
   *   A render array (max-age 0).
   */
  public function page(): array {
    throw new \LogicException('Not implemented yet.');
  }

}
