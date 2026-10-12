<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\Sync;

use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Sync\SourceIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests remote source IDs (design section 8.3, amendment A10).
 *
 * @group webassistant_connector
 */
#[CoversClass(SourceIdGenerator::class)]
#[Group('webassistant_connector')]
final class SourceIdGeneratorTest extends UnitTestCase {

  /**
   * Builds a generator with a configured prefix.
   *
   * @param mixed $prefix
   *   The value of sync.source_id_prefix.
   *
   * @return \Drupal\webassistant_connector\Sync\SourceIdGenerator
   *   The generator.
   */
  private function generator(mixed $prefix = 'drupal-1a2b3c4d'): SourceIdGenerator {
    return new SourceIdGenerator($this->getConfigFactoryStub([
      'webassistant_connector.settings' => ['sync' => ['source_id_prefix' => $prefix]],
    ]));
  }

  /**
   * Source IDs follow {prefix}:{entity_type}:{id}:{langcode}.
   */
  public function testGenerate(): void {
    $generator = $this->generator();
    $this->assertSame('drupal-1a2b3c4d', $generator->getPrefix());
    $this->assertSame('drupal-1a2b3c4d:node:42:es', $generator->generate('node', 42, 'es'));
    $this->assertSame('drupal-1a2b3c4d:node:42:pt-br', $generator->generate('node', '42', 'pt-br'));
    $this->assertSame('drupal-1a2b3c4d:node:42:es:file:0f8e2c56-1111-4a2b-9c3d-123456789abc', $generator->forAttachment('drupal-1a2b3c4d:node:42:es', '0f8e2c56-1111-4a2b-9c3d-123456789abc'));
  }

  /**
   * Invalid or missing prefixes fall back to the default one.
   *
   * @param mixed $prefix
   *   The configured prefix.
   */
  #[DataProvider('providerInvalidPrefix')]
  public function testInvalidPrefix(mixed $prefix): void {
    $this->assertSame(SourceIdGenerator::DEFAULT_PREFIX, $this->generator($prefix)->getPrefix());
  }

  /**
   * Data provider for testInvalidPrefix().
   *
   * @return array<string, array{mixed}>
   *   Invalid prefixes.
   */
  public static function providerInvalidPrefix(): array {
    return [
      'null' => [NULL],
      'empty' => [''],
      'uppercase' => ['Drupal'],
      'colon' => ['drupal:x'],
      'leading digit' => ['1drupal'],
      'too long' => [str_repeat('a', 33)],
    ];
  }

  /**
   * Unsafe characters are encoded and every ID matches the platform pattern.
   */
  public function testEncodingIsPatternSafe(): void {
    $generator = $this->generator();
    $id = $generator->generate('custom_entity', 'a:b/c d@é', 'zh-hans');
    $this->assertMatchesRegularExpression(SourceIdGenerator::SOURCE_ID_PATTERN, $id);
    $this->assertSame('drupal-1a2b3c4d:custom_entity:a@3Ab@2Fc@20d@40@C3@A9:zh-hans', $id);
    $this->assertSame([
      'entity_type' => 'custom_entity',
      'entity_id' => 'a:b/c d@é',
      'langcode' => 'zh-hans',
      'attachment' => NULL,
    ], $generator->parse($id));
    $this->assertSame('a:b', SourceIdGenerator::decode(SourceIdGenerator::encode('a:b')));
    $this->assertNull(SourceIdGenerator::decode('bad@zz'));
  }

  /**
   * Over-long entity IDs are hashed (and are not parseable).
   */
  public function testLongIdsAreHashed(): void {
    $generator = $this->generator();
    $long = str_repeat('x', 300);
    $id = $generator->generate('node', $long, 'en');
    $this->assertLessThanOrEqual(SourceIdGenerator::MAX_BASE_LENGTH, strlen($id));
    $this->assertSame('drupal-1a2b3c4d:node:@h' . hash('sha256', $long) . ':en', $id);
    $this->assertMatchesRegularExpression(SourceIdGenerator::SOURCE_ID_PATTERN, $generator->forAttachment($id, '0f8e2c56-1111-4a2b-9c3d-123456789abc'));
    $this->assertNull($generator->parse($id));
  }

  /**
   * Parsing accepts only IDs of this installation.
   */
  public function testParse(): void {
    $generator = $this->generator();
    $this->assertTrue($generator->isOwn('drupal-1a2b3c4d:node:1:en'));
    $this->assertFalse($generator->isOwn('drupal:node:1:en'));
    $this->assertSame([
      'entity_type' => 'node',
      'entity_id' => '7',
      'langcode' => 'en',
      'attachment' => 'f-uuid',
    ], $generator->parse('drupal-1a2b3c4d:node:7:en:file:f-uuid'));
    $this->assertNull($generator->parse('drupal:node:7:en'));
    $this->assertNull($generator->parse('drupal-1a2b3c4d:node:7'));
    $this->assertNull($generator->parse('drupal-1a2b3c4d:node:7:en:image:x'));
    $this->assertNull($generator->parse('drupal-1a2b3c4d:Node:7:en'));
    $this->assertNull($generator->parse('drupal-1a2b3c4d:node::en'));
    $this->assertNull($generator->parse('drupal-1a2b3c4d:node:7:en:file:'));
  }

}
