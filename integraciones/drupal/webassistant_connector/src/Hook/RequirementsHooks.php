<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\webassistant_connector\Form\SettingsForm;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SyncStatus;
use Drupal\webassistant_connector\Sync\UrlResolver;

/**
 * Status report requirements (design section 10).
 *
 * Checks credentials (and that the API URL is as protected as the client
 * secret), an https API URL outside localhost, a trusted public base URL,
 * dead events and the backlog. No request to the platform is made here (the
 * status page of the connector runs the live checks).
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  /**
   * Age in seconds after which pending events count as backlog.
   */
  public const BACKLOG_AGE = 3600;

  /**
   * Constructs a RequirementsHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials.
   * @param \Drupal\webassistant_connector\Sync\SyncStatus $syncStatus
   *   The synchronization status.
   * @param \Drupal\webassistant_connector\Sync\UrlResolver $urlResolver
   *   The URL resolver.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Credentials $credentials,
    private readonly SyncStatus $syncStatus,
    private readonly UrlResolver $urlResolver,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $config = $this->configFactory->get('webassistant_connector.settings');
    $statusUrl = Url::fromRoute('webassistant_connector.status')->toString();
    $requirements = [];

    if (!$config->get('enabled')) {
      $requirements['webassistant_connector'] = [
        'title' => $this->t('WebAssistant connector'),
        'value' => $this->t('Disabled'),
        'description' => $this->t('The connector is disabled: the widget is not shown and the outbox is not processed (changes are still recorded).'),
        'severity' => RequirementSeverity::Info,
      ];
    }

    $missing = [];
    if ($this->credentials->clientId() === '') {
      $missing[] = 'client_id';
    }
    if ($this->credentials->clientSecret() === '') {
      $missing[] = 'client_secret';
    }
    if ($this->credentials->apiBaseUrl() === '') {
      $missing[] = 'api_base_url';
    }
    $requirements['webassistant_connector_credentials'] = $missing === []
      ? [
        'title' => $this->t('WebAssistant credentials'),
        'value' => $this->t('Configured (client ID from @id_source, secret from @secret_source)', [
          '@id_source' => $this->credentials->source('client_id'),
          '@secret_source' => $this->credentials->source('client_secret'),
        ]),
        'severity' => RequirementSeverity::OK,
      ]
      : [
        'title' => $this->t('WebAssistant credentials'),
        'value' => $this->t('Missing: @names', ['@names' => implode(', ', $missing)]),
        "description" => $this->t("Set them in settings.php (\$settings['webassistant_connector']) or in the environment (WEBASSISTANT_CLIENT_ID, WEBASSISTANT_CLIENT_SECRET, WEBASSISTANT_API_URL). The client secret is never stored in configuration."),
        'severity' => $config->get('enabled') ? RequirementSeverity::Error : RequirementSeverity::Warning,
      ];

    $secretSource = $this->credentials->source('client_secret');
    if ($secretSource !== Credentials::SOURCE_MISSING && $this->credentials->source('api_base_url') === Credentials::SOURCE_CONFIG) {
      $requirements['webassistant_connector_api_url_source'] = [
        'title' => $this->t('WebAssistant API URL'),
        'value' => $this->t('Stored in configuration'),
        'description' => $this->t("Set the API URL next to the client secret, in settings.php (\$settings['webassistant_connector']['api_base_url']) or in WEBASSISTANT_API_URL: whoever can change it in the connector settings can make Drupal send the client secret to another server."),
        'severity' => RequirementSeverity::Warning,
      ];
    }

    $apiUrl = $this->credentials->apiBaseUrl();
    if ($apiUrl !== '' && !SettingsForm::isSecureUrl($apiUrl)) {
      $requirements['webassistant_connector_https'] = [
        'title' => $this->t('WebAssistant API URL'),
        'value' => $apiUrl,
        'description' => $this->t('The API URL does not use https. Use https outside local development: the connection carries the client secret and user tokens.'),
        'severity' => RequirementSeverity::Warning,
      ];
    }

    if (!$config->get('sync.enabled')) {
      $requirements['webassistant_connector_sync'] = [
        'title' => $this->t('WebAssistant synchronization'),
        'value' => $this->t('Disabled'),
        'description' => $this->t('Content changes are recorded but not sent to the platform.'),
        'severity' => RequirementSeverity::Info,
      ];
      return $requirements;
    }

    if (!$this->urlResolver->hasTrustedBaseUrl(FALSE)) {
      $requirements['webassistant_connector_public_url'] = [
        'title' => $this->t('WebAssistant public URL'),
        'value' => $this->t('Not set'),
        'description' => $this->t("Set the public base URL in the synchronization settings or \$settings['trusted_host_patterns'] in settings.php. Until then cron does not send documents: their URLs, including signed download URLs, would take the Host header of the visit that runs cron."),
        'severity' => $config->get('enabled') ? RequirementSeverity::Error : RequirementSeverity::Warning,
      ];
    }

    $counts = $this->syncStatus->counts();
    $dead = $counts[OutboxItem::STATUS_DEAD] ?? 0;
    $backlog = $this->syncStatus->backlog(self::BACKLOG_AGE);
    if ($dead > 0) {
      $requirements['webassistant_connector_dead'] = [
        'title' => $this->t('WebAssistant synchronization errors'),
        'value' => $this->formatPlural($dead, '1 event in the dead letter', '@count events in the dead letter'),
        'description' => $this->t('These changes were not delivered. Review and retry them on the <a href=":url">status page</a>.', [':url' => $statusUrl]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    if ($backlog > 0) {
      $requirements['webassistant_connector_backlog'] = [
        'title' => $this->t('WebAssistant synchronization backlog'),
        'value' => $this->formatPlural($backlog, '1 event waiting for more than an hour', '@count events waiting for more than an hour'),
        'description' => $this->t('Make sure cron runs (or run "drush queue:run webassistant_connector_dispatch") and check the platform on the <a href=":url">status page</a>.', [':url' => $statusUrl]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    if ($dead === 0 && $backlog === 0) {
      $requirements['webassistant_connector_outbox'] = [
        'title' => $this->t('WebAssistant synchronization'),
        'value' => $this->t('@pending pending, @failed failed', [
          '@pending' => $counts[OutboxItem::STATUS_PENDING] ?? 0,
          '@failed' => $counts[OutboxItem::STATUS_FAILED] ?? 0,
        ]),
        'severity' => RequirementSeverity::OK,
      ];
    }
    return $requirements;
  }

}
