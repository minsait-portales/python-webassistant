<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Validates and executes host actions (design section 7, amendment C5).
 *
 * Service: webassistant_connector.host_action_dispatcher.
 */
final class HostActionDispatcher {

  use StringTranslationTrait;

  /**
   * Expirable key/value collection used for idempotency.
   */
  public const IDEMPOTENCY_COLLECTION = 'webassistant_connector.host_action';

  /**
   * Seconds a result is kept for replays (24 h).
   */
  public const IDEMPOTENCY_TTL = 86400;

  /**
   * Seconds an in-progress marker blocks duplicates (crashed runs expire).
   */
  public const RUNNING_TTL = 60;

  /**
   * Session key holding the random per-session idempotency nonce.
   */
  public const SESSION_KEY = 'webassistant_connector.host_action_session';

  /**
   * Flood event name.
   */
  public const FLOOD_EVENT = 'webassistant_connector.host_action';

  /**
   * Host actions allowed per user (or IP for anonymous users) and window.
   */
  public const FLOOD_THRESHOLD = 60;

  /**
   * Flood window in seconds.
   */
  public const FLOOD_WINDOW = 60;

  /**
   * Constructs a HostActionDispatcher.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionManager $hostActionManager
   *   The host action plugin manager.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $keyValueExpirable
   *   The expirable key/value factory.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly HostActionManager $hostActionManager,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Dispatches a host action.
   *
   * Steps: plugin exists (404), payload valid and payload name equals $name
   * (400), plugin access (403), flood per user or IP (429), idempotency
   * replay (same result with data.replayed = TRUE, only for the same user and
   * session: key sha256(uid|session nonce|name|execution_id), 24 h),
   * in-progress marker per key (409 if concurrent), execution, redirect
   * sanitization and log with correlation_id. HostActionException becomes a
   * failed result; unexpected errors are logged and become a generic failed
   * result, stored for replay only when the action has effects (so it never
   * runs twice). Messages are returned as plain text.
   *
   * @param string $name
   *   The host action name from the route.
   * @param array<mixed> $payload
   *   The decoded JSON body.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (session for the idempotency key, client IP).
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result (HTTP 200).
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Invalid payload or name mismatch (400).
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Access denied (403).
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Unknown host action (404).
   * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
   *   Same execution running concurrently (409).
   * @throws \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException
   *   Too many host actions for the user or IP (429).
   */
  public function dispatch(string $name, array $payload, AccountInterface $account, Request $request): HostActionResult {
    if (!preg_match(HostActionRequest::NAME_PATTERN, $name) || !$this->hostActionManager->hasDefinition($name)) {
      $this->logger->notice('Unknown host action @name requested by user @uid.', [
        '@name' => mb_substr($name, 0, 128),
        '@uid' => $account->id(),
      ]);
      throw new NotFoundHttpException('Unknown host action.');
    }

    try {
      $hostRequest = HostActionRequest::fromArray($payload, $this->time->getCurrentTime());
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->notice('Invalid host action @name requested by user @uid: @reason', [
        '@name' => $name,
        '@uid' => $account->id(),
        '@reason' => $e->getMessage(),
      ]);
      throw new BadRequestHttpException('Invalid host action.', $e);
    }
    if ($hostRequest->name !== $name) {
      $this->logger->notice('Host action name mismatch: route @name, payload @payload_name (correlation @correlation).', [
        '@name' => $name,
        '@payload_name' => $hostRequest->name,
        '@correlation' => $hostRequest->correlationId,
      ]);
      throw new BadRequestHttpException('Invalid host action.');
    }

    try {
      $plugin = $this->hostActionManager->createInstance($name);
    }
    catch (PluginException $e) {
      $this->logger->error('Host action @name could not be instantiated (correlation @correlation): @message', [
        '@name' => $name,
        '@correlation' => $hostRequest->correlationId,
        '@message' => $e->getMessage(),
      ]);
      throw new NotFoundHttpException('Unknown host action.', $e);
    }

    if (!$plugin->access($account)->isAllowed()) {
      $this->logger->notice('Host action @name denied to user @uid (correlation @correlation).', [
        '@name' => $name,
        '@uid' => $account->id(),
        '@correlation' => $hostRequest->correlationId,
      ]);
      throw new AccessDeniedHttpException('Access denied.');
    }

    $floodIdentifier = $account->isAuthenticated() ? 'uid:' . $account->id() : 'ip:' . $request->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW, $floodIdentifier)) {
      $this->logger->warning('Host action flood limit reached by user @uid (correlation @correlation).', [
        '@uid' => $account->id(),
        '@correlation' => $hostRequest->correlationId,
      ]);
      throw new TooManyRequestsHttpException(self::FLOOD_WINDOW, 'Too many requests.');
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $floodIdentifier);

    $store = $this->keyValueExpirable->get(self::IDEMPOTENCY_COLLECTION);
    $key = $this->idempotencyKey($account, $request, $name, $hostRequest->executionId);

    $replay = $this->replay($store, $key, $hostRequest);
    if ($replay !== NULL) {
      return $replay;
    }
    $owner = Crypt::randomBytesBase64(16);
    $marker = ['state' => 'running', 'owner' => $owner, 'started' => $this->time->getCurrentTime()];
    if (!$store->setWithExpireIfNotExists($key, $marker, self::RUNNING_TTL)) {
      // Another request stored the marker or the result in the meantime.
      return $this->replay($store, $key, $hostRequest) ?? $this->conflict($hostRequest);
    }
    // setWithExpireIfNotExists() is not atomic in every backend: make sure
    // this request owns the marker before running the action.
    $current = $store->get($key);
    if (!is_array($current) || ($current['owner'] ?? NULL) !== $owner) {
      return $this->replay($store, $key, $hostRequest) ?? $this->conflict($hostRequest);
    }

    try {
      $result = $plugin->execute($hostRequest, $account);
    }
    catch (HostActionException $e) {
      $result = HostActionResult::failed($e->getMessage(), $e->getData());
    }
    catch (\Throwable $e) {
      $this->logger->error('Host action @name failed for user @uid (correlation @correlation): %type: @message', [
        '@name' => $name,
        '@uid' => $account->id(),
        '@correlation' => $hostRequest->correlationId,
        '%type' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      $failed = HostActionResult::failed((string) $this->t('The action could not be completed. Please try again later.'));
      if ($plugin->hasEffects()) {
        // Data may have changed before the error: never run it twice.
        $store->setWithExpire($key, ['state' => 'done', 'result' => $failed->toArray()], self::IDEMPOTENCY_TTL);
      }
      else {
        // Side-effect free: a retry of the same execution may run again.
        $store->delete($key);
      }
      return $failed;
    }

    $result = $this->sanitizeRedirect($result, $request, $hostRequest);
    if ($result->message !== NULL) {
      // Plugin messages are markup (t() escapes placeholders): answer text.
      $result = $result->withMessage(PlainTextOutput::renderFromHtml($result->message));
    }
    $store->setWithExpire($key, ['state' => 'done', 'result' => $result->toArray()], self::IDEMPOTENCY_TTL);

    $this->logger->info('Host action @name executed for user @uid: @status (execution @execution, correlation @correlation).', [
      '@name' => $name,
      '@uid' => $account->id(),
      '@status' => $result->status,
      '@execution' => $hostRequest->executionId,
      '@correlation' => $hostRequest->correlationId,
    ]);
    return $result;
  }

  /**
   * Computes the idempotency key of an execution.
   *
   * The key binds the result to the user, the browser session (a random
   * nonce stored in the session, so anonymous users get a session first) and
   * the action: results are never replayed to another user or session.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $name
   *   The host action name.
   * @param string $executionId
   *   The execution ID.
   *
   * @return string
   *   The key (sha256 hex).
   */
  private function idempotencyKey(AccountInterface $account, Request $request, string $name, string $executionId): string {
    return hash('sha256', implode('|', [(string) $account->id(), $this->sessionNonce($request), $name, $executionId]));
  }

  /**
   * Returns the per-session nonce, starting the session if needed.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string
   *   The nonce. Without a session a fresh random value is returned, which
   *   disables replays instead of sharing results between clients.
   */
  private function sessionNonce(Request $request): string {
    if (!$request->hasSession()) {
      return Crypt::randomBytesBase64(32);
    }
    $session = $request->getSession();
    if (!$session->isStarted()) {
      $session->start();
    }
    $nonce = $session->get(self::SESSION_KEY);
    if (!is_string($nonce) || strlen($nonce) < 32) {
      $nonce = Crypt::randomBytesBase64(32);
      $session->set(self::SESSION_KEY, $nonce);
    }
    return $nonce;
  }

  /**
   * Returns the stored result of a finished execution, flagged as replayed.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $store
   *   The idempotency store.
   * @param string $key
   *   The idempotency key.
   * @param \Drupal\webassistant_connector\HostAction\HostActionRequest $hostRequest
   *   The request.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult|null
   *   The replayed result, NULL if there is none.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
   *   When the same execution is still running.
   */
  private function replay(KeyValueStoreExpirableInterface $store, string $key, HostActionRequest $hostRequest): ?HostActionResult {
    $stored = $store->get($key);
    if (!is_array($stored)) {
      return NULL;
    }
    if (($stored['state'] ?? NULL) === 'done' && is_array($stored['result'] ?? NULL)) {
      $this->logger->info('Host action @name replayed (execution @execution, correlation @correlation).', [
        '@name' => $hostRequest->name,
        '@execution' => $hostRequest->executionId,
        '@correlation' => $hostRequest->correlationId,
      ]);
      return HostActionResult::fromArray($stored['result'])->withData(['replayed' => TRUE]);
    }
    $this->conflict($hostRequest);
  }

  /**
   * Throws the conflict exception of a concurrent duplicate.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionRequest $hostRequest
   *   The request.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
   */
  private function conflict(HostActionRequest $hostRequest): never {
    $this->logger->notice('Host action @name already running (execution @execution, correlation @correlation).', [
      '@name' => $hostRequest->name,
      '@execution' => $hostRequest->executionId,
      '@correlation' => $hostRequest->correlationId,
    ]);
    throw new ConflictHttpException('The action is already running.');
  }

  /**
   * Keeps only internal redirects of the current host (amendment C5).
   *
   * Root-relative paths are kept; absolute URLs of the same scheme, host and
   * port are reduced to their path; anything else is dropped and logged.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionResult $result
   *   The result.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\webassistant_connector\HostAction\HostActionRequest $hostRequest
   *   The host action request.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result with a safe redirect or none.
   */
  private function sanitizeRedirect(HostActionResult $result, Request $request, HostActionRequest $hostRequest): HostActionResult {
    if ($result->redirect === NULL) {
      return $result;
    }
    $safe = self::safeRedirect($result->redirect, $request->getSchemeAndHttpHost());
    if ($safe === $result->redirect) {
      return $result;
    }
    if ($safe === NULL) {
      $this->logger->warning('Host action @name returned an external or invalid redirect; it was dropped (correlation @correlation).', [
        '@name' => $hostRequest->name,
        '@correlation' => $hostRequest->correlationId,
      ]);
    }
    return $result->withRedirect($safe);
  }

  /**
   * Validates a redirect target against the current origin.
   *
   * @param string $redirect
   *   The redirect returned by a plugin.
   * @param string $origin
   *   The scheme and host of the current request (e.g. https://example.com).
   *
   * @return string|null
   *   A root-relative URL, or NULL if the target is not internal.
   */
  public static function safeRedirect(string $redirect, string $origin): ?string {
    if ($redirect === '' || strlen($redirect) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $redirect)) {
      return NULL;
    }
    if (str_starts_with($redirect, '/')) {
      return str_starts_with($redirect, '//') ? NULL : $redirect;
    }
    $parts = parse_url($redirect);
    $originParts = parse_url($origin);
    if ($parts === FALSE || $originParts === FALSE || !isset($parts['scheme'], $parts['host'], $originParts['scheme'], $originParts['host']) || isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }
    $defaultPorts = ['http' => 80, 'https' => 443];
    $scheme = strtolower($parts['scheme']);
    $originScheme = strtolower($originParts['scheme']);
    $port = $parts['port'] ?? ($defaultPorts[$scheme] ?? NULL);
    $originPort = $originParts['port'] ?? ($defaultPorts[$originScheme] ?? NULL);
    if ($scheme !== $originScheme || strtolower($parts['host']) !== strtolower($originParts['host']) || $port !== $originPort) {
      return NULL;
    }
    $path = $parts['path'] ?? '/';
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
      return NULL;
    }
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
  }

}
