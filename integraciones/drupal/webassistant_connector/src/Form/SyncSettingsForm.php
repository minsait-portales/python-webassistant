<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webassistant_connector\Sync\DocumentMap;

/**
 * Synchronization settings (route webassistant_connector.sync_settings).
 *
 * Edits sync.* of webassistant_connector.settings. The source ID prefix is
 * read-only once documents were sent (amendment A10): changing it would
 * orphan every remote document.
 */
final class SyncSettingsForm extends ConfigFormBase {

  use AutowireTrait;

  /**
   * The configuration name.
   */
  private const CONFIG = 'webassistant_connector.settings';

  /**
   * Constructs a SyncSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected DocumentMap $documentMap,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webassistant_connector_sync_settings';
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
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send content changes to the platform'),
      '#description' => $this->t('When disabled, changes are still recorded in the outbox and sent once synchronization is enabled again.'),
      '#config_target' => self::CONFIG . ':sync.enabled',
    ];

    $mapped = $this->documentMap->count();
    $form['source_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source ID prefix'),
      '#description' => $mapped > 0
        ? $this->t('Remote documents are identified as %example. The prefix cannot change while @count documents are mapped: run "drush webassistant:purge" first, change it, then index the sources again.', [
          '%example' => '{prefix}:node:42:es',
          '@count' => $mapped,
        ])
        : $this->t('Remote documents are identified as %example. It must be unique per Drupal installation sharing a knowledge base (lowercase letters, digits, dots, hyphens and underscores; up to 32 characters).', ['%example' => '{prefix}:node:42:es']),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'sync.source_id_prefix',
        toConfig: [self::class, 'trimToConfig'],
      ),
      '#maxlength' => 32,
      '#size' => 32,
      '#required' => TRUE,
      '#disabled' => $mapped > 0,
    ];
    $form['public_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Public base URL'),
      '#description' => $this->t('Absolute URL used to build the canonical URL of documents, for example %example. Required when cron or Drush run without the public host name. Leave empty to use the current request.', ['%example' => 'https://www.example.com']),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'sync.public_base_url',
        toConfig: [self::class, 'trimUrlToConfig'],
      ),
      '#maxlength' => 255,
    ];
    $form['default_locale'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Locale for content without language'),
      '#description' => $this->t('Platform locale (for example %es or %ptbr) of entities whose language is "undefined" or "not applicable".', [
        '%es' => 'es',
        '%ptbr' => 'pt-BR',
      ]),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'sync.default_locale',
        toConfig: [self::class, 'trimToConfig'],
      ),
      '#maxlength' => 5,
      '#size' => 8,
      '#required' => TRUE,
    ];

    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#open' => TRUE,
    ];
    $form['delivery']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Documents per ingestion request'),
      '#min' => 1,
      '#max' => 100,
      '#config_target' => self::CONFIG . ':sync.batch_size',
    ];
    $form['delivery']['process_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Events per queue or cron run'),
      '#min' => 1,
      '#max' => 100000,
      '#config_target' => self::CONFIG . ':sync.process_limit',
    ];
    $form['delivery']['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum delivery attempts'),
      '#description' => $this->t('Events that fail this many times move to the dead letter. Retries wait 30 seconds doubling up to one hour, or what the platform asks for.'),
      '#min' => 1,
      '#max' => 100,
      '#config_target' => self::CONFIG . ':sync.max_attempts',
    ];
    $form['delivery']['reconcile_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Reconciliation interval'),
      '#description' => $this->t('Seconds between automatic reconciliations of each knowledge base (0 disables them). Large sites should run "drush webassistant:reconcile" from system cron instead.'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#config_target' => self::CONFIG . ':sync.reconcile_interval',
    ];
    $form['delivery']['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to keep delivered events'),
      '#min' => 0,
      '#max' => 3650,
      '#config_target' => self::CONFIG . ':sync.retention_days',
    ];
    $form['cron_note'] = [
      '#type' => 'item',
      '#markup' => $this->t('The outbox is processed by the queue %queue. Run cron from the system (or "drush queue:run %queue") instead of relying on Automated Cron.', ['%queue' => 'webassistant_connector_dispatch']),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->documentMap->count() > 0) {
      $current = (string) $this->config(self::CONFIG)->get('sync.source_id_prefix');
      $submitted = trim((string) $form_state->getValue('source_id_prefix'));
      if ($submitted !== '' && $submitted !== $current) {
        $form_state->setErrorByName('source_id_prefix', $this->t('The prefix cannot change while documents are mapped.'));
      }
    }
    $url = trim((string) $form_state->getValue('public_base_url'));
    if ($url !== '' && !self::isValidBaseUrl($url)) {
      $form_state->setErrorByName('public_base_url', $this->t('The public base URL must be an absolute http or https URL without credentials, query string or fragment.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Trims a value before saving it (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return string
   *   The trimmed value.
   */
  public static function trimToConfig(mixed $value): string {
    return trim((string) $value);
  }

  /**
   * Trims a URL and removes its trailing slash (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return string
   *   The URL without trailing slash.
   */
  public static function trimUrlToConfig(mixed $value): string {
    return rtrim(trim((string) $value), '/');
  }

  /**
   * Whether a URL is a valid public base URL.
   *
   * @param string $url
   *   The URL.
   *
   * @return bool
   *   TRUE for an absolute http(s) URL without credentials, query or
   *   fragment.
   */
  public static function isValidBaseUrl(string $url): bool {
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return FALSE;
    }
    return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], TRUE)
      && !isset($parts['user'])
      && !isset($parts['pass'])
      && !isset($parts['query'])
      && !isset($parts['fragment']);
  }

}
