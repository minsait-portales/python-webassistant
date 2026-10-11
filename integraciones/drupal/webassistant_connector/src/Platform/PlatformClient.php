<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Platform;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Guzzle implementation of the WebAssistant API client (design section 4).
 *
 * - Client from http_client_factory with timeout, connect_timeout,
 *   http_errors => FALSE and allow_redirects => FALSE (amendment C6). URLs
 *   are built from Credentials::apiBaseUrl(), so an API mounted under a path
 *   prefix keeps working.
 * - Headers: Accept: application/json, User-Agent:
 *   WebAssistantDrupalConnector/1.0 (Drupal <version>), X-Request-Id: <uuid>.
 * - /v1/token sends client_id/client_secret in the JSON body; ingest and
 *   search use Authorization: Bearer <serverToken()>; a 401 renews the server
 *   token once and retries. The server token lives in memory only.
 * - Path segments (knowledge_base, source_id) are encoded with rawurlencode
 *   except "/" and ":".
 * - Ingest batches are split by size (100 documents, 20 MB of JSON) and, on
 *   413, in halves (amendment C6).
 * - Errors become PlatformException (see PlatformException::fromResponse()).
 */
final class PlatformClient implements PlatformClientInterface {

  /**
   * User-Agent product token.
   */
  public const USER_AGENT = 'WebAssistantDrupalConnector/1.0';

  /**
   * Seconds before expiration at which the cached server token is renewed.
   */
  public const SERVER_TOKEN_MARGIN = 30;

  /**
   * Maximum documents per ingest request (platform limit).
   */
  public const MAX_BATCH_DOCUMENTS = 100;

  /**
   * Maximum JSON bytes per ingest request (the platform accepts 25 MB).
   */
  public const MAX_BATCH_BYTES = 20000000;

  /**
   * Maximum inventory page size accepted by the platform.
   */
  public const MAX_INVENTORY_LIMIT = 1000;

  /**
   * Error code for documents that cannot be encoded as JSON.
   */
  public const CODE_INVALID_DOCUMENT = 'invalid_document';

  /**
   * The server token cached for this PHP process (never persisted).
   */
  private ?TokenResult $serverToken = NULL;

  /**
   * Constructs a PlatformClient.
   *
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The HTTP client factory.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials resolver.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator (X-Request-Id).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly ClientFactory $httpClientFactory,
    private readonly Credentials $credentials,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function issueWidgetToken(WidgetTokenRequest $request): TokenResult {
    $body = $request->toArray() + $this->clientCredentials();
    $siteId = $this->siteId();
    if ($siteId !== '') {
      $body['site_id'] = $siteId;
    }
    return $this->requestToken($body);
  }

  /**
   * {@inheritdoc}
   */
  public function serverToken(): TokenResult {
    $now = $this->time->getCurrentTime();
    if ($this->serverToken !== NULL && $this->serverToken->isValid($now, self::SERVER_TOKEN_MARGIN)) {
      return $this->serverToken;
    }
    $this->serverToken = NULL;
    $this->serverToken = $this->requestToken(['grant_type' => 'client_credentials'] + $this->clientCredentials());
    return $this->serverToken;
  }

