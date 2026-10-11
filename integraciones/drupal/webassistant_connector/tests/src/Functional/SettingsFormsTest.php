<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector\Form\IdentityForm;
use Drupal\webassistant_connector\Form\SettingsForm;
use Drupal\webassistant_connector_test\FakePlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the connection/widget settings form and the identity form.
 *
 * @group webassistant_connector
 */
#[CoversClass(SettingsForm::class)]
#[CoversClass(IdentityForm::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class SettingsFormsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webassistant_connector', 'webassistant_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer webassistant connector']));
  }

  /**
   * Returns the stored connector configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration, read from storage.
   */
  private function settings() {
    $this->container->get('config.factory')->reset('webassistant_connector.settings');
    return $this->container->get('config.factory')->get('webassistant_connector.settings');
  }

  /**
   * The settings form saves valid values and rejects invalid ones.
   */
  public function testSettingsForm(): void {
    $this->drupalGet('admin/config/services/webassistant-connector');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'api_base_url' => 'https://api.example.com/',
      'api_public_url' => '',
      'api_timeout' => 12,
      'api_connect_timeout' => 4,
      'client_id' => ' wa_portal ',
      'site_id' => 'portal_site',
      'assistant_id' => 'catalog',
      'placement' => 'block',
      'position' => 'right',
      'open' => TRUE,
      'result_target' => '#main-results',
      'pass_locale' => FALSE,
      'analytics_datalayer' => TRUE,
      'exclude_admin_routes' => FALSE,
      'pages' => "/user/*\n\n  /contact  ",
      'negate' => '0',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSession()->pageTextNotContains('does not use https');

    $config = $this->settings();
    $this->assertSame('https://api.example.com/', $config->get('api.base_url'));
    $this->assertSame(12, $config->get('api.timeout'));
    $this->assertSame(4, $config->get('api.connect_timeout'));
    $this->assertSame('wa_portal', $config->get('client_id'));
    $this->assertSame('portal_site', $config->get('site_id'));
    $this->assertSame('catalog', $config->get('assistant_id'));
    $this->assertSame('block', $config->get('widget.placement'));
    $this->assertSame('right', $config->get('widget.position'));
    $this->assertTrue($config->get('widget.open'));
    $this->assertSame('#main-results', $config->get('widget.result_target'));
    $this->assertFalse($config->get('widget.pass_locale'));
    $this->assertTrue($config->get('widget.analytics_datalayer'));
    $this->assertFalse($config->get('widget.visibility.exclude_admin_routes'));
    $this->assertSame("/user/*\n/contact", $config->get('widget.visibility.pages'));
    $this->assertFalse($config->get('widget.visibility.negate'));

    // Invalid values are rejected and nothing is saved.
    $this->submitForm([
      'api_base_url' => 'ftp://api.example.com',
      'site_id' => 'Bad Site',
      'assistant_id' => 'Bad!',
      'result_target' => '<script>',
      'pages' => 'user/login',
      'api_timeout' => 500,
    ], 'Save configuration');
    $assert = $this->assertSession();
    $assert->pageTextContains('The URL must be an absolute http or https URL');
    $assert->pageTextContains('The site ID must start with a lowercase letter');
    $assert->pageTextContains('The assistant ID must start with a lowercase letter');
    $assert->pageTextContains('The results container selector is not a valid CSS selector.');
    $assert->pageTextContains('requires a leading forward slash');
    $assert->pageTextContains('must be lower than or equal to 120');
    $this->assertSame('portal_site', $this->settings()->get('site_id'));

    // Plain http outside localhost is accepted with a warning.
    $this->submitForm([
      'api_base_url' => 'http://api.example.com',
      'site_id' => 'portal_site',
      'assistant_id' => '',
      'result_target' => '',
      'pages' => '',
      'api_timeout' => 10,
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $assert->pageTextContains('does not use https');
    $this->assertSame('http://api.example.com', $this->settings()->get('api.base_url'));
  }

  /**
   * Secrets are never shown; overrides are reported; the test button works.
   */
  public function testCredentialsAndConnectionTest(): void {
    $settings['settings']['webassistant_connector'] = (object) [
      'value' => [
        'client_secret' => 'very-secret-value',
        'api_base_url' => 'https://internal.example.test',
      ],
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    $this->drupalGet('admin/config/services/webassistant-connector');
    $assert = $this->assertSession();
    $assert->pageTextContains('Credential sources');
    $assert->pageTextContains('Configured in settings.php');
    $assert->pageTextContains('Derived from the site hash salt');
    $assert->pageTextContains('Overridden in settings.php: the value below is ignored.');
    $assert->responseNotContains('very-secret-value');
    $assert->responseNotContains('internal.example.test');

    // The API URL may be empty when settings.php defines it.
    $this->submitForm(['api_base_url' => ''], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');

    $this->submitForm([], 'Test connection');
    $assert->pageTextContains('Platform API: ready.');
    $assert->pageTextContains('Site site_test accepts the origin');
    $assert->pageTextContains('The API client credentials are valid');
    $calls = array_column(FakePlatformClient::calls($this->container->get('state')), 0);
    $this->assertSame(['health', 'bootstrap', 'serverToken'], $calls);

    $this->container->get('state')->set(FakePlatformClient::FAIL_KEY, TRUE);
    $this->submitForm([], 'Test connection');
    $assert->pageTextContains('Platform API health check failed');
    $assert->pageTextContains(FakePlatformClient::CORRELATION_ID);
    $assert->pageTextContains('The API client credentials were rejected');
  }

  /**
   * The identity form saves options, scopes and the scope map.
   */
  public function testIdentityForm(): void {
    $this->drupalGet('admin/config/services/webassistant-connector/identity');
    $this->assertSession()->statusCodeEquals(200);
    // Permissions that no module defines are kept and marked.
    $this->assertSession()->pageTextContains('use webassistant cart actions (not defined)');

    $this->submitForm([
      'authenticated' => TRUE,
      'token_ttl' => 600,
      'rate_limit' => 10,
      'send_origin' => FALSE,
      'send_roles' => TRUE,
      'base_scopes' => "chat:use\nknowledge:read\n\nchat:use\nactions:execute",
      'scope_permissions[0][scope]' => '',
      'scope_permissions[6][scope]' => 'profile:read',
      'scope_permissions[6][permission]' => 'use webassistant profile actions',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->settings();
    $this->assertSame(600, $config->get('identity.token_ttl'));
    $this->assertSame(10, $config->get('identity.rate_limit'));
    $this->assertFalse($config->get('identity.send_origin'));
    $this->assertSame(['chat:use', 'knowledge:read', 'actions:execute'], $config->get('identity.base_scopes'));
    $mappings = $config->get('identity.scope_permissions');
    $this->assertNotContains(['scope' => 'catalog:read', 'permission' => 'access content'], $mappings);
    $this->assertContains(['scope' => 'cart:read', 'permission' => 'use webassistant cart actions'], $mappings);
    $this->assertContains(['scope' => 'profile:read', 'permission' => 'use webassistant profile actions'], $mappings);

    $this->submitForm([
      'token_ttl' => 30,
      'base_scopes' => "chat:use\nNot A Scope",
      'scope_permissions[1][scope]' => 'cart read',
    ], 'Save configuration');
    $assert = $this->assertSession();
    $assert->pageTextContains('must be higher than or equal to 60');
    $assert->pageTextContains('Not A Scope is not a valid scope');
    $assert->pageTextContains('cart read is not a valid scope');
    $this->assertSame(600, $this->settings()->get('identity.token_ttl'));
  }

}
