<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Plugin\Block\WidgetBlock;
use Drupal\webassistant_connector\Widget\WidgetAttachmentBuilder;

/**
 * Connection, site and widget settings (route webassistant_connector.settings).
 *
 * Edits enabled, api.*, site_id, tenant_id, assistant_id, client_id and
 * widget.* of webassistant_connector.settings. Secrets are never shown: only
 * where they come from (Credentials::source()).
 */
final class SettingsForm extends ConfigFormBase {

  use AutowireTrait;

  /**
   * The configuration name.
   */
  private const CONFIG = 'webassistant_connector.settings';

  /**
   * Constructs a SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials.
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client (connection test).
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected Credentials $credentials,
    protected PlatformClientInterface $platformClient,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webassistant_connector_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Platform connection'),
      '#open' => TRUE,
    ];
    $form['connection']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable the connector'),
      '#description' => $this->t('When disabled, the widget is not shown, no tokens are issued and synchronization stops (changes are still recorded). Use it to roll back without uninstalling.'),
      '#config_target' => self::CONFIG . ':enabled',
    ];
    $form['connection']['api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL (server to server)'),
      '#description' => $this->overrideDescription('api_base_url', $this->t('URL that Drupal uses to call the WebAssistant API, for example %example. It may be an internal network address.', ['%example' => 'https://assistant.example.com'])),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'api.base_url',
        toConfig: [self::class, 'trimToConfig'],
      ),
      '#maxlength' => 255,
    ];
    $form['connection']['api_public_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL for browsers'),
      '#description' => $this->overrideDescription('api_public_url', $this->t('URL from which browsers load the widget and call the API. Leave empty to use the server URL.')),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'api.public_url',
        toConfig: [self::class, 'trimToConfig'],
      ),
      '#maxlength' => 255,
    ];
    $form['connection']['api_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#max' => 120,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'api.timeout',
        toConfig: [self::class, 'intToConfig'],
      ),
    ];
    $form['connection']['api_connect_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection timeout'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#max' => 60,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'api.connect_timeout',
        toConfig: [self::class, 'intToConfig'],
      ),
    ];
    $form['connection']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API client ID'),
      '#description' => $this->overrideDescription('client_id', $this->t('Public identifier of the API client. The client secret is never stored in configuration: define it in settings.php (%setting) or in the %env environment variable.', [
        '%setting' => "\$settings['webassistant_connector']['client_secret']",
        '%env' => 'WEBASSISTANT_CLIENT_SECRET',
      ])),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'client_id',
        toConfig: [self::class, 'trimToConfig'],
      ),
      '#maxlength' => 64,
    ];
    $form['connection']['credentials'] = [
      '#type' => 'table',
      '#caption' => $this->t('Credential sources'),
      '#header' => [$this->t('Value'), $this->t('Source')],
      '#rows' => $this->credentialRows(),
    ];

    $form['site'] = [
      '#type' => 'details',
      '#title' => $this->t('Site'),
      '#open' => TRUE,
    ];
    $form['site']['site_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site ID'),
      '#description' => $this->t('Public ID of the site in the WebAssistant platform (lowercase letters, digits, hyphens and underscores).'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#config_target' => new ConfigTarget(self::CONFIG, 'site_id', toConfig: [self::class, 'trimToConfig']),
    ];
    $form['site']['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant ID'),
      '#description' => $this->t('Informative, shown in diagnostics.'),
      '#maxlength' => 64,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'tenant_id',
        toConfig: [self::class, 'trimToConfig'],
      ),
    ];
    $form['site']['assistant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default assistant ID'),
      '#description' => $this->t('Leave empty to use the default assistant of the site.'),
      '#maxlength' => 64,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'assistant_id',
        toConfig: [self::class, 'trimToConfig'],
      ),
    ];

    $form['widget'] = [
      '#type' => 'details',
      '#title' => $this->t('Widget'),
      '#open' => TRUE,
    ];
    $form['widget']['placement'] = [
      '#type' => 'radios',
      '#title' => $this->t('Placement'),
      '#options' => [
        'global' => $this->t('On every page (subject to the visibility settings below)'),
        'block' => $this->t('Only where a "WebAssistant widget" block is placed'),
      ],
      '#config_target' => self::CONFIG . ':widget.placement',
    ];
    $form['widget']['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Position'),
      '#options' => [
        '' => $this->t('Platform default'),
        'left' => $this->t('Left'),
        'right' => $this->t('Right'),
      ],
      '#config_target' => self::CONFIG . ':widget.position',
    ];
    $form['widget']['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open on load'),
      '#config_target' => self::CONFIG . ':widget.open',
    ];
    $form['widget']['result_target'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Results container selector'),
      '#description' => $this->t('CSS selector of the element where the widget shows its results, for example %example. Leave empty to show them in the widget panel.', ['%example' => '#main-results']),
      '#maxlength' => 255,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'widget.result_target',
        toConfig: [self::class, 'trimToConfig'],
      ),
    ];
    $form['widget']['pass_locale'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the interface language to the widget'),
      '#config_target' => self::CONFIG . ':widget.pass_locale',
    ];
    $form['widget']['analytics_datalayer'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish widget events in window.dataLayer'),
      '#description' => $this->t('Only the event type, instance, skill, action and status are published: no parameters and no personal data.'),
      '#config_target' => self::CONFIG . ':widget.analytics_datalayer',
    ];
    $form['widget']['visibility'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Visibility of the global placement'),
    ];
    $form['widget']['visibility']['exclude_admin_routes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide on administration pages'),
      '#config_target' => self::CONFIG . ':widget.visibility.exclude_admin_routes',
    ];
    $form['widget']['visibility']['pages'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pages'),
      '#description' => $this->t('One path per line, with a leading slash. The %wildcard character is a wildcard and %front is the front page. Paths and aliases are both checked. Leave empty to show the widget on every page.', [
        '%wildcard' => '*',
        '%front' => '<front>',
      ]),
      '#rows' => 4,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'widget.visibility.pages',
        toConfig: [self::class, 'linesToConfig'],
      ),
    ];
    $form['widget']['visibility']['negate'] = [
      '#type' => 'radios',
      '#title' => $this->t('Show the widget'),
      '#options' => [
        1 => $this->t('On every page except the listed pages'),
        0 => $this->t('Only on the listed pages'),
      ],
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'widget.visibility.negate',
        fromConfig: [self::class, 'boolFromConfig'],
        toConfig: [self::class, 'boolToConfig'],
      ),
    ];

    $form = parent::buildForm($form, $form_state);
    // After the save button, so that pressing Enter saves the form.
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection'),
      '#submit' => ['::testConnection'],
      '#validate' => [],
      '#limit_validation_errors' => [],
      '#weight' => 10,
      '#attributes' => ['title' => $this->t('Tests the saved configuration: save your changes first.')],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach (['api_base_url' => 'api_base_url', 'api_public_url' => 'api_public_url'] as $element => $credential) {
      $url = trim((string) $form_state->getValue($element));
      if ($url === '') {
        if ($element === 'api_base_url' && !$this->isOverridden($credential)) {
          $form_state->setErrorByName($element, $this->t('The API URL is required unless it is defined in settings.php or in the environment.'));
        }
        continue;
      }
      if (!self::isValidApiUrl($url)) {
        $form_state->setErrorByName($element, $this->t('The URL must be an absolute http or https URL without credentials, query string or fragment.'));
      }
    }

    $assistantId = trim((string) $form_state->getValue('assistant_id'));
    if ($assistantId !== '' && !preg_match(WidgetAttachmentBuilder::ID_PATTERN, $assistantId)) {
      $form_state->setErrorByName('assistant_id', $this->t('The assistant ID must start with a lowercase letter and contain only lowercase letters, digits, hyphens and underscores (2 to 64 characters).'));
    }

    $selector = trim((string) $form_state->getValue('result_target'));
    if ($selector !== '' && !WidgetBlock::isSafeSelector($selector)) {
      $form_state->setErrorByName('result_target', $this->t('The results container selector is not a valid CSS selector.'));
    }

    foreach (preg_split('/\R/', (string) $form_state->getValue('pages')) ?: [] as $line) {
      $line = trim($line);
      if ($line !== '' && $line !== '<front>' && !str_starts_with($line, '/')) {
        $form_state->setErrorByName('pages', $this->t('The path %path requires a leading forward slash.', ['%path' => $line]));
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    foreach ([
      'api_base_url' => $this->credentials->apiBaseUrl(),
      'api_public_url' => $this->credentials->apiPublicUrl(),
    ] as $url) {
      if ($url !== '' && !self::isSecureUrl($url)) {
        $this->messenger()->addWarning($this->t('The API URL %url does not use https. Use https outside local development.', ['%url' => $url]));
      }
    }
  }

  /**
   * Submit handler of "Test connection": checks the saved configuration.
   *
   * Calls health(), bootstrap() with the origin of this site and
   * serverToken(), and reports each result. Only error codes, HTTP statuses
   * and correlation IDs are shown, never secrets.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    try {
      $health = $this->platformClient->health();
      $this->messenger()->addStatus($this->t('Platform API: @status.', ['@status' => is_string($health['status'] ?? NULL) ? $health['status'] : 'ok']));
    }
    catch (PlatformException $e) {
      $this->messenger()->addError($this->t('Platform API health check failed: @error', ['@error' => self::describe($e)]));
    }

    $origin = $this->getRequest()->getSchemeAndHttpHost();
    try {
      $bootstrap = $this->platformClient->bootstrap($origin);
      $site = is_array($bootstrap['site'] ?? NULL) ? $bootstrap['site'] : [];
      $assistant = is_array($bootstrap['assistant'] ?? NULL) ? $bootstrap['assistant'] : [];
      $this->messenger()->addStatus($this->t('Site %site accepts the origin %origin (assistant %assistant).', [
        '%site' => is_string($site['id'] ?? NULL) ? $site['id'] : '-',
        '%origin' => $origin,
        '%assistant' => is_string($assistant['id'] ?? NULL) ? $assistant['id'] : '-',
      ]));
    }
    catch (PlatformException $e) {
      $this->messenger()->addError($this->t('Site bootstrap for the origin %origin failed: @error', [
        '%origin' => $origin,
        '@error' => self::describe($e),
      ]));
    }

    try {
      $token = $this->platformClient->serverToken();
      $this->messenger()->addStatus($this->t('The API client credentials are valid (scopes: @scopes).', [
        '@scopes' => $token->scopes === [] ? '-' : implode(', ', $token->scopes),
      ]));
    }
    catch (PlatformException $e) {
      $this->messenger()->addError($this->t('The API client credentials were rejected: @error', ['@error' => self::describe($e)]));
    }
  }

  /**
   * Trims a string before it is stored (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The form value.
   *
   * @return string
   *   The trimmed string.
   */
  public static function trimToConfig(mixed $value): string {
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Casts a number before it is stored (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The form value.
   *
   * @return int
   *   The integer.
   */
  public static function intToConfig(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Converts a boolean to a radios value (ConfigTarget::fromConfig).
   *
   * @param mixed $value
   *   The stored value.
   *
   * @return int
   *   1 or 0.
   */
  public static function boolFromConfig(mixed $value): int {
    return $value ? 1 : 0;
  }

  /**
   * Converts a radios value to a boolean (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The form value.
   *
   * @return bool
   *   The boolean.
   */
  public static function boolToConfig(mixed $value): bool {
    return (bool) $value;
  }

  /**
   * Normalizes a list of lines before it is stored (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The textarea value.
   *
   * @return string
   *   Trimmed, non-empty lines separated by "\n".
   */
  public static function linesToConfig(mixed $value): string {
    $lines = preg_split('/\R/', is_scalar($value) ? (string) $value : '') ?: [];
    return implode("\n", array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
  }

  /**
   * Whether a URL is acceptable as API URL.
   *
   * @param string $url
   *   The URL.
   *
   * @return bool
   *   TRUE for absolute http(s) URLs without credentials, query or fragment.
   */
  public static function isValidApiUrl(string $url): bool {
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return FALSE;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    return in_array($scheme, ['http', 'https'], TRUE)
      && !isset($parts['user'])
      && !isset($parts['pass'])
      && !isset($parts['query'])
      && !isset($parts['fragment']);
  }

  /**
   * Whether a URL uses https or points to the local machine.
   *
   * @param string $url
   *   The URL.
   *
   * @return bool
   *   FALSE for plain http to a non-local host.
   */
  public static function isSecureUrl(string $url): bool {
    $parts = parse_url($url);
    if ($parts === FALSE) {
      return FALSE;
    }
    if (strtolower($parts['scheme'] ?? '') === 'https') {
      return TRUE;
    }
    $host = strtolower(trim($parts['host'] ?? '', '[]'));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], TRUE) || str_ends_with($host, '.localhost');
  }

  /**
   * Describes a platform error for administrators (no secrets).
   *
   * @param \Drupal\webassistant_connector\Platform\PlatformException $exception
   *   The error.
   *
   * @return string
   *   The safe message with the HTTP status and the correlation ID.
   */
  private static function describe(PlatformException $exception): string {
    $parts = [$exception->getMessage()];
    if ($exception->getCorrelationId() !== NULL) {
      $parts[] = sprintf('(correlation ID %s)', $exception->getCorrelationId());
    }
    return implode(' ', $parts);
  }

  /**
   * Whether a value is defined in settings.php or the environment.
   *
   * @param string $name
   *   The Credentials value name.
   *
   * @return bool
   *   TRUE if configuration is not used for it.
   */
  private function isOverridden(string $name): bool {
    return in_array($this->credentials->source($name), [Credentials::SOURCE_SETTINGS, Credentials::SOURCE_ENVIRONMENT], TRUE);
  }

  /**
   * Appends an override notice to a field description.
   *
   * @param string $name
   *   The Credentials value name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The base description.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  private function overrideDescription(string $name, TranslatableMarkup $description): TranslatableMarkup {
    return match ($this->credentials->source($name)) {
      Credentials::SOURCE_SETTINGS => $this->t('@description <strong>Overridden in settings.php: the value below is ignored.</strong>', ['@description' => $description]),
      Credentials::SOURCE_ENVIRONMENT => $this->t('@description <strong>Overridden by an environment variable: the value below is ignored.</strong>', ['@description' => $description]),
      default => $description,
    };
  }

  /**
   * Builds the rows of the credential sources table (never the values).
   *
   * @return list<array<int, \Drupal\Core\StringTranslation\TranslatableMarkup|string>>
   *   The rows.
   */
  private function credentialRows(): array {
    $labels = [
      'client_id' => $this->t('API client ID'),
      'client_secret' => $this->t('API client secret'),
      'signing_key' => $this->t('Signing key'),
      'api_base_url' => $this->t('API URL (server to server)'),
      'api_public_url' => $this->t('API URL for browsers'),
    ];
    $rows = [];
    foreach ($labels as $name => $label) {
      $source = match ($this->credentials->source($name)) {
        Credentials::SOURCE_SETTINGS => $this->t('Configured in settings.php'),
        Credentials::SOURCE_ENVIRONMENT => $this->t('Configured in the environment'),
        Credentials::SOURCE_CONFIG => $this->t('Configured in this form'),
        Credentials::SOURCE_DEFAULT => $name === 'signing_key' ? $this->t('Derived from the site hash salt') : $this->t('Same as the server URL'),
        default => $this->t('Missing'),
      };
      $rows[] = [$label, $source];
    }
    return $rows;
  }

}
