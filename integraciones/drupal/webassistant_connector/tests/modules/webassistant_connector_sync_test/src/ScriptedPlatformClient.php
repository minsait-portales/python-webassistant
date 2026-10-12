<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_sync_test;

use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Platform\TokenResult;
use Drupal\webassistant_connector\Platform\WidgetTokenRequest;

/**
 * Programmable in-memory platform client for kernel tests.
 *
 * The script and the recorded calls live in static properties, so they
 * survive container rebuilds within a test (kernel tests run in separate
 * processes). Call reset() in setUp().
 */
final class ScriptedPlatformClient implements PlatformClientInterface {

  /**
   * Recorded calls: [method, arguments].
   *
   * @var list<array{0: string, 1: array<int, mixed>}>
   */
  public static array $calls = [];

  /**
   * Ingest results by source_id (default: queued/created).
   *
   * @var array<string, array<string, mixed>>
   */
  public static array $ingestResults = [];

  /**
   * Exceptions thrown by the next calls of a method, in order.
   *
   * @var array<string, list<\Drupal\webassistant_connector\Platform\PlatformException>>
   */
  public static array $failures = [];

  /**
   * Inventory pages by knowledge base and cursor ('' for the first page).
   *
   * A page may be a PlatformException, thrown when the page is read.
   *
   * @var array<string, array<string, array{items: list<array<string, mixed>>, next_cursor: string|null}|\Drupal\webassistant_connector\Platform\PlatformException>>
   */
  public static array $inventory = [];

  /**
   * Scopes of the server token.
   *
   * @var list<string>
   */
  public static array $scopes = ['ingest:read', 'ingest:write', 'search:read', 'token:issue'];

  /**
   * Forgets the script and the calls.
   */
  public static function reset(): void {
    self::$calls = [];
    self::$ingestResults = [];
    self::$failures = [];
    self::$inventory = [];
    self::$scopes = ['ingest:read', 'ingest:write', 'search:read', 'token:issue'];
  }

  /**
   * Makes the next call of a method throw.
   *
   * @param string $method
   *   The method name.
   * @param \Drupal\webassistant_connector\Platform\PlatformException $exception
   *   The exception.
   */
  public static function failNext(string $method, PlatformException $exception): void {
    self::$failures[$method][] = $exception;
  }

  /**
   * Returns the arguments of the calls of a method.
   *
   * @param string $method
   *   The method name.
   *
   * @return list<array<int, mixed>>
   *   The arguments of each call.
   *
   * @phpstan-impure
   */
  public static function callsOf(string $method): array {
    $calls = [];
    foreach (self::$calls as [$name, $arguments]) {
      if ($name === $method) {
        $calls[] = $arguments;
      }
    }
    return $calls;
  }

  /**
   * Returns every document sent to ingest(), in order.
   *
   * @return list<array<string, mixed>>
   *   The documents.
   *
   * @phpstan-impure
   */
  public static function ingestedDocuments(): array {
    $documents = [];
    foreach (self::callsOf('ingest') as $arguments) {
      foreach ($arguments[0] as $document) {
        $documents[] = $document;
      }
    }
    return $documents;
  }

  /**
   * {@inheritdoc}
   */
  public function issueWidgetToken(WidgetTokenRequest $request): TokenResult {
    $this->record(__FUNCTION__, [$request->toArray()]);
    return new TokenResult('scripted-widget-token', time() + $request->ttl, $request->scopes);
  }

  /**
   * {@inheritdoc}
   */
  public function serverToken(): TokenResult {
    $this->record(__FUNCTION__, []);
    return new TokenResult('scripted-server-token', time() + 600, self::$scopes);
  }

  /**
   * {@inheritdoc}
   */
  public function ingest(array $documents): array {
    $this->record(__FUNCTION__, [$documents]);
    $items = [];
    foreach ($documents as $document) {
      $sourceId = (string) ($document['source_id'] ?? '');
      $items[] = (self::$ingestResults[$sourceId] ?? []) + [
        'source_id' => $sourceId,
        'knowledge_base' => (string) ($document['knowledge_base'] ?? ''),
        'status' => 'queued',
        'operation' => 'created',
        'document_id' => 'doc_' . substr(hash('sha256', $sourceId), 0, 12),
      ];
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDocument(string $knowledgeBase, string $sourceId): array {
    $this->record(__FUNCTION__, [$knowledgeBase, $sourceId]);
    return [
      'source_id' => $sourceId,
      'knowledge_base' => $knowledgeBase,
      'status' => 'deleted',
      'operation' => 'deleted',
      'document_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function updateAcl(string $knowledgeBase, string $sourceId, array $acl): array {
    $this->record(__FUNCTION__, [$knowledgeBase, $sourceId, $acl]);
    return [
      'source_id' => $sourceId,
      'knowledge_base' => $knowledgeBase,
      'status' => 'ready',
      'operation' => 'acl_updated',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDocument(string $knowledgeBase, string $sourceId): ?array {
    $this->record(__FUNCTION__, [$knowledgeBase, $sourceId]);
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function inventory(string $knowledgeBase, ?string $cursor = NULL, int $limit = 500): array {
    $this->record(__FUNCTION__, [$knowledgeBase, $cursor, $limit]);
    $page = self::$inventory[$knowledgeBase][$cursor ?? ''] ?? ['items' => [], 'next_cursor' => NULL];
    if ($page instanceof PlatformException) {
      throw $page;
    }
    return $page;
  }

  /**
   * {@inheritdoc}
   */
  public function search(array $query): array {
    $this->record(__FUNCTION__, [$query]);
    return ['items' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function health(): array {
    $this->record(__FUNCTION__, []);
    return ['status' => 'ready', 'checks' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function bootstrap(string $origin): array {
    $this->record(__FUNCTION__, [$origin]);
    return [
      'site' => ['id' => 'site_demo', 'name' => 'Demo'],
      'assistant' => ['id' => 'demo_assistant', 'name' => 'Demo assistant', 'version' => 1],
    ];
  }

  /**
   * Records a call and throws the scripted failure, if any.
   *
   * @param string $method
   *   The method name.
   * @param array<int, mixed> $arguments
   *   The arguments.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   */
  private function record(string $method, array $arguments): void {
    self::$calls[] = [$method, $arguments];
    if (!empty(self::$failures[$method])) {
      throw array_shift(self::$failures[$method]);
    }
  }

}
