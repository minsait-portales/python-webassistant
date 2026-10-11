<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Widget;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Executable\ExecutableManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webassistant_connector\HostAction\HostActionRegistry;
use Drupal\webassistant_connector\Locale\LocaleMapper;
use Drupal\webassistant_connector\Platform\Credentials;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Builds the widget #attached and #cache (design section 6).
 *
 * Single source of truth for the global placement (page_attachments) and the
 * block. drupalSettings.webassistantConnector = {loaderUrl, apiBase, siteId,
 * tokenUrl|null, csrfTokenUrl, hostActionUrl, hostActions, dataLayer,
 * instances: {<key>: {assistantId, locale, resultTarget, open, position}}}.
 * The instance key is the loader instance ID
 * "{siteId}:{assistantId|default}:{resultTarget|panel}" (amendment C2).
 *
 * Service: webassistant_connector.widget_attachment_builder.
 */
final class WidgetAttachmentBuilder {

  /**
   * Cache contexts of every widget render array (amendment C1).
   */
  public const CACHE_CONTEXTS = [
    'url.path',
    'user.permissions',
    'user.roles:authenticated',
    'languages:language_interface',
    'route.name',
  ];

  /**
   * The connector configuration name.
   */
  public const CONFIG_NAME = 'webassistant_connector.settings';

  /**
   * The widget library.
   */
  public const LIBRARY = 'webassistant_connector/widget';

  /**
   * Pattern of site and assistant IDs accepted by the loader.
   */
  public const ID_PATTERN = '/^[a-z][a-z0-9_-]{1,63}$/';

  /**
   * Path of the loader below the public API URL.
   */
  public const LOADER_PATH = '/widget/v1/loader.js';

  /**
   * Permission required to request an authenticated token.
   */
  public const TOKEN_PERMISSION = 'use webassistant connector';

  /**
   * Placeholder action name used to compute the host action URL prefix.
   */
  private const ACTION_PLACEHOLDER = 'placeholder.action';

