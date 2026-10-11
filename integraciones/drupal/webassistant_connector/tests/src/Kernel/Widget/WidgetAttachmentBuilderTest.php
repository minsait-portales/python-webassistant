<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Widget;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\webassistant_connector\Hook\WidgetHooks;
use Drupal\webassistant_connector\Widget\WidgetAttachmentBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the widget attachments, their cacheability and the visibility rules.
 *
 * @group webassistant_connector
 */
#[CoversClass(WidgetAttachmentBuilder::class)]
#[CoversClass(WidgetHooks::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class WidgetAttachmentBuilderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'path_alias', 'webassistant_connector'];

  /**
   * The builder.
   */
  private WidgetAttachmentBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'user', 'webassistant_connector']);
    $this->builder = $this->container->get('webassistant_connector.widget_attachment_builder');
  }

  /**
   * Sets a configuration value.
   */
  private function setConfig(string $key, mixed $value): void {
    $this->config('webassistant_connector.settings')->set($key, $value)->save();
  }

  /**
   * Makes a request the current one.
   *
   * @param string $path
   *   The path.
   * @param bool $admin
   *   Whether the route is an administration route.
   */
  private function pushRequest(string $path, bool $admin = FALSE): void {
    $request = Request::create($path);
    $request->setSession($this->container->get('request_stack')->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $admin ? 'test.admin' : 'test.page');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($path, [], [], $admin ? ['_admin_route' => TRUE] : []));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Asserts the shared cacheability of every widget decision.
   *
   * @param array<string, mixed> $build
   *   The render array.
   */
  private function assertWidgetCacheability(array $build): void {
    $this->assertContains('config:webassistant_connector.settings', $build['#cache']['tags']);
    foreach (WidgetAttachmentBuilder::CACHE_CONTEXTS as $context) {
      $this->assertContains($context, $build['#cache']['contexts']);
    }
  }

  /**
   * The drupalSettings shape for an anonymous visitor.
   */
  public function testBuildForAnonymous(): void {
    $build = $this->builder->build();

    $this->assertSame([WidgetAttachmentBuilder::LIBRARY], $build['#attached']['library']);
    $settings = $build['#attached']['drupalSettings']['webassistantConnector'];
    $this->assertSame([
      'loaderUrl',
      'apiBase',
      'siteId',
      'tokenUrl',
      'csrfTokenUrl',
      'hostActionUrl',
      'hostActions',
      'dataLayer',
      'instances',
    ], array_keys($settings));
    $this->assertSame('http://localhost:8000/widget/v1/loader.js', $settings['loaderUrl']);
    $this->assertSame('http://localhost:8000', $settings['apiBase']);
    $this->assertSame('site_demo', $settings['siteId']);
    $this->assertNull($settings['tokenUrl']);
    $this->assertStringEndsWith('/session/token', $settings['csrfTokenUrl']);
    $this->assertStringEndsWith('/webassistant/host-action/', $settings['hostActionUrl']);
    $this->assertSame([], $settings['hostActions']);
    $this->assertFalse($settings['dataLayer']);
    $this->assertSame([
      'site_demo:default:panel' => [
        'assistantId' => NULL,
        'locale' => 'en',
        'resultTarget' => NULL,
        'open' => FALSE,
        'position' => NULL,
      ],
    ], $settings['instances']);
    $this->assertWidgetCacheability($build);
  }

  /**
   * Users allowed to get a token see the token URL and their host actions.
   */
  public function testBuildForAuthorizedUser(): void {
    $this->createUser([], 'placeholder');
    $this->setUpCurrentUser(['name' => 'jane'], ['use webassistant connector']);
    $this->setConfig('widget.analytics_datalayer', TRUE);

    $build = $this->builder->build();
    $settings = $build['#attached']['drupalSettings']['webassistantConnector'];
    $this->assertStringEndsWith('/webassistant/token', (string) $settings['tokenUrl']);
    $this->assertIsArray($settings['hostActions']);
    $this->assertTrue($settings['dataLayer']);
    $this->assertWidgetCacheability($build);

    $this->setConfig('identity.authenticated', FALSE);
    $settings = $this->builder->build()['#attached']['drupalSettings']['webassistantConnector'];
    $this->assertNull($settings['tokenUrl']);
  }

  /**
   * Instance overrides and the loader instance ID.
   */
  public function testInstanceOverrides(): void {
    $this->setConfig('assistant_id', 'portal');
    $this->setConfig('widget.position', 'left');
    $this->setConfig('widget.pass_locale', FALSE);

    $instance = ['assistant_id' => 'catalog', 'result_target' => '#results', 'open' => TRUE, 'position' => 'right'];
    $settings = $this->builder->build($instance)['#attached']['drupalSettings']['webassistantConnector'];
    $this->assertSame([
      'site_demo:catalog:#results' => [
        'assistantId' => 'catalog',
        'locale' => NULL,
        'resultTarget' => '#results',
        'open' => TRUE,
        'position' => 'right',
      ],
    ], $settings['instances']);
    $this->assertSame('site_demo:catalog:#results', $this->builder->instanceKey($instance));

    // Empty or invalid overrides fall back to the configuration.
    $this->assertSame([
      'assistantId' => 'portal',
      'locale' => NULL,
      'resultTarget' => NULL,
      'open' => FALSE,
      'position' => 'left',
    ], $this->builder->resolveInstance(['assistant_id' => '', 'position' => 'top']));
    $this->setConfig('assistant_id', 'Not Valid');
    $this->assertSame('site_demo:default:panel', $this->builder->instanceKey());
  }

  /**
   * Nothing is attached when the widget cannot work, but cacheability stays.
   */
  public function testNotAvailable(): void {
    $this->setConfig('enabled', FALSE);
    $build = $this->builder->build();
    $this->assertArrayNotHasKey('#attached', $build);
    $this->assertWidgetCacheability($build);

    $this->setConfig('enabled', TRUE);
    $this->setConfig('site_id', 'Bad site');
    $this->assertArrayNotHasKey('#attached', $this->builder->build());

    $this->setConfig('site_id', 'site_demo');
    $this->setConfig('api.base_url', '');
    $this->assertFalse($this->builder->isAvailable());
  }

  /**
   * Visibility: admin routes, path patterns and negation.
   */
  public function testVisibility(): void {
    $this->pushRequest('/node/1');
    $this->assertTrue($this->builder->isVisibleOnCurrentRoute());

    // Empty pages show everywhere, whatever "negate" says (amendment C3).
    $this->setConfig('widget.visibility.negate', FALSE);
    $this->assertTrue($this->builder->isVisibleOnCurrentRoute());

    $this->setConfig('widget.visibility.pages', "/user/*\n/contact");
    $this->assertFalse($this->builder->isVisibleOnCurrentRoute());
    $this->pushRequest('/user/login');
    $this->assertTrue($this->builder->isVisibleOnCurrentRoute());

    $this->setConfig('widget.visibility.negate', TRUE);
    $this->assertFalse($this->builder->isVisibleOnCurrentRoute());
    $this->pushRequest('/node/1');
    $this->assertTrue($this->builder->isVisibleOnCurrentRoute());

    $this->pushRequest('/admin/config', TRUE);
    $this->assertFalse($this->builder->isVisibleOnCurrentRoute());
    $this->setConfig('widget.visibility.exclude_admin_routes', FALSE);
    $this->assertTrue($this->builder->isVisibleOnCurrentRoute());

    $this->setConfig('enabled', FALSE);
    $this->assertFalse($this->builder->isVisibleOnCurrentRoute());
  }

  /**
   * The page_attachments hook attaches the global instance only.
   */
  public function testPageAttachments(): void {
    $this->pushRequest('/node/1');
    $attachments = $this->invokePageAttachments();
    $this->assertContains(WidgetAttachmentBuilder::LIBRARY, $attachments['#attached']['library']);
    $this->assertWidgetCacheability($attachments);

    $this->setConfig('widget.placement', 'block');
    $attachments = $this->invokePageAttachments();
    $this->assertNotContains(WidgetAttachmentBuilder::LIBRARY, $attachments['#attached']['library'] ?? []);
    $this->assertArrayNotHasKey('webassistantConnector', $attachments['#attached']['drupalSettings'] ?? []);
    $this->assertWidgetCacheability($attachments);

    $this->setConfig('widget.placement', 'global');
    $this->pushRequest('/admin/config', TRUE);
    $attachments = $this->invokePageAttachments();
    $this->assertNotContains(WidgetAttachmentBuilder::LIBRARY, $attachments['#attached']['library'] ?? []);
    $this->assertWidgetCacheability($attachments);
  }

  /**
   * Invokes hook_page_attachments() like the HTML renderer.
   *
   * @return array<string, mixed>
   *   The attachments.
   */
  private function invokePageAttachments(): array {
    $attachments = [];
    $this->container->get('module_handler')->invokeAllWith('page_attachments', static function (callable $hook) use (&$attachments): void {
      $hook($attachments);
    });
    return $attachments;
  }

}
