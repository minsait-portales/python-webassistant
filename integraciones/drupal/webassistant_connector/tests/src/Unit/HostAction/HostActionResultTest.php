<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\HostAction;

use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Serialization and immutability of host action results.
 *
 * @group webassistant_connector
 */
#[CoversClass(HostActionResult::class)]
#[Group('webassistant_connector')]
final class HostActionResultTest extends UnitTestCase {

  /**
   * A succeeded result serializes every non-empty member.
   */
  public function testSucceeded(): void {
    $message = new class() implements \Stringable {

      /**
       * {@inheritdoc}
       */
      public function __toString(): string {
        return 'Opening the form.';
      }

    };
    $result = HostActionResult::succeeded(['url' => '/node/add/article'], $message, '/node/add/article');
    $this->assertTrue($result->isSucceeded());
    $this->assertSame([
      'status' => 'succeeded',
      'data' => ['url' => '/node/add/article'],
      'message' => 'Opening the form.',
      'redirect' => '/node/add/article',
    ], $result->toArray());
  }

  /**
   * NULL members and empty data are omitted.
   */
  public function testOmitsEmptyMembers(): void {
    $this->assertSame(['status' => 'succeeded'], HostActionResult::succeeded()->toArray());
    $failed = HostActionResult::failed('Not found.');
    $this->assertFalse($failed->isSucceeded());
    $this->assertSame(['status' => 'failed', 'message' => 'Not found.'], $failed->toArray());
    $this->assertNull($failed->redirect);
  }

  /**
   * A failed result keeps its data (e.g. candidates).
   */
  public function testFailedWithData(): void {
    $candidates = [['title' => 'A', 'url' => '/node/1/edit']];
    $result = HostActionResult::failed('Several items match.', ['candidates' => $candidates]);
    $this->assertSame([
      'status' => 'failed',
      'data' => ['candidates' => $candidates],
      'message' => 'Several items match.',
    ], $result->toArray());
  }

  /**
   * Stored results are rebuilt identically; unknown statuses become failed.
   */
  public function testFromArray(): void {
    $original = HostActionResult::succeeded(['a' => 1], 'Done.', '/x');
    $this->assertEquals($original, HostActionResult::fromArray($original->toArray()));

    $rebuilt = HostActionResult::fromArray(['status' => 'weird', 'data' => 'nope', 'message' => 5, 'redirect' => ['x']]);
    $this->assertSame(['status' => 'failed'], $rebuilt->toArray());
  }

  /**
   * The with*() methods return modified copies.
   */
  public function testWithers(): void {
    $result = HostActionResult::succeeded(['a' => 1, 'b' => 2], 'Done.', '/x');

    $replayed = $result->withData(['b' => 3, 'replayed' => TRUE]);
    $this->assertSame(['a' => 1, 'b' => 3, 'replayed' => TRUE], $replayed->data);
    $this->assertSame(['a' => 1, 'b' => 2], $result->data);
    $this->assertSame('/x', $replayed->redirect);

    $withoutRedirect = $result->withRedirect(NULL);
    $this->assertArrayNotHasKey('redirect', $withoutRedirect->toArray());
    $this->assertSame('/x', $result->redirect);
    $this->assertSame('/y', $result->withRedirect('/y')->redirect);

    $plain = $result->withMessage('Hecho.');
    $this->assertSame('Hecho.', $plain->message);
    $this->assertSame('Done.', $result->message);
    $this->assertArrayNotHasKey('message', $result->withMessage(NULL)->toArray());
  }

}
