<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_commerce\Unit\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Tests\webassistant_connector_commerce\Unit\CommerceUnitTestBase;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use Drupal\webassistant_connector_commerce\Hook\VariationSyncHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests that variation changes reindex their product (design section 12).
 *
 * @group webassistant_connector
 */
#[CoversClass(VariationSyncHooks::class)]
#[Group('webassistant_connector')]
final class VariationSyncHooksTest extends CommerceUnitTestBase {

  /**
   * Keys of the outbox merges, as "source:entity_type:id:langcode:op".
   *
   * @var list<string>
   */
  private array $recorded = [];

  /**
   * Builds the hooks with a real outbox and source matcher.
   *
   * @param list<\Drupal\webassistant_connector\Entity\SourceInterface> $sources
   *   The sources (enabled and disabled).
   *
   * @return \Drupal\webassistant_connector_commerce\Hook\VariationSyncHooks
   *   The hooks.
   */
  private function hooks(array $sources): VariationSyncHooks {
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->method('getListCacheTags')->willReturn(['webassistant_source_list']);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($sources);
    $storage->method('getEntityType')->willReturn($entityType);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('webassistant_source')->willReturn($storage);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $connection = $this->createMock(Connection::class);
    $connection->method('merge')->willReturnCallback(function (string $table): Merge {
      $this->assertSame(Outbox::TABLE, $table);
      $keys = [];
      $operation = '';
      $merge = $this->createMock(Merge::class);
      $merge->method('keys')->willReturnCallback(static function (array $values) use (&$keys, $merge): Merge {
        $keys = $values;
        return $merge;
      });
      $merge->method('insertFields')->willReturnCallback(static function (array $values) use (&$operation, $merge): Merge {
        $operation = $values['operation'];
        return $merge;
      });
      $merge->method('updateFields')->willReturnSelf();
      $merge->method('expression')->willReturnSelf();
      $merge->method('execute')->willReturnCallback(function () use (&$keys, &$operation): int {
        $this->recorded[] = implode(':', $keys) . ':' . $operation;
        return 1;
      });
      return $merge;
    });
    $transactions = $this->createMock(TransactionManagerInterface::class);
    $transactions->method('inTransaction')->willReturn(TRUE);
    $connection->method('transactionManager')->willReturn($transactions);

    $outbox = new Outbox(
      $connection,
      $this->createMock(TimeInterface::class),
      $this->createMock(UuidInterface::class),
      $this->createMock(QueueFactory::class),
      $this->createMock(LoggerInterface::class),
    );
    return new VariationSyncHooks(new SourceMatcher($entityTypeManager, $cache), $outbox);
  }

  /**
   * Creates a source.
   *
   * @param string $id
   *   The source ID.
   * @param bool $enabled
   *   Whether it is enabled.
   * @param bool $products
   *   Whether it covers products.
   * @param list<string> $langcodes
   *   The covered languages (empty: all).
   *
   * @return \Drupal\webassistant_connector\Entity\SourceInterface
   *   The source.
   */
  private function source(string $id, bool $enabled = TRUE, bool $products = TRUE, array $langcodes = []): SourceInterface {
    $source = $this->createMock(SourceInterface::class);
    $source->method('id')->willReturn($id);
    $source->method('status')->willReturn($enabled);
    $source->method('appliesTo')->willReturnCallback(static fn (EntityInterface $entity): bool => $products && $entity->getEntityTypeId() === 'commerce_product');
    $source->method('appliesToLangcode')->willReturnCallback(static fn (string $langcode): bool => $langcodes === [] || in_array($langcode, $langcodes, TRUE));
    return $source;
  }

  /**
   * Creates the parent product, translated into English and Spanish.
   *
   * @param bool $new
   *   Whether the product is being created.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface
   *   The product.
   */
  private function parent(bool $new = FALSE): ProductInterface {
    $product = $this->product(10, 'Shirt');
    $product->method('uuid')->willReturn('0f8e2c56-1111-4a2b-9c3d-123456789abc');
    $product->method('bundle')->willReturn('default');
    $product->method('isNew')->willReturn($new);
    $product->method('getTranslationLanguages')->willReturn(['en' => NULL, 'es' => NULL]);
    return $product;
  }

  /**
   * Saving or deleting a variation records an upsert of its product.
   */
  public function testVariationChangesRecordProduct(): void {
    $variation = $this->variation(21, $this->parent(), 'SHIRT-RED');
    $hooks = $this->hooks([
      $this->source('catalog'),
      $this->source('catalog_es', TRUE, TRUE, ['es']),
      $this->source('disabled', FALSE),
      $this->source('articles', TRUE, FALSE),
    ]);

    $hooks->variationUpdate($variation);
    $expected = [
      'catalog:commerce_product:10:en:upsert',
      'catalog:commerce_product:10:es:upsert',
      'catalog_es:commerce_product:10:es:upsert',
    ];
    $this->assertSame($expected, $this->recorded);

    $this->recorded = [];
    $hooks->variationInsert($variation);
    $hooks->variationDelete($variation);
    $this->assertSame(array_merge($expected, $expected), $this->recorded);
  }

  /**
   * Nothing is recorded without a saved product or matching sources.
   */
  public function testNothingRecorded(): void {
    $hooks = $this->hooks([$this->source('catalog')]);
    // Variation of a product being created (the product insert is captured
    // by the base module).
    $hooks->variationInsert($this->variation(21, $this->parent(TRUE), 'SHIRT-RED'));
    // Variation whose product is gone.
    $orphan = $this->createMock(ProductVariationInterface::class);
    $orphan->method('getProduct')->willReturn(NULL);
    $hooks->variationDelete($orphan);
    // Other entities.
    $hooks->variationUpdate($this->createMock(EntityInterface::class));
    $this->assertSame([], $this->recorded);

    $this->hooks([$this->source('articles', TRUE, FALSE)])->variationUpdate($this->variation(22, $this->parent(), 'SHIRT-BLUE'));
    $this->assertSame([], $this->recorded);
  }

}
