<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\PermissionHandlerInterface;
use Drupal\webassistant_connector\Identity\ScopeResolver;

/**
 * Identity settings and permission-to-scope map (route .identity).
 *
 * Edits identity.* of webassistant_connector.settings.
 */
final class IdentityForm extends ConfigFormBase {

  use AutowireTrait;

  /**
   * The configuration name.
   */
  private const CONFIG = 'webassistant_connector.settings';

  /**
   * Number of empty rows offered to add scope mappings.
   */
  private const EMPTY_ROWS = 3;

  /**
   * Constructs an IdentityForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\user\PermissionHandlerInterface $permissionHandler
   *   The permission handler (permission options).
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected PermissionHandlerInterface $permissionHandler,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webassistant_connector_identity';
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
    $form['authenticated'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Issue widget tokens for authenticated users'),
      '#description' => $this->t('Users with the "Use WebAssistant connector" permission get a temporary token that carries an opaque user ID, their roles and the scopes below. Anonymous visitors always get an anonymous widget session.'),
      '#config_target' => self::CONFIG . ':identity.authenticated',
    ];
    $form['token_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Token lifetime'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 60,
      '#max' => 3600,
      '#required' => TRUE,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'identity.token_ttl',
        toConfig: [SettingsForm::class, 'intToConfig'],
      ),
    ];
    $form['rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Token requests per user and minute'),
      '#min' => 1,
      '#max' => 1000,
      '#required' => TRUE,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'identity.rate_limit',
        toConfig: [SettingsForm::class, 'intToConfig'],
      ),
    ];
    $form['send_origin'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bind the token to the page origin'),
      '#description' => $this->t('Behind a reverse proxy, configure the trusted proxy settings so that Drupal sees the public scheme and host.'),
      '#config_target' => self::CONFIG . ':identity.send_origin',
    ];
    $form['send_roles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the user roles'),
      '#description' => $this->t('Roles let the platform filter documents whose access list contains role principals.'),
      '#config_target' => self::CONFIG . ':identity.send_roles',
    ];
    $form['base_scopes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Scopes granted to every authenticated user'),
      '#description' => $this->t('One scope per line. Keep %scopes: without them the assistant cannot read the knowledge bases or run actions. The platform drops scopes the site does not allow.', [
        '%scopes' => 'chat:use, knowledge:read, documents:read, actions:execute',
      ]),
      '#rows' => 5,
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'identity.base_scopes',
        fromConfig: [self::class, 'listToLines'],
        toConfig: [self::class, 'linesToList'],
      ),
    ];

    $form['scope_permissions'] = [
      '#type' => 'table',
      '#caption' => $this->t('Scopes granted by Drupal permissions'),
      '#header' => [$this->t('Scope'), $this->t('Permission')],
      '#empty' => $this->t('No mappings.'),
      '#tree' => TRUE,
      '#suffix' => '<div class="description">' . $this->t('A user gets the scope when they have the permission. Permissions that do not exist grant nothing. Leave the scope empty to remove a row.') . '</div>',
    ];
    $mappings = $this->config(self::CONFIG)->get('identity.scope_permissions') ?? [];
    $mappings = array_values(array_filter((array) $mappings, 'is_array'));
    $options = $this->permissionOptions($mappings);
    $rows = array_merge($mappings, array_fill(0, self::EMPTY_ROWS, ['scope' => '', 'permission' => '']));
    foreach ($rows as $delta => $mapping) {
      $form['scope_permissions'][$delta]['scope'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Scope'),
        '#title_display' => 'invisible',
        '#default_value' => (string) ($mapping['scope'] ?? ''),
        '#size' => 24,
        '#maxlength' => 64,
      ];
      $form['scope_permissions'][$delta]['permission'] = [
        '#type' => 'select',
        '#title' => $this->t('Permission'),
        '#title_display' => 'invisible',
        '#options' => $options,
        '#empty_value' => '',
        '#default_value' => (string) ($mapping['permission'] ?? ''),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $scopes = self::linesToList($form_state->getValue('base_scopes'));
    foreach ($scopes as $scope) {
      if (!ScopeResolver::isValidScope($scope)) {
        $form_state->setErrorByName('base_scopes', $this->t('%scope is not a valid scope (expected a value such as %example).', [
          '%scope' => $scope,
          '%example' => 'knowledge:read',
        ]));
        break;
      }
    }
    if (count($scopes) > ScopeResolver::MAX_SCOPES) {
      $form_state->setErrorByName('base_scopes', $this->t('At most @max scopes are allowed.', ['@max' => ScopeResolver::MAX_SCOPES]));
    }

    foreach ((array) $form_state->getValue('scope_permissions') as $delta => $row) {
      $scope = trim((string) ($row['scope'] ?? ''));
      $permission = (string) ($row['permission'] ?? '');
      if ($scope === '') {
        continue;
      }
      if (!ScopeResolver::isValidScope($scope)) {
        $form_state->setErrorByName("scope_permissions][$delta][scope", $this->t('%scope is not a valid scope (expected a value such as %example).', [
          '%scope' => $scope,
          '%example' => 'cart:read',
        ]));
      }
      if ($permission === '') {
        $form_state->setErrorByName("scope_permissions][$delta][permission", $this->t('Select the permission that grants the scope %scope.', ['%scope' => $scope]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mappings = [];
    foreach ((array) $form_state->getValue('scope_permissions') as $row) {
      $scope = trim((string) ($row['scope'] ?? ''));
      $permission = (string) ($row['permission'] ?? '');
      if ($scope !== '' && $permission !== '') {
        $mappings[$scope . "\0" . $permission] = ['scope' => $scope, 'permission' => $permission];
      }
    }
    parent::submitForm($form, $form_state);
    $this->configFactory()->getEditable(self::CONFIG)
      ->set('identity.scope_permissions', array_values($mappings))
      ->save();
  }

  /**
   * Converts a list to textarea lines (ConfigTarget::fromConfig).
   *
   * @param mixed $value
   *   The stored list.
   *
   * @return string
   *   One item per line.
   */
  public static function listToLines(mixed $value): string {
    return implode("\n", array_filter((array) $value, 'is_string'));
  }

