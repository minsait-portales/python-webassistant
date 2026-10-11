<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Identity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webassistant_connector\Event\WidgetTokenEvent;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\TokenResult;
use Drupal\webassistant_connector\Platform\WidgetTokenRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Issues widget tokens for Drupal users (design section 5).
 *
 * Service: webassistant_connector.widget_token_manager.
 */
final class WidgetTokenManager {

  /**
   * Session key holding {token, expires_at, fingerprint, scopes}.
   */
  public const SESSION_KEY = 'webassistant_connector.token';

  /**
   * Flood event name.
   */
  public const FLOOD_EVENT = 'webassistant_connector.token';

  /**
   * Flood window in seconds (also the Retry-After of a 429, amendment C4).
   */
  public const FLOOD_WINDOW = 60;

  /**
   * Seconds that a session token must still be valid to be reused.
   */
  public const MIN_REMAINING = 60;

  /**
   * Scope sent when no other scope applies (an empty list means "all").
   */
  public const MINIMUM_SCOPE = 'chat:use';

  /**
   * Constructs a WidgetTokenManager.
   *
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Identity\IdentityMapper $identityMapper
   *   The identity mapper.
   * @param \Drupal\webassistant_connector\Identity\ScopeResolver $scopeResolver
   *   The scope resolver.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher (WidgetTokenEvent).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly PlatformClientInterface $platformClient,
    private readonly IdentityMapper $identityMapper,
    private readonly ScopeResolver $scopeResolver,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FloodInterface $flood,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns a widget token for the account.
   *
   * Reuses the token stored in the session (SESSION_KEY) when its
   * fingerprint (site + uid + scopes + roles + groups + locale + origin)
   * matches and more than MIN_REMAINING seconds remain. Otherwise applies
   * Flood (FLOOD_EVENT, identity.rate_limit per user and minute), dispatches
   * WidgetTokenEvent, builds the WidgetTokenRequest (origin =
   * $request->getSchemeAndHttpHost() when identity.send_origin) and calls the
   * platform.
   *
   * The token is not bound to an assistant: one page may hold widget
   * instances of different assistants and all of them share this token.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The token request (session, origin).
   *
   * @return \Drupal\webassistant_connector\Platform\TokenResult
   *   The token.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the connector or identity.authenticated is disabled.
   * @throws \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException
   *   When the flood limit is exceeded (with Retry-After).
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the platform fails.
   */
  public function getToken(AccountInterface $account, Request $request): TokenResult {
    $config = $this->configFactory->get('webassistant_connector.settings');
    if (!$config->get('enabled') || !$config->get('identity.authenticated') || $account->isAnonymous()) {
      throw new AccessDeniedHttpException('Authenticated widget tokens are disabled.');
    }

    $tokenRequest = $this->buildRequest($account, $request);
    $fingerprint = hash('sha256', $config->get('site_id') . '|' . $tokenRequest->fingerprint());
    $now = $this->time->getCurrentTime();

    $session = $request->hasSession() ? $request->getSession() : NULL;
    $cached = $session?->get(self::SESSION_KEY);
    if (is_array($cached)
      && is_string($cached['token'] ?? NULL)
      && is_int($cached['expires_at'] ?? NULL)
      && is_string($cached['fingerprint'] ?? NULL)
      && hash_equals($cached['fingerprint'], $fingerprint)
      && $cached['expires_at'] - self::MIN_REMAINING > $now) {
      return new TokenResult($cached['token'], $cached['expires_at'], is_array($cached['scopes'] ?? NULL) ? $cached['scopes'] : []);
    }

    $limit = max(1, (int) ($config->get('identity.rate_limit') ?: 30));
    $identifier = 'uid:' . $account->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, self::FLOOD_WINDOW, $identifier)) {
      throw new TooManyRequestsHttpException(self::FLOOD_WINDOW, 'Too many widget token requests.');
    }
    // Every platform call counts, so a failing platform is not hammered.
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);

    $token = $this->platformClient->issueWidgetToken($tokenRequest);
    $session?->set(self::SESSION_KEY, [
      'token' => $token->accessToken,
      'expires_at' => $token->expiresAt,
      'fingerprint' => $fingerprint,
      'scopes' => $token->scopes,
    ]);
    return $token;
  }

  /**
   * Builds the platform request for an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\webassistant_connector\Platform\WidgetTokenRequest
   *   The token request.
   */
  private function buildRequest(AccountInterface $account, Request $request): WidgetTokenRequest {
    $config = $this->configFactory->get('webassistant_connector.settings');
    $roles = $config->get('identity.send_roles') ? $this->identityMapper->roles($account) : [];
    $event = new WidgetTokenEvent($account, $request, $roles, [], $this->scopeResolver->resolve($account));
    $this->eventDispatcher->dispatch($event, WidgetTokenEvent::NAME);

    $ttl = (int) ($config->get('identity.token_ttl') ?: 900);
    return new WidgetTokenRequest(
      userId: $this->identityMapper->userId($account),
      roles: $this->sanitizeIds($event->getRoles(), 'role'),
      groups: $this->sanitizeIds($event->getGroups(), 'group'),
      locale: $this->identityMapper->locale(),
      scopes: $this->sanitizeScopes($event->getScopes()),
      origin: $config->get('identity.send_origin') ? $request->getSchemeAndHttpHost() : NULL,
      ttl: max(WidgetTokenRequest::MIN_TTL, min(WidgetTokenRequest::MAX_TTL, $ttl)),
    );
  }

  /**
   * Keeps the identifiers usable in platform ACLs (amendment B4).
   *
   * @param list<string> $values
   *   Role or group IDs.
   * @param string $kind
   *   Either role or group (for the log).
   *
   * @return list<string>
   *   Valid IDs, at most WidgetTokenRequest::MAX_ITEMS.
   */
  private function sanitizeIds(array $values, string $kind): array {
    $valid = [];
    foreach ($values as $value) {
      if (preg_match(IdentityMapper::ID_PATTERN, $value)) {
        $valid[] = $value;
      }
      else {
        $this->logger->warning('The @kind @id is not sent to the WebAssistant platform: it does not match [A-Za-z0-9_.@-].', [
          '@kind' => $kind,
          '@id' => $value,
        ]);
      }
    }
    if (count($valid) > WidgetTokenRequest::MAX_ITEMS) {
      $this->logger->warning('Only the first @max @kind IDs are sent to the WebAssistant platform.', [
        '@max' => WidgetTokenRequest::MAX_ITEMS,
        '@kind' => $kind,
      ]);
      $valid = array_slice($valid, 0, WidgetTokenRequest::MAX_ITEMS);
    }
    return $valid;
  }

  /**
   * Keeps valid scopes (at most WidgetTokenRequest::MAX_ITEMS).
   *
   * @param list<string> $scopes
   *   The scopes.
   *
   * @return list<string>
   *   Valid scopes. Never empty: the platform grants every allowed scope
   *   when the list is empty, so "chat:use" (always granted) is sent instead.
   */
  private function sanitizeScopes(array $scopes): array {
    $scopes = array_values(array_filter($scopes, [ScopeResolver::class, 'isValidScope']));
    if ($scopes === []) {
      return [self::MINIMUM_SCOPE];
    }
    return array_slice($scopes, 0, WidgetTokenRequest::MAX_ITEMS);
  }

}
