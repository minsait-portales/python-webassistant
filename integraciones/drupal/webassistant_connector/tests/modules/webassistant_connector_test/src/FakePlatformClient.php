<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_test;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Platform\TokenResult;
use Drupal\webassistant_connector\Platform\WidgetTokenRequest;

/**
 * In-memory platform client for functional and kernel tests.
 *
 * Every call is recorded in the state key CALLS_KEY as [method, arguments]
 * (widget token requests are stored with WidgetTokenRequest::toArray()).
 * Setting the state key FAIL_KEY to TRUE makes every call throw a retryable
 * PlatformException (HTTP 503, correlation ID CORRELATION_ID).
 */
final class FakePlatformClient implements PlatformClientInterface {

  /**
   * State key of the recorded calls.
   */
  public const CALLS_KEY = 'webassistant_connector_test.calls';

  /**
   * State key that makes every call fail.
   */
  public const FAIL_KEY = 'webassistant_connector_test.fail';

  /**
   * Correlation ID of the simulated failures.
   */
  public const CORRELATION_ID = 'fake-correlation-0001';

  /**
   * Constructs a FakePlatformClient.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the recorded calls.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param string|null $method
   *   Only the calls of this method, if given.
   *
   * @return list<array{0: string, 1: array<int|string, mixed>}>
   *   The calls.
   */
  public static function calls(StateInterface $state, ?string $method = NULL): array {
    $state->resetCache();
    $calls = $state->get(self::CALLS_KEY, []);
    if ($method !== NULL) {
      $calls = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === $method));
    }
    return $calls;
  }

  /**
   * {@inheritdoc}
   */
  public function issueWidgetToken(WidgetTokenRequest $request): TokenResult {
    $count = $this->record(__FUNCTION__, [$request->toArray()]);
    return new TokenResult('fake-widget-token-' . $count, $this->time->getCurrentTime() + $request->ttl, $request->scopes);
  }

  /**
   * {@inheritdoc}
   */
  public function serverToken(): TokenResult {
    $this->record(__FUNCTION__);
    $scopes = ['ingest:read', 'ingest:write', 'search:read', 'token:issue'];
    return new TokenResult('fake-server-token', $this->time->getCurrentTime() + 600, $scopes);
  }

  /**
   * {@inheritdoc}
   */
  public function ingest(array $documents): array {
    $this->record(__FUNCTION__, [$documents]);
    $items = [];
    foreach ($documents as $document) {
      $items[] = [
        'source_id' => $document['source_id'] ?? '',
        'knowledge_base' => $document['knowledge_base'] ?? '',
        'status' => 'queued',
        'operation' => 'created',
        'document_id' => 'doc_fake',
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
      'document_id' => 'doc_fake',
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
      'document_id' => 'doc_fake',
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
    return ['items' => [], 'next_cursor' => NULL];
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
    $this->record(__FUNCTION__);
    return ['status' => 'ready', 'checks' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function bootstrap(string $origin): array {
    $this->record(__FUNCTION__, [$origin]);
    return [
      'site' => ['id' => 'site_test', 'name' => 'Test site'],
      'assistant' => ['id' => 'test_assistant', 'name' => 'Test assistant', 'version' => 1],
    ];
  }

  /**
   * Records a call and fails if requested.
   *
   * @param string $method
   *   The method name.
   * @param array<int|string, mixed> $arguments
   *   The arguments.
   *
   * @return int
   *   The number of calls of this method so far.
   *
   * @throws \Drupal\webassistant_connector\Platform\PlatformException
   *   When the state key FAIL_KEY is TRUE.
   */
  private function record(string $method, array $arguments = []): int {
    $this->state->resetCache();
    $calls = $this->state->get(self::CALLS_KEY, []);
    $calls[] = [$method, $arguments];
    $this->state->set(self::CALLS_KEY, $calls);
    if ($this->state->get(self::FAIL_KEY, FALSE)) {
      throw new PlatformException(
        message: 'Simulated platform failure.',
        status: 503,
        errorCode: 'service_unavailable',
        retryable: TRUE,
        retryAfter: 30,
        correlationId: self::CORRELATION_ID,
      );
    }
    return count(array_filter($calls, static fn (array $call): bool => $call[0] === $method));
  }

}
