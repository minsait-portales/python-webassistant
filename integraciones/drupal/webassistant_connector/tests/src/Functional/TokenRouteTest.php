<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector\Controller\TokenController;
use Drupal\webassistant_connector_test\FakePlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the widget token route: access, CSRF, caching headers and errors.
 *
 * @group webassistant_connector
 */
#[CoversClass(TokenController::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class TokenRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dblog', 'webassistant_connector', 'webassistant_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Sends a POST to the token route with the browser session.
   *
   * @param bool $withCsrf
   *   Whether to send the X-CSRF-Token header.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function postToken(bool $withCsrf = TRUE): ResponseInterface {
    $headers = ['Accept' => 'application/json'];
    if ($withCsrf) {
      $this->drupalGet('session/token');
      $headers['X-CSRF-Token'] = $this->getSession()->getPage()->getContent();
    }
    return $this->getHttpClient()->request('POST', $this->buildUrl('webassistant/token'), [
      'cookies' => $this->getSessionCookies(),
      'headers' => $headers,
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The body.
   */
  private static function json(ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Returns the number of widget tokens requested to the platform.
   */
  private function platformCalls(): int {
    return count(FakePlatformClient::calls($this->container->get('state'), 'issueWidgetToken'));
  }

  /**
   * Login, permission and the CSRF header are required.
   */
  public function testAccess(): void {
    $this->assertSame(403, $this->postToken(FALSE)->getStatusCode());

    $this->drupalLogin($this->drupalCreateUser([]));
    $this->assertSame(403, $this->postToken()->getStatusCode());

    $this->drupalLogin($this->drupalCreateUser(['use webassistant connector']));
    $response = $this->postToken(FALSE);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(0, $this->platformCalls());

    $get = $this->getHttpClient()->request('GET', $this->buildUrl('webassistant/token'), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
    $this->assertSame(405, $get->getStatusCode());
  }

  /**
   * A token is returned as non-cacheable JSON and reused from the session.
   */
  public function testToken(): void {
    $this->drupalLogin($this->drupalCreateUser(['use webassistant connector']));

    $response = $this->postToken();
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
    $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    $body = self::json($response);
    $this->assertSame(['token', 'expires_at'], array_keys($body));
    $this->assertSame('fake-widget-token-1', $body['token']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $body['expires_at']);

    $again = self::json($this->postToken());
    $this->assertSame($body['token'], $again['token']);
    $this->assertSame(1, $this->platformCalls());
  }

  /**
   * Errors are generic for the browser and logged with the correlation ID.
   */
  public function testErrors(): void {
    $this->drupalLogin($this->drupalCreateUser(['use webassistant connector']));
    $config = $this->config('webassistant_connector.settings');

    $config->set('identity.authenticated', FALSE)->save();
    $response = $this->postToken();
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(['error' => 'forbidden'], self::json($response));

    $config->set('identity.authenticated', TRUE)->save();
    $this->container->get('state')->set(FakePlatformClient::FAIL_KEY, TRUE);
    $response = $this->postToken();
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame(['error' => 'unavailable'], self::json($response));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertStringNotContainsString('Simulated', (string) $response->getBody());
    $logged = $this->container->get('database')->select('watchdog', 'w')
      ->condition('type', 'webassistant_connector')
      ->condition('variables', '%' . FakePlatformClient::CORRELATION_ID . '%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, (int) $logged);

    // Flood: every platform call counts.
    $this->container->get('state')->set(FakePlatformClient::FAIL_KEY, FALSE);
    $config->set('identity.rate_limit', 1)->save();
    $response = $this->postToken();
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('60', $response->getHeaderLine('Retry-After'));
    $this->assertSame(['error' => 'too_many_requests'], self::json($response));
  }

}
