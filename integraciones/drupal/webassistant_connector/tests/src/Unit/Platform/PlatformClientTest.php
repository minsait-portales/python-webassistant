<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\Platform;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Platform\PlatformClient;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Platform\WidgetTokenRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Tests the Guzzle platform client against mocked API responses.
 *
 * @group webassistant_connector
 */
#[CoversClass(PlatformClient::class)]
#[Group('webassistant_connector')]
final class PlatformClientTest extends UnitTestCase {

  /**
   * The API URL (with a path prefix, which must be kept).
   */
  private const API_URL = 'https://api.example.test/assistant';

  /**
   * The client secret used in the tests.
   */
  private const SECRET = 'top-secret-value';

  /**
   * The mocked HTTP handler.
   */
  private MockHandler $mock;

  /**
   * Requests sent, from the Guzzle history middleware.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $history = [];

  /**
   * The current time returned by the time service.
   */
  private int $now = 1800000000;

  /**
   * The client under test.
   */
  private PlatformClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->client = $this->createClient();
  }

  /**
   * Builds a client with the given settings and configuration.
   *
   * @param array<string, string> $credentials
   *   Values of $settings['webassistant_connector'].
   * @param array<string, mixed> $config
   *   Overrides of webassistant_connector.settings.
   *
   * @return \Drupal\webassistant_connector\Platform\PlatformClient
   *   The client.
   */
  private function createClient(array $credentials = [], array $config = []): PlatformClient {
    $settings = new Settings([
      'webassistant_connector' => $credentials + [
        'client_id' => 'wa_test_client',
        'client_secret' => self::SECRET,
        'api_base_url' => self::API_URL . '/',
      ],
    ]);
    $configFactory = $this->getConfigFactoryStub([
      'webassistant_connector.settings' => $config + [
        'site_id' => 'site_demo',
        'assistant_id' => '',
        'api' => ['timeout' => 7, 'connect_timeout' => 3],
      ],
    ]);
    $this->mock = new MockHandler();
    $this->history = [];
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn (): int => $this->now);
    return new PlatformClient(
      new ClientFactory($stack),
      new Credentials($settings, $configFactory),
      $configFactory,
      new PhpUuid(),
      $time,
      new NullLogger(),
    );
  }

  /**
   * Queues a JSON response.
   *
   * @param int $status
   *   The HTTP status.
   * @param array<string, mixed> $body
   *   The body.
   * @param array<string, string> $headers
   *   Extra headers.
   */
  private function queueJson(int $status, array $body, array $headers = []): void {
    $type = $status >= 400 ? 'application/problem+json' : 'application/json';
    $this->mock->append(new Response($status, ['Content-Type' => $type] + $headers, json_encode($body, JSON_THROW_ON_ERROR)));
  }

  /**
   * Queues a successful /v1/token response.
   *
   * @param string $token
   *   The access token.
   * @param int $ttl
   *   Seconds until expiration.
   */
  private function queueToken(string $token = 'server-token', int $ttl = 600): void {
    $this->queueJson(200, [
      'access_token' => $token,
      'token_type' => 'Bearer',
      'expires_in' => $ttl,
      'expires_at' => gmdate('Y-m-d\TH:i:s', $this->now + $ttl) . '.123456+00:00',
      'scopes' => ['ingest:read', 'ingest:write'],
    ]);
  }

  /**
   * Returns the request number $index sent so far.
   */
  private function request(int $index): RequestInterface {
    $this->assertArrayHasKey($index, $this->history, sprintf('Request %d was not sent.', $index));
    return $this->history[$index]['request'];
  }

  /**
   * Decodes the JSON body of a request.
   *
   * @return array<string, mixed>
   *   The body.
   */
  private function jsonBody(RequestInterface $request): array {
    return json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * The widget token request carries the strict body and safe headers.
   */
  public function testIssueWidgetToken(): void {
    $this->queueJson(200, [
      'access_token' => 'widget-jwt',
      'token_type' => 'Bearer',
      'expires_in' => 899,
      'expires_at' => '2027-01-15T08:15:00.123456+00:00',
      'scopes' => ['chat:use', 'knowledge:read'],
    ]);
    $result = $this->client->issueWidgetToken(new WidgetTokenRequest(
      userId: 'dabc',
      roles: ['editor'],
      locale: 'es',
      scopes: ['knowledge:read', 'chat:use'],
      origin: 'https://www.example.test',
      ttl: 600,
    ));

    $this->assertSame('widget-jwt', $result->accessToken);
    $this->assertSame(strtotime('2027-01-15T08:15:00+00:00'), $result->expiresAt);
    $this->assertSame(['chat:use', 'knowledge:read'], $result->scopes);

    $this->assertCount(1, $this->history);
    $request = $this->request(0);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame(self::API_URL . '/v1/token', (string) $request->getUri());
    $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    $this->assertStringStartsWith('WebAssistantDrupalConnector/1.0 (Drupal ', $request->getHeaderLine('User-Agent'));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request->getHeaderLine('X-Request-Id'));
    $this->assertFalse($request->hasHeader('Authorization'));
    $this->assertSame([
      'grant_type' => 'widget_token',
      'user' => ['id' => 'dabc', 'roles' => ['editor'], 'groups' => [], 'locale' => 'es'],
      'scopes' => ['chat:use', 'knowledge:read'],
      'ttl_seconds' => 600,
      'origin' => 'https://www.example.test',
      'client_id' => 'wa_test_client',
      'client_secret' => self::SECRET,
      'site_id' => 'site_demo',
    ], $this->jsonBody($request));
  }

  /**
   * NULL fields are omitted from the strict token body.
   */
  public function testWidgetTokenOmitsEmptyOptionalFields(): void {
    $this->queueToken('widget-jwt', 900);
    $this->client->issueWidgetToken(new WidgetTokenRequest(userId: 'dabc', scopes: ['chat:use']));
    $body = $this->jsonBody($this->request(0));
    $this->assertArrayNotHasKey('origin', $body);
    $this->assertArrayNotHasKey('assistant_id', $body);
    $this->assertArrayNotHasKey('locale', $body['user']);
  }

  /**
   * The server token is cached in memory until 30 seconds before expiry.
   */
  public function testServerTokenIsCached(): void {
    $this->queueToken('token-1', 600);
    $this->queueToken('token-2', 600);

    $this->assertSame('token-1', $this->client->serverToken()->accessToken);
    $this->assertSame('token-1', $this->client->serverToken()->accessToken);
    $this->assertCount(1, $this->history);
    $this->assertSame([
      'grant_type' => 'client_credentials',
      'client_id' => 'wa_test_client',
      'client_secret' => self::SECRET,
    ], $this->jsonBody($this->request(0)));

    $this->now += 571;
    $this->assertSame('token-2', $this->client->serverToken()->accessToken);
    $this->assertCount(2, $this->history);
  }

  /**
   * A 401 renews the server token once and retries the request.
   */
  public function testUnauthorizedRenewsServerTokenOnce(): void {
    $this->queueToken('stale-token');
    $this->queueJson(401, ['code' => 'unauthorized', 'title' => 'Unauthorized', 'status' => 401]);
    $this->queueToken('fresh-token');
    $this->queueJson(202, ['items' => [['source_id' => 'drupal:node:1:es', 'status' => 'queued']]]);

    $items = $this->client->ingest([['source_id' => 'drupal:node:1:es']]);

    $this->assertSame([['source_id' => 'drupal:node:1:es', 'status' => 'queued']], $items);
    $this->assertCount(4, $this->history);
    $this->assertSame('Bearer stale-token', $this->request(1)->getHeaderLine('Authorization'));
    $this->assertSame('Bearer fresh-token', $this->request(3)->getHeaderLine('Authorization'));
    $this->assertNotSame($this->request(1)->getHeaderLine('X-Request-Id'), $this->request(3)->getHeaderLine('X-Request-Id'));
  }

  /**
   * A second 401 is reported as a non-retryable error.
   */
  public function testRepeatedUnauthorizedFails(): void {
    $this->queueToken('token-1');
    $this->queueJson(401, ['code' => 'unauthorized']);
    $this->queueToken('token-2');
    $this->queueJson(401, ['code' => 'unauthorized']);

    try {
      $this->client->search(['query' => 'hello']);
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(401, $e->getStatus());
      $this->assertSame('unauthorized', $e->getErrorCode());
      $this->assertFalse($e->isRetryable());
    }
    $this->assertCount(4, $this->history);
  }

  /**
   * A 429 keeps the Retry-After delay and the correlation ID.
   */
  public function testTooManyRequests(): void {
    $this->queueToken();
    $this->queueJson(429, [
      'code' => 'rate_limited',
      'title' => 'Too many requests',
      'retryable' => TRUE,
    ], ['Retry-After' => '17', 'X-Request-Id' => 'corr-429-abcdef']);

    try {
      $this->client->search(['query' => 'hello']);
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(429, $e->getStatus());
      $this->assertSame('rate_limited', $e->getErrorCode());
      $this->assertTrue($e->isRetryable());
      $this->assertSame(17, $e->getRetryAfter());
      $this->assertSame('corr-429-abcdef', $e->getCorrelationId());
    }
  }

  /**
   * A 422 problem+json exposes the validation errors.
   */
  public function testValidationErrors(): void {
    $this->queueToken();
    $this->queueJson(422, [
      'code' => 'validation_error',
      'title' => 'Validation error',
      'retryable' => FALSE,
      'errors' => [['path' => '/documents/0/title', 'message' => 'Field required']],
    ]);

    try {
      $this->client->ingest([['source_id' => 'drupal:node:1:es']]);
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(422, $e->getStatus());
      $this->assertSame('validation_error', $e->getErrorCode());
      $this->assertFalse($e->isRetryable());
      $this->assertSame([['path' => '/documents/0/title', 'message' => 'Field required']], $e->getErrors());
      $this->assertSame($this->request(1)->getHeaderLine('X-Request-Id'), $e->getCorrelationId());
    }
  }

  /**
   * Transport errors are retryable and never leak the secret.
   */
  public function testNetworkError(): void {
    $this->mock->append(new ConnectException('cURL error 7: Failed to connect to api.example.test port 443', new PsrRequest('POST', self::API_URL . '/v1/token')));

    try {
      $this->client->serverToken();
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertNull($e->getStatus());
      $this->assertSame(PlatformException::CODE_NETWORK_ERROR, $e->getErrorCode());
      $this->assertTrue($e->isRetryable());
      $this->assertStringContainsString('cURL error 7', $e->getMessage());
      $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
      $this->assertNotEmpty($e->getCorrelationId());
    }
  }

  /**
   * Redirects are never followed.
   */
  public function testRedirectsAreNotFollowed(): void {
    $this->mock->append(new Response(302, ['Location' => 'https://elsewhere.test/']));
    $this->expectException(PlatformException::class);
    try {
      $this->client->health();
    }
    finally {
      $this->assertCount(1, $this->history);
    }
  }

  /**
   * GET document: 404 means NULL; path segments keep ":" and "/".
   */
  public function testGetDocument(): void {
    $this->queueToken();
    $this->queueJson(404, ['code' => 'not_found', 'title' => 'Not found']);
    $this->queueJson(200, ['source_id' => 'drupal:node:1:es', 'status' => 'ready']);

    $this->assertNull($this->client->getDocument('ayuda', 'drupal:node:1:es'));
    $this->assertSame(self::API_URL . '/v1/ingest/documents/ayuda/drupal:node:1:es', (string) $this->request(1)->getUri());

    $document = $this->client->getDocument('ayuda', 'drupal:file/a b#c');
    $this->assertSame('ready', $document['status']);
    $this->assertSame(self::API_URL . '/v1/ingest/documents/ayuda/drupal:file/a%20b%23c', (string) $this->request(2)->getUri());
    $this->assertSame('GET', $this->request(2)->getMethod());
  }

  /**
   * DELETE returns the body; an unknown knowledge base fails.
   */
  public function testDeleteDocument(): void {
    $this->queueToken();
    $this->queueJson(200, [
      'source_id' => 'drupal:node:1:es',
      'status' => 'deleted',
      'operation' => 'unchanged',
      'document_id' => NULL,
    ]);
    $this->queueJson(404, ['code' => 'not_found']);

    $result = $this->client->deleteDocument('ayuda', 'drupal:node:1:es');
    $this->assertSame('unchanged', $result['operation']);
    $this->assertSame('DELETE', $this->request(1)->getMethod());

    $this->expectException(PlatformException::class);
    $this->client->deleteDocument('missing', 'drupal:node:1:es');
  }

  /**
   * PUT ACL sends the principals list.
   */
  public function testUpdateAcl(): void {
    $this->queueToken();
    $this->queueJson(200, ['operation' => 'acl_updated']);
    $this->client->updateAcl('ayuda', 'drupal:node:1:es', ['role:editor', 'authenticated']);
    $request = $this->request(1);
    $this->assertSame('PUT', $request->getMethod());
    $this->assertSame(self::API_URL . '/v1/ingest/acl/ayuda/drupal:node:1:es', (string) $request->getUri());
    $this->assertSame(['acl' => ['role:editor', 'authenticated']], $this->jsonBody($request));
  }

  /**
   * The inventory is read page by page with the cursor.
   */
  public function testInventoryPagination(): void {
    $this->queueToken();
    $this->queueJson(200, [
      'items' => [['source_id' => 'drupal:node:1:es', 'version' => 'm1.a']],
      'next_cursor' => 'drupal:node:1:es',
    ]);
    $this->queueJson(200, [
      'items' => [['source_id' => 'drupal:node:2:es', 'version' => 'm1.b']],
      'next_cursor' => NULL,
    ]);

    $first = $this->client->inventory('ayuda', NULL, 5000);
    $this->assertSame('drupal:node:1:es', $first['next_cursor']);
    $this->assertCount(1, $first['items']);
    parse_str($this->request(1)->getUri()->getQuery(), $query);
    $this->assertSame(['limit' => '1000'], $query);
    $this->assertSame('/assistant/v1/ingest/inventory/ayuda', $this->request(1)->getUri()->getPath());

    $second = $this->client->inventory('ayuda', $first['next_cursor'], 2);
    $this->assertNull($second['next_cursor']);
    $this->assertSame('drupal:node:2:es', $second['items'][0]['source_id']);
    parse_str($this->request(2)->getUri()->getQuery(), $query);
    $this->assertSame(['limit' => '2', 'cursor' => 'drupal:node:1:es'], $query);
  }

  /**
   * Ingest is split in batches of 100 documents.
   */
  public function testIngestBatchesByCount(): void {
    $documents = [];
    for ($i = 1; $i <= 150; $i++) {
      $documents[] = ['source_id' => "drupal:node:$i:es"];
    }
    $this->queueToken();
    $this->queueJson(202, ['items' => array_map(static fn (array $d): array => $d + ['status' => 'queued'], array_slice($documents, 0, 100))]);
    $this->queueJson(202, ['items' => array_map(static fn (array $d): array => $d + ['status' => 'queued'], array_slice($documents, 100))]);

    $items = $this->client->ingest($documents);

    $this->assertCount(150, $items);
    $this->assertSame('drupal:node:150:es', $items[149]['source_id']);
    $this->assertCount(100, $this->jsonBody($this->request(1))['documents']);
    $this->assertCount(50, $this->jsonBody($this->request(2))['documents']);
    $this->assertSame('application/json', $this->request(1)->getHeaderLine('Content-Type'));
  }

  /**
   * A 413 splits the batch in halves.
   */
  public function testIngestSplitsOnPayloadTooLarge(): void {
    $documents = [
      ['source_id' => 'a:1'],
      ['source_id' => 'a:2'],
      ['source_id' => 'a:3'],
    ];
    $this->queueToken();
    $this->queueJson(413, ['code' => 'payload_too_large']);
    $this->queueJson(202, ['items' => [['source_id' => 'a:1'], ['source_id' => 'a:2']]]);
    $this->queueJson(202, ['items' => [['source_id' => 'a:3']]]);

    $items = $this->client->ingest($documents);

    $this->assertSame(['a:1', 'a:2', 'a:3'], array_column($items, 'source_id'));
    $this->assertCount(2, $this->jsonBody($this->request(2))['documents']);
    $this->assertCount(1, $this->jsonBody($this->request(3))['documents']);
  }

  /**
   * An empty ingest sends nothing.
   */
  public function testIngestNothing(): void {
    $this->assertSame([], $this->client->ingest([]));
    $this->assertCount(0, $this->history);
  }

  /**
   * NULL search fields are omitted (the body is strict).
   */
  public function testSearchOmitsNullFields(): void {
    $this->queueToken();
    $this->queueJson(200, ['items' => [['source_id' => 'x', 'score' => 0.9]]]);
    $result = $this->client->search(['query' => 'hello', 'locale' => NULL, 'limit' => 3]);
    $this->assertSame([['source_id' => 'x', 'score' => 0.9]], $result['items']);
    $this->assertSame(['query' => 'hello', 'limit' => 3], $this->jsonBody($this->request(1)));
  }

  /**
   * Health and bootstrap are public calls; bootstrap sends the Origin.
   */
  public function testHealthAndBootstrap(): void {
    $this->client = $this->createClient([], ['assistant_id' => 'portal']);
    $this->queueJson(200, ['status' => 'ready', 'checks' => []]);
    $this->queueJson(200, ['site' => ['id' => 'site_demo'], 'assistant' => ['id' => 'portal']]);
    $this->queueJson(503, ['status' => 'not_ready', 'checks' => []]);

    $this->assertSame('ready', $this->client->health()['status']);
    $this->assertSame('site_demo', $this->client->bootstrap('https://www.example.test')['site']['id']);

    $health = $this->request(0);
    $this->assertSame(self::API_URL . '/health/ready', (string) $health->getUri());
    $this->assertFalse($health->hasHeader('Authorization'));
    $bootstrap = $this->request(1);
    $this->assertSame('/assistant/v1/bootstrap', $bootstrap->getUri()->getPath());
    parse_str($bootstrap->getUri()->getQuery(), $query);
    $this->assertSame(['site_id' => 'site_demo', 'assistant_id' => 'portal'], $query);
    $this->assertSame('https://www.example.test', $bootstrap->getHeaderLine('Origin'));
    $this->assertFalse($bootstrap->hasHeader('Authorization'));

    try {
      $this->client->health();
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(503, $e->getStatus());
      $this->assertTrue($e->isRetryable());
    }
  }

  /**
   * Missing credentials fail before any request.
   */
  public function testNotConfigured(): void {
    $this->client = $this->createClient(['client_secret' => '']);
    // The environment must not provide the secret either.
    putenv('WEBASSISTANT_CLIENT_SECRET');
    try {
      $this->client->serverToken();
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(PlatformException::CODE_NOT_CONFIGURED, $e->getErrorCode());
      $this->assertFalse($e->isRetryable());
    }
    $this->assertCount(0, $this->history);
  }

  /**
   * Invalid JSON on success is an invalid response.
   */
  public function testInvalidJson(): void {
    $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{not json'));
    try {
      $this->client->health();
      $this->fail('Expected a PlatformException.');
    }
    catch (PlatformException $e) {
      $this->assertSame(PlatformException::CODE_INVALID_RESPONSE, $e->getErrorCode());
      $this->assertSame(200, $e->getStatus());
    }
  }

}