  /**
   * {@inheritdoc}
   */
  public function ingest(array $documents): array {
    $documents = array_values($documents);
    $items = [];
    foreach ($this->batches($documents) as $batch) {
      foreach ($this->ingestBatch($batch) as $item) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDocument(string $knowledgeBase, string $sourceId): array {
    return $this->expectSuccess($this->send('DELETE', self::documentPath($knowledgeBase, $sourceId), [], TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function updateAcl(string $knowledgeBase, string $sourceId, array $acl): array {
    $path = '/v1/ingest/acl/' . self::encodePath($knowledgeBase) . '/' . self::encodePath($sourceId);
    $principals = [];
    foreach ($acl as $principal) {
      $principals[] = (string) $principal;
    }
    return $this->expectSuccess($this->send('PUT', $path, ['json' => ['acl' => $principals]], TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function getDocument(string $knowledgeBase, string $sourceId): ?array {
    $response = $this->send('GET', self::documentPath($knowledgeBase, $sourceId), [], TRUE);
    if ($response['status'] === 404) {
      return NULL;
    }
    return $this->expectSuccess($response);
  }

  /**
   * {@inheritdoc}
   */
  public function inventory(string $knowledgeBase, ?string $cursor = NULL, int $limit = 500): array {
    $query = ['limit' => max(1, min(self::MAX_INVENTORY_LIMIT, $limit))];
    if ($cursor !== NULL && $cursor !== '') {
      $query['cursor'] = $cursor;
    }
    $response = $this->send('GET', '/v1/ingest/inventory/' . self::encodePath($knowledgeBase), ['query' => $query], TRUE);
    $data = $this->expectSuccess($response);
    $next = $data['next_cursor'] ?? NULL;
    if ($next !== NULL && !is_string($next)) {
      throw PlatformException::invalidResponse('The inventory response has an invalid next_cursor.', $response['status'], $response['correlation_id']);
    }
    return [
      'items' => $this->itemList($data, $response),
      'next_cursor' => $next === '' ? NULL : $next,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function search(array $query): array {
    // The body is strict: NULL fields are omitted instead of sent as null.
    $query = array_filter($query, static fn ($value): bool => $value !== NULL);
    $response = $this->send('POST', '/v1/search', ['json' => $query], TRUE);
    return ['items' => $this->itemList($this->expectSuccess($response), $response)];
  }

  /**
   * {@inheritdoc}
   */
  public function health(): array {
    return $this->expectSuccess($this->send('GET', '/health/ready'));
  }

  /**
   * {@inheritdoc}
   */
  public function bootstrap(string $origin): array {
    $siteId = $this->siteId();
    if ($siteId === '') {
      throw PlatformException::notConfigured('The WebAssistant site ID is not configured.');
    }
    $query = ['site_id' => $siteId];
    $assistantId = trim((string) $this->settings()->get('assistant_id'));
    if ($assistantId !== '') {
      $query['assistant_id'] = $assistantId;
    }
    return $this->expectSuccess($this->send('GET', '/v1/bootstrap', [
      'query' => $query,
      'headers' => ['Origin' => $origin],
    ]));
  }

  /**
   * Encodes a path segment, keeping ":" and "/" readable.
   *
   * @param string $segment
   *   The raw segment (knowledge base key or source_id).
   *
   * @return string
   *   The encoded segment.
   */
  public static function encodePath(string $segment): string {
    return str_replace(['%3A', '%2F'], [':', '/'], rawurlencode($segment));
  }

  /**
   * Returns the path of a document of the ingest API.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   * @param string $sourceId
   *   The source ID.
   *
   * @return string
   *   The encoded path.
   */
  private static function documentPath(string $knowledgeBase, string $sourceId): string {
    return '/v1/ingest/documents/' . self::encodePath($knowledgeBase) . '/' . self::encodePath($sourceId);
  }

  /**
   * Calls POST /v1/token and parses the token.
   *
   * @param array<string, mixed> $body
   *   The request body (grant, credentials and widget fields).
   *
   * @return \Drupal\webassistant_connector\Platform\TokenResult
   *   The token.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function requestToken(#[\SensitiveParameter] array $body): TokenResult {
    $response = $this->send('POST', '/v1/token', ['json' => $body]);
    $data = $this->expectSuccess($response);
    return TokenResult::fromResponse($data, $this->time->getCurrentTime(), $response['correlation_id']);
  }

  /**
   * Returns the client credentials for the /v1/token body.
   *
   * @return array{client_id: string, client_secret: string}
   *   The credentials.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the client ID or the client secret is missing.
   */
  private function clientCredentials(): array {
    $clientId = $this->credentials->clientId();
    $clientSecret = $this->credentials->clientSecret();
    if ($clientId === '' || $clientSecret === '') {
      throw PlatformException::notConfigured('The WebAssistant API client ID or client secret is not configured.');
    }
    return ['client_id' => $clientId, 'client_secret' => $clientSecret];
  }

  /**
   * Sends one ingest batch, splitting it in halves on 413.
   *
   * @param list<array<string, mixed>> $batch
   *   The documents (at least one).
   *
   * @return list<array<string, mixed>>
   *   The response items.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function ingestBatch(array $batch): array {
    try {
      $json = json_encode(['documents' => $batch], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new PlatformException('A document could not be encoded as JSON.', errorCode: self::CODE_INVALID_DOCUMENT, previous: $e);
    }
    $response = $this->send('POST', '/v1/ingest/documents', [
      'body' => $json,
      'headers' => ['Content-Type' => 'application/json'],
    ], TRUE);
    if ($response['status'] === 413 && count($batch) > 1) {
      $this->logger->notice('The WebAssistant API rejected an ingest batch of @count documents as too large; retrying in halves (correlation ID @correlation_id).', [
        '@count' => count($batch),
        '@correlation_id' => $response['correlation_id'],
      ]);
      $half = intdiv(count($batch) + 1, 2);
      return array_merge(
        $this->ingestBatch(array_slice($batch, 0, $half)),
        $this->ingestBatch(array_slice($batch, $half)),
      );
    }
    return $this->itemList($this->expectSuccess($response), $response);
  }

  /**
   * Splits documents in batches of at most 100 documents and 20 MB of JSON.
   *
   * @param list<array<string, mixed>> $documents
   *   The documents.
   *
   * @return list<list<array<string, mixed>>>
   *   The batches, in order. A document larger than the byte limit travels
   *   alone (the platform answers 413 for it).
   */
  private function batches(array $documents): array {
    $batches = [];
    $current = [];
    $bytes = 0;
    foreach ($documents as $document) {
      $size = strlen((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) + 1;
      if ($current !== [] && (count($current) >= self::MAX_BATCH_DOCUMENTS || $bytes + $size > self::MAX_BATCH_BYTES)) {
        $batches[] = $current;
        $current = [];
        $bytes = 0;
      }
      $current[] = $document;
      $bytes += $size;
    }
    if ($current !== []) {
      $batches[] = $current;
    }
    return $batches;
  }

  /**
   * Sends a request to the API.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The API path, starting with "/" and already encoded.
   * @param array<string, mixed> $options
   *   Guzzle options (json, body, query, headers).
   * @param bool $authenticated
   *   Whether to send the server token (renewed once on 401).
   *
   * @return array{status: int, body: array<string, mixed>|null, correlation_id: string, retry_after: string|null}
   *   The HTTP status, the decoded JSON object (NULL if empty or not a JSON
   *   object), the correlation ID and the raw Retry-After header.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function send(string $method, string $path, #[\SensitiveParameter] array $options = [], bool $authenticated = FALSE): array {
    $baseUrl = $this->credentials->apiBaseUrl();
    if ($baseUrl === '') {
      throw PlatformException::notConfigured('The WebAssistant API URL is not configured.');
    }
    $settings = $this->settings();
    $client = $this->httpClientFactory->fromOptions([
      'timeout' => max(1, (int) ($settings->get('api.timeout') ?: 10)),
      'connect_timeout' => max(1, (int) ($settings->get('api.connect_timeout') ?: 5)),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ]);

    $renewed = FALSE;
    while (TRUE) {
      $requestId = $this->uuid->generate();
      $headers = [
        'Accept' => 'application/json',
        'User-Agent' => sprintf('%s (Drupal %s)', self::USER_AGENT, \Drupal::VERSION),
        'X-Request-Id' => $requestId,
      ];
      if ($authenticated) {
        $headers['Authorization'] = 'Bearer ' . $this->serverToken()->accessToken;
      }
      $requestOptions = $options;
      $requestOptions['headers'] = $headers + ($options['headers'] ?? []);
      try {
        $response = $client->request($method, $baseUrl . $path, $requestOptions);
      }
      catch (GuzzleException $e) {
        throw PlatformException::networkError(sprintf('Could not reach the WebAssistant API (%s %s): %s.', $method, self::stripQuery($path), self::transportReason($e)), $requestId, $e);
      }
      $status = $response->getStatusCode();
      $correlationId = self::correlationId($response, $requestId);
      if ($status === 401 && $authenticated && !$renewed) {
        // The server token may have been revoked or rotated: renew it once.
        $renewed = TRUE;
        $this->serverToken = NULL;
        $this->logger->notice('The WebAssistant API rejected the server token; renewing it (correlation ID @correlation_id).', [
          '@correlation_id' => $correlationId,
        ]);
        continue;
      }
      $retryAfter = $response->getHeaderLine('Retry-After');
      return [
        'status' => $status,
        'body' => self::decode($response),
        'correlation_id' => $correlationId,
        'retry_after' => $retryAfter === '' ? NULL : $retryAfter,
      ];
    }
  }

  /**
   * Returns the decoded body of a 2xx response or throws the API error.
   *
   * @param array{status: int, body: array<string, mixed>|null, correlation_id: string, retry_after: string|null} $response
   *   The response returned by send().
   *
   * @return array<string, mixed>
   *   The decoded body.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function expectSuccess(array $response): array {
    $status = $response['status'];
    if ($status >= 200 && $status < 300) {
      if ($response['body'] === NULL) {
        throw PlatformException::invalidResponse(sprintf('The WebAssistant API returned an empty or non-JSON body (HTTP %d).', $status), $status, $response['correlation_id']);
      }
      return $response['body'];
    }
    throw PlatformException::fromResponse($status, $response['body'] ?? [], $response['retry_after'], $response['correlation_id']);
  }

  /**
   * Returns the "items" list of a response body.
   *
   * @param array<string, mixed> $data
   *   The decoded body.
   * @param array{status: int, body: array<string, mixed>|null, correlation_id: string, retry_after: string|null} $response
   *   The response returned by send().
   *
   * @return list<array<string, mixed>>
   *   The items (non-array entries are dropped).
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the body has no items list.
   */
  private function itemList(array $data, array $response): array {
    if (!isset($data['items']) || !is_array($data['items'])) {
      throw PlatformException::invalidResponse('The WebAssistant API response has no items list.', $response['status'], $response['correlation_id']);
    }
    return array_values(array_filter($data['items'], 'is_array'));
  }

  /**
   * Decodes a JSON object body.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return array<string, mixed>|null
   *   The object, or NULL if the body is empty or not a JSON object.
   */
  private static function decode(ResponseInterface $response): ?array {
    $raw = (string) $response->getBody();
    if (trim($raw) === '') {
      return NULL;
    }
    try {
      $data = json_decode($raw, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($data) ? $data : NULL;
  }

  /**
   * Returns the correlation ID echoed by the API or the one sent.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   * @param string $requestId
   *   The X-Request-Id sent.
   *
   * @return string
   *   The correlation ID.
   */
  private static function correlationId(ResponseInterface $response, string $requestId): string {
    $echoed = $response->getHeaderLine('X-Request-Id');
    return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $echoed) ? $echoed : $requestId;
  }

  /**
   * Returns a short, secret-free reason for a transport error.
   *
   * @param \GuzzleHttp\Exception\GuzzleException $exception
   *   The Guzzle exception.
   *
   * @return string
   *   E.g. "cURL error 28" or "ConnectException".
   */
  private static function transportReason(GuzzleException $exception): string {
    if (preg_match('/cURL error \d+/', $exception->getMessage(), $matches)) {
      return $matches[0];
    }
    $class = get_class($exception);
    $position = strrpos($class, '\\');
    return $position === FALSE ? $class : substr($class, $position + 1);
  }

  /**
   * Removes the query string from a path (for messages).
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   The path without query.
   */
  private static function stripQuery(string $path): string {
    return explode('?', $path, 2)[0];
  }

  /**
   * Returns the public site ID from the configuration ('' if missing).
   */
  private function siteId(): string {
    return trim((string) $this->settings()->get('site_id'));
  }

  /**
   * Returns the connector configuration.
   */
  private function settings(): ImmutableConfig {
    return $this->configFactory->get('webassistant_connector.settings');
  }

}
