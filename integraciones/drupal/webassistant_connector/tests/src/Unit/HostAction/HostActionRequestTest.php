<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\HostAction;

use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Validation of untrusted host action payloads (host-action.v1).
 *
 * @group webassistant_connector
 */
#[CoversClass(HostActionRequest::class)]
#[Group('webassistant_connector')]
final class HostActionRequestTest extends UnitTestCase {

  /**
   * Fixed "now" used by the tests: 2026-10-11T12:00:00Z.
   */
  private const NOW = 1791720000;

  /**
   * Returns a valid payload as sent by the platform.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private static function payload(array $overrides = []): array {
    return array_replace([
      'version' => '1',
      'name' => 'content.create',
      'execution_id' => 'act_0123456789abcdef',
      'correlation_id' => 'act_0123456789abcdef',
      'skill' => 'content_forms',
      'action' => 'create',
      'run_id' => 'run_42',
      'params' => ['content_type' => 'article'],
      'expires_at' => '2026-10-11T12:02:00.123456+00:00',
    ], $overrides);
  }

  /**
   * A complete valid payload is accepted and mapped.
   */
  public function testValidPayload(): void {
    $request = HostActionRequest::fromArray(self::payload(), self::NOW);
    $this->assertSame('content.create', $request->name);
    $this->assertSame('act_0123456789abcdef', $request->executionId);
    $this->assertSame('act_0123456789abcdef', $request->correlationId);
    $this->assertSame('content_forms', $request->skill);
    $this->assertSame('create', $request->action);
    $this->assertSame('run_42', $request->runId);
    $this->assertSame(['content_type' => 'article'], $request->params);
    $this->assertSame(self::NOW + 120, $request->expiresAt);
    $this->assertSame('1', $request->version);
  }

  /**
   * Optional members may be missing or NULL; unknown members are ignored.
   */
  public function testOptionalMembers(): void {
    $payload = self::payload(['run_id' => NULL, 'expires_at' => NULL, 'unknown' => 'x', 'params' => []]);
    unset($payload['skill'], $payload['action']);
    $request = HostActionRequest::fromArray($payload, self::NOW);
    $this->assertNull($request->runId);
    $this->assertNull($request->skill);
    $this->assertNull($request->action);
    $this->assertNull($request->expiresAt);
    $this->assertSame([], $request->params);
  }

  /**
   * The expiry accepts a 30 s tolerance and rejects older actions.
   */
  public function testExpiryTolerance(): void {
    $request = HostActionRequest::fromArray(self::payload(['expires_at' => '2026-10-11T11:59:31Z']), self::NOW);
    $this->assertSame(self::NOW - 29, $request->expiresAt);

    $request = HostActionRequest::fromArray(self::payload(['expires_at' => '2026-10-11T13:59:30+02:00']), self::NOW);
    $this->assertSame(self::NOW - 30, $request->expiresAt);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The host action has expired.');
    HostActionRequest::fromArray(self::payload(['expires_at' => '2026-10-11T11:59:29Z']), self::NOW);
  }

  /**
   * Invalid payloads are rejected.
   *
   * @param array<string, mixed> $overrides
   *   Members replaced in the valid payload.
   * @param list<string> $remove
   *   Members removed from the payload.
   */
  #[DataProvider('providerInvalidPayloads')]
  public function testInvalidPayloads(array $overrides, array $remove = []): void {
    $payload = self::payload($overrides);
    foreach ($remove as $key) {
      unset($payload[$key]);
    }
    $this->expectException(\InvalidArgumentException::class);
    HostActionRequest::fromArray($payload, self::NOW);
  }

  /**
   * Data provider for testInvalidPayloads().
   *
   * @return array<string, array{0: array<string, mixed>, 1?: list<string>}>
   *   Cases.
   */
  public static function providerInvalidPayloads(): array {
    return [
      'missing version' => [[], ['version']],
      'integer version' => [['version' => 1]],
      'version 2' => [['version' => '2']],
      'missing name' => [[], ['name']],
      'uppercase name' => [['name' => 'Content.Create']],
      'name without dot' => [['name' => 'content']],
      'name with slash' => [['name' => 'content/create']],
      'missing execution_id' => [[], ['execution_id']],
      'empty execution_id' => [['execution_id' => '']],
      'execution_id with spaces' => [['execution_id' => 'act 1']],
      'execution_id with slash' => [['execution_id' => 'act/1']],
      'execution_id too long' => [['execution_id' => str_repeat('a', 129)]],
      'integer execution_id' => [['execution_id' => 42]],
      'missing correlation_id' => [[], ['correlation_id']],
      'bad correlation_id' => [['correlation_id' => '<script>']],
      'missing params' => [[], ['params']],
      'params string' => [['params' => 'content_type=article']],
      'params list' => [['params' => ['article']]],
      'skill not a string' => [['skill' => ['x']]],
      'skill too long' => [['skill' => str_repeat('s', 129)]],
      'action with control characters' => [['action' => "create\n"]],
      'run_id integer' => [['run_id' => 7]],
      'expires_at relative' => [['expires_at' => 'tomorrow']],
      'expires_at without offset' => [['expires_at' => '2026-10-11T12:02:00']],
      'expires_at timestamp' => [['expires_at' => self::NOW + 60]],
      'expires_at invalid date' => [['expires_at' => '2026-13-45T12:02:00Z']],
      'expires_at overflowing date' => [['expires_at' => '2026-02-30T12:02:00Z']],
    ];
  }

  /**
   * The execution ID pattern accepts the platform identifiers.
   */
  public function testIdPattern(): void {
    foreach (['act_01J9Z', 'a', 'A-b_c.d:e', str_repeat('x', 128)] as $id) {
      $request = HostActionRequest::fromArray(self::payload(['execution_id' => $id, 'correlation_id' => $id]), self::NOW);
      $this->assertSame($id, $request->executionId);
    }
  }

}
