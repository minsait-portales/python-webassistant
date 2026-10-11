<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector\Hook\WidgetHooks;
use Drupal\webassistant_connector\Plugin\Block\WidgetBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the global widget placement, its visibility and the widget block.
 *
 * @group webassistant_connector
 */
#[CoversClass(WidgetHooks::class)]
#[CoversClass(WidgetBlock::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class WidgetPlacementTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'webassistant_connector', 'webassistant_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Returns drupalSettings.webassistantConnector of the current page.
   *
   * @return array<string, mixed>|null
   *   The settings, or NULL when the widget is not attached.
   */
  private function widgetSettings(): ?array {
    return $this->getDrupalSettings()['webassistantConnector'] ?? NULL;
  }

  /**
   * Sets a connector configuration value.
   */
  private function setConfig(string $key, mixed $value): void {
    $this->config('webassistant_connector.settings')->set($key, $value)->save();
  }

  /**
   * The global placement on public pages, by user and visibility.
   */
  public function testGlobalPlacement(): void {
    $this->drupalGet('user/login');
    $settings = $this->widgetSettings();
    $this->assertNotNull($settings);
    $this->assertSame('site_demo', $settings['siteId']);
    $this->assertSame('http://localhost:8000/widget/v1/loader.js', $settings['loaderUrl']);
    $this->assertNull($settings['tokenUrl']);
    $this->assertArrayHasKey('site_demo:default:panel', $settings['instances']);
    $this->assertSession()->responseContains('js/webassistant-connector.js');
    // The token is never part of the page.
    $this->assertSession()->responseNotContains('fake-widget-token');

    $account = $this->drupalCreateUser(['use webassistant connector']);
    $this->drupalLogin($account);
    $this->drupalGet('user/' . $account->id());
    $settings = $this->widgetSettings();
    $this->assertStringEndsWith('/webassistant/token', (string) $settings['tokenUrl']);
    $this->assertStringEndsWith('/session/token', $settings['csrfTokenUrl']);
    $this->assertStringEndsWith('/webassistant/host-action/', $settings['hostActionUrl']);
    $this->drupalLogout();

    // Path visibility: hidden on the listed pages.
    $this->setConfig('widget.visibility.pages', '/user/login');
    $this->drupalGet('user/login');
    $this->assertNull($this->widgetSettings());
    $this->drupalGet('user/password');
    $this->assertNotNull($this->widgetSettings());

    // Only on the listed pages.
    $this->setConfig('widget.visibility.negate', FALSE);
    $this->drupalGet('user/password');
    $this->assertNull($this->widgetSettings());
    $this->drupalGet('user/login');
    $this->assertNotNull($this->widgetSettings());

    // Disabled connector.
    $this->setConfig('enabled', FALSE);
    $this->drupalGet('user/login');
    $this->assertNull($this->widgetSettings());
    $this->assertSession()->responseNotContains('js/webassistant-connector.js');
  }

  /**
   * The widget is not attached to administration pages.
   */
  public function testAdminRoutes(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'administer webassistant connector',
      'use webassistant connector',
    ]));
    $this->drupalGet('admin/config/services/webassistant-connector');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNull($this->widgetSettings());

    // Error pages of administration paths are rendered in a subrequest.
    $this->drupalGet('admin/people');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertNull($this->widgetSettings());

    $this->setConfig('widget.visibility.exclude_admin_routes', FALSE);
    $this->drupalGet('admin/config/services/webassistant-connector');
    $this->assertNotNull($this->widgetSettings());
  }

  /**
   * The block renders the mount point, the results container and settings.
   */
  public function testBlock(): void {
    $this->setConfig('widget.placement', 'block');
    $this->drupalPlaceBlock('webassistant_connector_widget', [
      'id' => 'wa_widget',
      'region' => 'content',
      'assistant_id' => 'catalog',
      'result_target' => '#wa-results',
      'render_result_container' => TRUE,
      'open' => TRUE,
    ]);

    $this->drupalGet('user/login');
    $assert = $this->assertSession();
    $assert->elementExists('css', 'div.webassistant-connector[data-webassistant-instance="site_demo:catalog:#wa-results"]');
    $assert->elementExists('css', 'div#wa-results.webassistant-connector__results[tabindex="-1"]');
    $settings = $this->widgetSettings();
    $this->assertSame(['site_demo:catalog:#wa-results'], array_keys($settings['instances']));
    $this->assertSame([
      'assistantId' => 'catalog',
      'locale' => 'en',
      'resultTarget' => '#wa-results',
      'open' => TRUE,
      'position' => NULL,
    ], $settings['instances']['site_demo:catalog:#wa-results']);

    // Disabled connector: the block renders nothing.
    $this->setConfig('enabled', FALSE);
    $this->drupalGet('user/login');
    $assert->elementNotExists('css', 'div.webassistant-connector');
    $this->assertNull($this->widgetSettings());
  }

  /**
   * The block form validates the selector and the assistant ID.
   */
  public function testBlockForm(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer blocks']));
    $this->drupalGet('admin/structure/block/add/webassistant_connector_widget/stark');
    $this->submitForm([
      'region' => 'content',
      'settings[assistant_id]' => 'Not Valid',
      'settings[result_target]' => '.results',
      'settings[render_result_container]' => TRUE,
    ], 'Save block');
    $this->assertSession()->pageTextContains('The assistant ID must start with a lowercase letter');
    $this->assertSession()->pageTextContains('the selector must be an ID selector');

    $this->submitForm([
      'id' => 'wa_widget_form',
      'region' => 'content',
      'settings[assistant_id]' => 'catalog',
      'settings[result_target]' => '#main-results',
      'settings[render_result_container]' => TRUE,
      'settings[position]' => 'left',
    ], 'Save block');
    $block = $this->container->get('entity_type.manager')->getStorage('block')->load('wa_widget_form');
    $this->assertNotNull($block);
    $settings = $block->get('settings');
    $this->assertSame('catalog', $settings['assistant_id']);
    $this->assertSame('#main-results', $settings['result_target']);
    $this->assertTrue($settings['render_result_container']);
    $this->assertSame('left', $settings['position']);
  }

}
