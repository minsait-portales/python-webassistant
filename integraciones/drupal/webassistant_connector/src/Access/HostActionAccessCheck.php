<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webassistant_connector\HostAction\HostActionRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Access check of the host action route (design section 7, amendment C5).
 *
 * Used as _custom_access of webassistant_connector.host_action: requires the
 * "use webassistant connector" permission, the connector enabled, the header
 * X-WebAssistant-Request: 1 (not settable cross-site without a CORS
 * preflight) and an Origin header, or a Referer when the Origin is missing,
 * with the same scheme, host and port as the request. For sessions of
 * authenticated users the X-CSRF-Token header is checked by the route
 * requirement _csrf_request_header_token; anonymous users rely on the custom
 * header and the origin check.
 */
final class HostActionAccessCheck implements AccessInterface, ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Header that must be sent by the widget bridge.
   */
  public const REQUEST_HEADER = 'X-WebAssistant-Request';

  /**
   * Constructs a HostActionAccessCheck.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks access to the host action endpoint.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result (uncacheable: depends on request headers). Reasons
   *   are generic: they are returned to the browser in the JSON 403 body.
   */
  public function access(AccountInterface $account, Request $request): AccessResultInterface {
    if (!$this->configFactory->get('webassistant_connector.settings')->get('enabled')) {
      return AccessResult::forbidden('The connector is disabled.')->setCacheMaxAge(0);
    }
    $permission = AccessResult::allowedIfHasPermission($account, HostActionRegistry::PERMISSION);
    if (!$permission->isAllowed()) {
      return $permission->setCacheMaxAge(0);
    }
    if ($request->headers->get(self::REQUEST_HEADER) !== '1') {
      return AccessResult::forbidden('Missing or invalid request header.')->setCacheMaxAge(0);
    }
    if (!self::isSameOrigin($request)) {
      return AccessResult::forbidden('Cross-origin request.')->setCacheMaxAge(0);
    }
    return $permission->setCacheMaxAge(0);
  }

  /**
   * Whether the Origin (or, when missing, the Referer) is the request origin.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE if the browser declared the same scheme, host and port. A missing
   *   Origin and Referer, or "Origin: null", is rejected.
   */
  public static function isSameOrigin(Request $request): bool {
    $declared = $request->headers->get('Origin');
    if ($declared === NULL || $declared === '') {
      $declared = $request->headers->get('Referer');
    }
    if ($declared === NULL || $declared === '' || strtolower($declared) === 'null') {
      return FALSE;
    }
    $origin = self::normalizeOrigin($declared);
    return $origin !== NULL && $origin === self::normalizeOrigin($request->getSchemeAndHttpHost());
  }

  /**
   * Normalizes a URL to scheme://host:port (lowercase, explicit port).
   *
   * @param string $url
   *   An origin or absolute URL.
   *
   * @return string|null
   *   The normalized origin, or NULL if the URL is not http(s) or invalid.
   */
  private static function normalizeOrigin(string $url): ?string {
    if (preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
      return NULL;
    }
    $parts = parse_url($url);
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }
    $scheme = strtolower($parts['scheme']);
    $defaultPorts = ['http' => 80, 'https' => 443];
    if (!isset($defaultPorts[$scheme])) {
      return NULL;
    }
    $port = $parts['port'] ?? $defaultPorts[$scheme];
    return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
  }

}