  /**
   * Constructs a WidgetAttachmentBuilder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials (public API URL).
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Routing\AdminContext $adminContext
   *   The admin context.
   * @param \Drupal\Core\Executable\ExecutableManagerInterface $conditionManager
   *   The condition plugin manager (request_path, amendment C3).
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The URL generator.
   * @param \Drupal\webassistant_connector\HostAction\HostActionRegistry $hostActionRegistry
   *   The host action registry.
   * @param \Drupal\webassistant_connector\Locale\LocaleMapper $localeMapper
   *   The locale mapper.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack (route of the main request).
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Credentials $credentials,
    private readonly AccountProxyInterface $currentUser,
    private readonly AdminContext $adminContext,
    #[Autowire(service: 'plugin.manager.condition')]
    private readonly ExecutableManagerInterface $conditionManager,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly HostActionRegistry $hostActionRegistry,
    private readonly LocaleMapper $localeMapper,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds the attachments of one widget instance.
   *
   * @param array<string, mixed> $instance
   *   Instance overrides: assistant_id, result_target, open, position (the
   *   block configuration). Empty for the global instance.
   *
   * @return array<string, mixed>
   *   A render array fragment with #attached (library
   *   webassistant_connector/widget and drupalSettings) and #cache (tag
   *   config:webassistant_connector.settings and CACHE_CONTEXTS). When the
   *   widget cannot be shown (connector disabled, invalid site ID or no API
   *   URL) only #cache is returned. Never contains a token.
   */
  public function build(array $instance = []): array {
    $build = [];
    if (!$this->isAvailable()) {
      // Only #cache: callers detect a usable widget by the #attached key.
      $this->getCacheableMetadata()->applyTo($build);
      return $build;
    }
    $metadata = BubbleableMetadata::createFromObject($this->getCacheableMetadata());

    $config = $this->config();
    $resolved = $this->resolveInstance($instance);
    $publicUrl = $this->credentials->apiPublicUrl();

    $tokenUrl = NULL;
    if ($config->get('identity.authenticated') && $this->currentUser->isAuthenticated() && $this->currentUser->hasPermission(self::TOKEN_PERMISSION)) {
      $generated = $this->urlGenerator->generateFromRoute('webassistant_connector.token', [], [], TRUE);
      $metadata = $metadata->merge($generated);
      $tokenUrl = $generated->getGeneratedUrl();
    }
    $csrf = $this->urlGenerator->generateFromRoute('system.csrftoken', [], [], TRUE);
    $action = $this->urlGenerator->generateFromRoute('webassistant_connector.host_action', ['name' => self::ACTION_PLACEHOLDER], [], TRUE);
    $actionUrl = $action->getGeneratedUrl();
    $actionPrefix = str_ends_with($actionUrl, self::ACTION_PLACEHOLDER) ? substr($actionUrl, 0, -strlen(self::ACTION_PLACEHOLDER)) : $actionUrl;

    $hostActions = $this->hostActionRegistry->availableForWithCacheability($this->currentUser);
    $metadata = $metadata->merge($csrf)->merge($action)->merge(BubbleableMetadata::createFromObject($hostActions['cacheability']));

    $settings = [
      'loaderUrl' => $publicUrl . self::LOADER_PATH,
      'apiBase' => $publicUrl,
      'siteId' => $this->siteId(),
      'tokenUrl' => $tokenUrl,
      'csrfTokenUrl' => $csrf->getGeneratedUrl(),
      'hostActionUrl' => $actionPrefix,
      'hostActions' => $hostActions['names'],
      'dataLayer' => (bool) $config->get('widget.analytics_datalayer'),
      'instances' => [
        $this->instanceKey($instance) => $resolved,
      ],
    ];
    $metadata = $metadata->merge((new BubbleableMetadata())->setAttachments([
      'library' => [self::LIBRARY],
      'drupalSettings' => ['webassistantConnector' => $settings],
    ]));
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Whether the widget may be shown on the current route.
   *
   * @return bool
   *   FALSE when the connector is disabled, on admin routes (if excluded) or
   *   when widget.visibility rejects the current path. The visibility uses
   *   the core request_path condition (alias and system path); an empty page
   *   list shows the widget everywhere, whatever "negate" says (amendment
   *   C3).
   */
  public function isVisibleOnCurrentRoute(): bool {
    $config = $this->config();
    if (!$config->get('enabled')) {
      return FALSE;
    }
    if ($config->get('widget.visibility.exclude_admin_routes') && $this->isAdminRoute()) {
      return FALSE;
    }
    $pages = trim((string) $config->get('widget.visibility.pages'));
    if ($pages === '') {
      return TRUE;
    }
    /** @var \Drupal\Core\Condition\ConditionInterface $condition */
    $condition = $this->conditionManager->createInstance('request_path', ['pages' => $pages]);
    $matches = (bool) $condition->evaluate();
    return $config->get('widget.visibility.negate') ? !$matches : $matches;
  }

  /**
   * Whether the current or the main request is an administration route.
   *
   * The main request matters for error pages: a 403 or 404 page shown for an
   * administration path is rendered in a subrequest whose route is not an
   * administration route.
   *
   * @return bool
   *   TRUE on administration routes.
   */
  private function isAdminRoute(): bool {
    if ($this->adminContext->isAdminRoute()) {
      return TRUE;
    }
    $route = $this->requestStack->getMainRequest()?->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    return $route instanceof Route && $this->adminContext->isAdminRoute($route);
  }

  /**
   * Whether the widget can be rendered at all.
   *
   * @return bool
   *   TRUE when the connector is enabled, the site ID is valid and the public
   *   API URL is known.
   */
  public function isAvailable(): bool {
    return (bool) $this->config()->get('enabled')
      && preg_match(self::ID_PATTERN, $this->siteId()) === 1
      && preg_match('#^https?://#', $this->credentials->apiPublicUrl()) === 1;
  }

  /**
   * Returns the cacheability shared by every widget decision.
   *
   * Applied even when the widget is not attached, so that a page cached
   * without the widget changes when the settings, the user or the path do
   * (amendment C1).
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   Tag config:webassistant_connector.settings and CACHE_CONTEXTS.
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheTags(['config:' . self::CONFIG_NAME])
      ->addCacheContexts(self::CACHE_CONTEXTS);
  }

  /**
   * Resolves the effective settings of an instance.
   *
   * @param array<string, mixed> $instance
   *   Instance overrides (assistant_id, result_target, open, position); empty
   *   values fall back to the global configuration.
   *
   * @return array{assistantId: string|null, locale: string|null, resultTarget: string|null, open: bool, position: string|null}
   *   The instance settings exposed in drupalSettings.
   */
  public function resolveInstance(array $instance = []): array {
    $config = $this->config();
    $assistantId = self::stringValue($instance['assistant_id'] ?? NULL) ?? self::stringValue($config->get('assistant_id'));
    if ($assistantId !== NULL && !preg_match(self::ID_PATTERN, $assistantId)) {
      $assistantId = NULL;
    }
    $position = self::position($instance['position'] ?? NULL) ?? self::position($config->get('widget.position'));
    return [
      'assistantId' => $assistantId,
      'locale' => $config->get('widget.pass_locale') ? $this->localeMapper->currentLocale() : NULL,
      'resultTarget' => self::stringValue($instance['result_target'] ?? NULL) ?? self::stringValue($config->get('widget.result_target')),
      'open' => array_key_exists('open', $instance) ? (bool) $instance['open'] : (bool) $config->get('widget.open'),
      'position' => $position,
    ];
  }

  /**
   * Returns the loader instance ID of an instance (amendment C2).
   *
   * @param array<string, mixed> $instance
   *   Instance overrides, as for build().
   *
   * @return string
   *   "{siteId}:{assistantId|default}:{resultTarget|panel}", exactly the
   *   formula of the loader.
   */
  public function instanceKey(array $instance = []): string {
    $resolved = $this->resolveInstance($instance);
    return sprintf('%s:%s:%s', $this->siteId(), $resolved['assistantId'] ?? 'default', $resolved['resultTarget'] ?? 'panel');
  }

  /**
   * Returns the configured site ID.
   */
  private function siteId(): string {
    return trim((string) $this->config()->get('site_id'));
  }

  /**
   * Returns the connector configuration.
   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

  /**
   * Returns a valid widget position or NULL.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string|null
   *   "left", "right" or NULL.
   */
  private static function position(mixed $value): ?string {
    return in_array($value, ['left', 'right'], TRUE) ? $value : NULL;
  }

  /**
   * Returns a trimmed non-empty string or NULL.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string|null
   *   The string, or NULL if empty or not a string.
   */
  private static function stringValue(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

}