  /**
   * Converts textarea lines to a unique list (ConfigTarget::toConfig).
   *
   * @param mixed $value
   *   The textarea value.
   *
   * @return list<string>
   *   Trimmed, unique, non-empty lines.
   */
  public static function linesToList(mixed $value): array {
    $lines = preg_split('/\R/', is_scalar($value) ? (string) $value : '') ?: [];
    $list = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $list[$line] = TRUE;
      }
    }
    return array_map('strval', array_keys($list));
  }

  /**
   * Builds the permission options, grouped by provider.
   *
   * Permissions referenced by the configuration that no module defines are
   * kept as options (marked as undefined) so that saving the form does not
   * lose them; they grant nothing until a module defines them.
   *
   * @param list<array<string, mixed>> $mappings
   *   The stored mappings.
   *
   * @return array<string, mixed>
   *   Select options.
   */
  private function permissionOptions(array $mappings): array {
    $options = [];
    $permissions = $this->permissionHandler->getPermissions();
    foreach ($permissions as $name => $permission) {
      $provider = (string) ($permission['provider'] ?? 'other');
      $options[$provider][$name] = strip_tags((string) ($permission['title'] ?? $name));
    }
    ksort($options);
    foreach ($mappings as $mapping) {
      $name = (string) ($mapping['permission'] ?? '');
      if ($name !== '' && !isset($permissions[$name])) {
        $options[(string) $this->t('Undefined permissions')][$name] = (string) $this->t('@permission (not defined)', ['@permission' => $name]);
      }
    }
    return $options;
  }

}
