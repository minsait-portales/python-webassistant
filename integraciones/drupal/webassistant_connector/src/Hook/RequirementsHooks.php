<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Sync\SyncStatus;

/**
 * Status report requirements (design section 10).
 *
 * Checks credentials, https API URL outside localhost, dead events and
 * backlog. Severities use \Drupal\Core\Extension\Requirement\RequirementSeverity.
 */
final class RequirementsHooks {

  /**
   * Constructs a RequirementsHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials.
   * @param \Drupal\webassistant_connector\Sync\SyncStatus $syncStatus
   *   The synchronization status.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Credentials $credentials,
    private readonly SyncStatus $syncStatus,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    // @todo Implement (status engineer).
    return [];
  }

}
