<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional\HostAction;

use Drupal\Core\Url;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * The host action route: access, CSRF, origin and JSON responses.
 *
 * @group webassistant_connector
 */
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class HostActionRouteTest extends BrowserTestBase {

  use ApiRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'webassistant_connector'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Counter for unique execution IDs.
   */
  private int $executions = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
  }

  /**
   * Anonymous users: permission, custom header, origin and JSON errors.
   */
  public function testAnonymous(): void {
    $response = $this->postAction('user.profile', []);
    $this->assertSame(403, $response->getStatusCode(), 'Anonymous users need the endpoint permission.');

    $role = Role::load(RoleInterface::ANONYMOUS_ID);
    $this->assertInstanceOf(RoleInterface::class, $role);
    $this->grantPermissions($role, ['use webassistant connector', 'use webassistant profile actions']);

    $response = $this->postAction('user.profile', []);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $body = $this->decode($response);
    $this->assertSame('succeeded', $body['status']);
    $this->assertSame(['login_required' => TRUE], $body['data']);
    $this->assertStringContainsString('/user/login?destination=', $body['redirect']);
    $this->assertStringStartsWith('/', $body['redirect']);
    // The session is started so that results are bound to this visitor.
    $this->assertNotSame('', $response->getHeaderLine('Set-Cookie'));

    // The custom header is required (no CSRF token without a session).
    $response = $this->postAction('user.profile', [], ['X-WebAssistant-Request' => NULL]);
    $this->assertSame(403, $response->getStatusCode());
    $response = $this->postAction('user.profile', [], ['X-WebAssistant-Request' => 'yes']);
    $this->assertSame(403, $response->getStatusCode());

    // The Origin (or the Referer) must be the site itself.
    $response = $this->postAction('user.profile', [], ['Origin' => 'https://evil.example']);
    $this->assertSame(403, $response->getStatusCode());
    $response = $this->postAction('user.profile', [], ['Origin' => NULL]);
    $this->assertSame(403, $response->getStatusCode());
    $response = $this->postAction('user.profile', [], ['Origin' => NULL, 'Referer' => $this->baseUrl . '/node/1']);
    $this->assertSame(200, $response->getStatusCode());

    // Protocol errors have a generic JSON body.
    $response = $this->postAction('user.profile', '{not json');
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame([
      'status' => 'failed',
      'error' => 'bad_request',
      'message' => 'The request is not valid.',
    ], $this->decode($response));

    $response = $this->postAction('cart.add', []);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('not_found', $this->decode($response)['error']);

    // The anonymous role lacks the content actions permission.
    $response = $this->postAction('content.create', ['content_type' => 'article']);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('forbidden', $this->decode($response)['error']);
  }

  /**
   * Authenticated users: CSRF token, happy path, replay and name mismatch.
   */
  public function testAuthenticated(): void {
    $editor = $this->drupalCreateUser([
      'use webassistant connector',
      'use webassistant content actions',
      'access content',
      'create article content',
    ]);
    $this->assertNotFalse($editor);
    $this->drupalLogin($editor);
    $this->drupalGet('session/token');
    $token = $this->getSession()->getPage()->getContent();
    $this->assertNotSame('', $token);

    $params = ['content_type' => 'article'];
    $response = $this->postAction('content.create', $params);
    $this->assertSame(403, $response->getStatusCode(), 'The CSRF token is required for sessions.');
    $response = $this->postAction('content.create', $params, ['X-CSRF-Token' => 'invalid']);
    $this->assertSame(403, $response->getStatusCode());
    $withoutHeader = ['X-CSRF-Token' => $token, 'X-WebAssistant-Request' => NULL];
    $response = $this->postAction('content.create', $params, $withoutHeader);
    $this->assertSame(403, $response->getStatusCode());
    $crossOrigin = ['X-CSRF-Token' => $token, 'Origin' => 'http://evil.example'];
    $response = $this->postAction('content.create', $params, $crossOrigin);
    $this->assertSame(403, $response->getStatusCode());

    $response = $this->postAction('content.create', $params, ['X-CSRF-Token' => $token], 'act_happy');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $body = $this->decode($response);
    $this->assertSame('succeeded', $body['status']);
    $this->assertStringEndsWith('/node/add/article', $body['redirect']);
    $this->assertSame('article', $body['data']['content_type']);
    $this->assertSame('Opening the form to create a new Article.', $body['message']);

    // The same execution is replayed.
    $response = $this->postAction('content.create', $params, ['X-CSRF-Token' => $token], 'act_happy');
    $this->assertSame(200, $response->getStatusCode());
    $replayed = $this->decode($response);
    $this->assertTrue($replayed['data']['replayed']);
    $this->assertSame($body['redirect'], $replayed['redirect']);

    // A failed business result is still a 200.
    $response = $this->postAction('content.create', ['content_type' => 'event'], ['X-CSRF-Token' => $token]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('failed', $this->decode($response)['status']);

    // The payload name must match the route.
    $payload = $this->payload('content.edit', ['content_title' => 'x']);
    $response = $this->postAction('content.create', $payload, ['X-CSRF-Token' => $token], NULL, FALSE);
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Posts a host action to the endpoint.
   *
   * @param string $name
   *   The action name in the URL.
   * @param array<string, mixed>|string $body
   *   Action params (wrapped in a valid payload) or a raw body.
   * @param array<string, string|null> $headers
   *   Headers overriding the defaults; NULL removes a header.
   * @param string|null $executionId
   *   The execution ID (unique if NULL).
   * @param bool $wrap
   *   Whether $body holds params to wrap in a payload.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function postAction(string $name, array|string $body, array $headers = [], ?string $executionId = NULL, bool $wrap = TRUE): ResponseInterface {
    $origin = parse_url($this->baseUrl);
    $defaults = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
      'X-WebAssistant-Request' => '1',
      'Origin' => $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : ''),
    ];
    $headers = array_filter(array_replace($defaults, $headers), static fn ($value) => $value !== NULL);
    if (is_array($body)) {
      $body = (string) json_encode($wrap ? $this->payload($name, $body, $executionId) : $body);
    }
    $url = Url::fromRoute('webassistant_connector.host_action', ['name' => $name]);
    return $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => $headers,
      RequestOptions::BODY => $body,
    ]);
  }

  /**
   * Builds a valid payload.
   *
   * @param string $name
   *   The action name.
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string|null $executionId
   *   The execution ID (unique if NULL).
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function payload(string $name, array $params, ?string $executionId = NULL): array {
    $executionId ??= 'act_route_' . (++$this->executions);
    return [
      'version' => '1',
      'name' => $name,
      'execution_id' => $executionId,
      'correlation_id' => $executionId,
      'skill' => 'test',
      'action' => 'test',
      'run_id' => NULL,
      'params' => (object) $params,
      'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 120),
    ];
  }

  /**
   * Decodes a JSON response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  private function decode(ResponseInterface $response): array {
    $decoded = json_decode((string) $response->getBody(), TRUE);
    $this->assertIsArray($decoded, (string) $response->getBody());
    return $decoded;
  }

}
