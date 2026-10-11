<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Identity;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drupal\webassistant_connector\Event\WidgetTokenEvent;
use Drupal\webassistant_connector\Identity\WidgetTokenManager;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector_test\FakePlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Tests widget token issuing: session cache, fingerprint, flood and events.
 *
 * @group webassistant_connector
 */
#[CoversClass(WidgetTokenManager::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class WidgetTokenManagerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'webassistant_connector', 'webassistant_connector_test'];

  /**
   * The token manager.
   */
  private WidgetTokenManager $manager;

  /**
   * A user allowed to request tokens.
   */
  private UserInterface $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user', 'webassistant_connector']);
    // Keep uid 1 (super user) out of the tests.
    $this->createUser([], 'placeholder');
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();
    Role::create(['id' => 'reviewer', 'label' => 'Reviewer'])->save();
    Role::create(['id' => 'member', 'label' => 'Member'])
      ->grantPermission('use webassistant connector')
      ->grantPermission('use webassistant content actions')
      ->save();
    $this->user = $this->createUser([], 'jane', FALSE, ['roles' => ['member', 'editor']]);
    $this->manager = $this->container->get('webassistant_connector.widget_token_manager');
  }

  /**
   * Builds a token request with its own session.
   *
   * @param string $host
   *   The scheme and host.
   * @param \Symfony\Component\HttpFoundation\Session\Session|null $session
   *   The session to reuse.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function request(string $host = 'https://www.example.test', ?Session $session = NULL): Request {
    $request = Request::create($host . '/webassistant/token', 'POST');
    $request->setSession($session ?? new Session(new MockArraySessionStorage()));
    return $request;
  }

  /**
   * Returns the widget token requests sent to the platform.
   *
   * @return list<array<string, mixed>>
   *   The WidgetTokenRequest::toArray() of each call.
   */
  private function sentRequests(): array {
    return array_map(static fn (array $call): array => $call[1][0], FakePlatformClient::calls($this->container->get('state'), 'issueWidgetToken'));
  }

  /**
   * The token carries the identity and is reused from the session.
   */
  public function testIssuesAndReusesToken(): void {
    $request = $this->request();
    $token = $this->manager->getToken($this->user, $request);
    $this->assertSame('fake-widget-token-1', $token->accessToken);

    $sent = $this->sentRequests();
    $this->assertCount(1, $sent);
    $this->assertSame([
      'grant_type' => 'widget_token',
      'user' => [
        'id' => $this->container->get('webassistant_connector.identity_mapper')->userId($this->user),
        'roles' => ['editor', 'member'],
        'groups' => [],
        'locale' => 'en',
      ],
      'scopes' => ['actions:execute', 'chat:use', 'content:write', 'documents:read', 'knowledge:read'],
      'ttl_seconds' => 900,
      'origin' => 'https://www.example.test',
    ], $sent[0]);

    $again = $this->manager->getToken($this->user, $request);
    $this->assertSame($token->accessToken, $again->accessToken);
    $this->assertSame($token->expiresAt, $again->expiresAt);
    $this->assertCount(1, $this->sentRequests());
    $stored = $request->getSession()->get(WidgetTokenManager::SESSION_KEY);
    $this->assertSame($token->accessToken, $stored['token']);
  }

  /**
   * A different identity (roles, origin, configuration) gets a new token.
   */
  public function testFingerprintChange(): void {
    $session = new Session(new MockArraySessionStorage());
    $first = $this->manager->getToken($this->user, $this->request('https://www.example.test', $session));

    $this->user->addRole('reviewer');
    $this->user->save();
    $second = $this->manager->getToken($this->user, $this->request('https://www.example.test', $session));
    $this->assertNotSame($first->accessToken, $second->accessToken);
    $this->assertSame(['editor', 'member', 'reviewer'], $this->sentRequests()[1]['user']['roles']);

    $third = $this->manager->getToken($this->user, $this->request('https://other.example.test', $session));
    $this->assertNotSame($second->accessToken, $third->accessToken);

    $this->config('webassistant_connector.settings')->set('identity.send_roles', FALSE)->save();
    $this->manager->getToken($this->user, $this->request('https://other.example.test', $session));
    $sent = $this->sentRequests();
    $this->assertCount(4, $sent);
    $this->assertSame([], $sent[3]['user']['roles']);
  }

  /**
   * A session token about to expire is renewed.
   */
  public function testExpiringTokenIsRenewed(): void {
    $request = $this->request();
    $this->manager->getToken($this->user, $request);
    $stored = $request->getSession()->get(WidgetTokenManager::SESSION_KEY);
    $stored['expires_at'] = $this->container->get('datetime.time')->getCurrentTime() + WidgetTokenManager::MIN_REMAINING - 1;
    $request->getSession()->set(WidgetTokenManager::SESSION_KEY, $stored);

    $renewed = $this->manager->getToken($this->user, $request);
    $this->assertSame('fake-widget-token-2', $renewed->accessToken);
    $this->assertCount(2, $this->sentRequests());
  }

  /**
   * The flood limit answers 429 with the window as Retry-After.
   */
  public function testFlood(): void {
    $this->config('webassistant_connector.settings')->set('identity.rate_limit', 2)->save();
    $this->manager->getToken($this->user, $this->request());
    $this->manager->getToken($this->user, $this->request());
    try {
      $this->manager->getToken($this->user, $this->request());
      $this->fail('Expected a TooManyRequestsHttpException.');
    }
    catch (TooManyRequestsHttpException $e) {
      $this->assertSame(429, $e->getStatusCode());
      $this->assertSame((string) WidgetTokenManager::FLOOD_WINDOW, (string) $e->getHeaders()['Retry-After']);
    }
    $this->assertCount(2, $this->sentRequests());

    // The limit is per user.
    $other = $this->createUser(['use webassistant connector'], 'john');
    $this->assertNotEmpty($this->manager->getToken($other, $this->request())->accessToken);
  }

  /**
   * Tokens are refused when identity is disabled and for anonymous users.
   */
  public function testDisabled(): void {
    $this->config('webassistant_connector.settings')->set('identity.authenticated', FALSE)->save();
    try {
      $this->manager->getToken($this->user, $this->request());
      $this->fail('Expected an AccessDeniedHttpException.');
    }
    catch (AccessDeniedHttpException) {
    }

    $this->config('webassistant_connector.settings')->set('identity.authenticated', TRUE)->set('enabled', FALSE)->save();
    try {
      $this->manager->getToken($this->user, $this->request());
      $this->fail('Expected an AccessDeniedHttpException.');
    }
    catch (AccessDeniedHttpException) {
    }

    $this->config('webassistant_connector.settings')->set('enabled', TRUE)->save();
    $this->expectException(AccessDeniedHttpException::class);
    $this->manager->getToken($this->container->get('current_user')->getAccount(), $this->request());
  }

  /**
   * WidgetTokenEvent subscribers adjust groups and scopes; values are checked.
   */
  public function testEventSubscriber(): void {
    $this->container->get('event_dispatcher')->addListener(WidgetTokenEvent::NAME, static function (WidgetTokenEvent $event): void {
      $event->setGroups(['team-a', 'not valid']);
      $event->setScopes(['Invalid scope']);
    });
    $this->manager->getToken($this->user, $this->request());
    $sent = $this->sentRequests()[0];
    $this->assertSame(['team-a'], $sent['user']['groups']);
    // An empty scope list would mean "every scope": chat:use is sent instead.
    $this->assertSame(['chat:use'], $sent['scopes']);
  }

  /**
   * Platform failures propagate and nothing is cached.
   */
  public function testPlatformFailure(): void {
    $this->container->get('state')->set(FakePlatformClient::FAIL_KEY, TRUE);
    $request = $this->request();
    try {
      $this->manager->getToken($this->user, $request);
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(FakePlatformClient::CORRELATION_ID, $e->getCorrelationId());
    }
    $this->assertNull($request->getSession()->get(WidgetTokenManager::SESSION_KEY));
  }

}
