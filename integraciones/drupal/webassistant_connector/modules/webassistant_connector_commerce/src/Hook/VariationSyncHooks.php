<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SourceMatcher;

/**
 * Reindexes a product when one of its variations changes (design 12).
 *
 * Product documents contain the SKU, attributes and price of the variations,
 * which are separate entities: saving or deleting a variation does not save
 * its product. Each change records an upsert of every translation of the
 * parent product for the enabled sources covering it (a single outbox merge
 * per key inside the transaction, no HTTP, amendment A2). The worker
 * rebuilds the document from the current state.
 */
final class VariationSyncHooks {

  /**
   * Constructs a VariationSyncHooks object.
   *
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher (enabled sources, cached in memory).
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   */
  public function __construct(
    private readonly SourceMatcher $sourceMatcher,
    private readonly Outbox $outbox,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for commerce_product_variation.
   */
  #[Hook('commerce_product_variation_insert')]
  public function variationInsert(EntityInterface $variation): void {
    $this->recordProduct($variation);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for commerce_product_variation.
   */
  #[Hook('commerce_product_variation_update')]
  public function variationUpdate(EntityInterface $variation): void {
    $this->recordProduct($variation);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for commerce_product_variation.
   */
  #[Hook('commerce_product_variation_delete')]
  public function variationDelete(EntityInterface $variation): void {
    $this->recordProduct($variation);
  }

  /**
   * Records an upsert of every translation of the parent product.
   *
   * @param \Drupal\Core\Entity\EntityInterface $variation
   *   The variation.
   */
  private function recordProduct(EntityInterface $variation): void {
    if (!$variation instanceof ProductVariationInterface) {
      return;
    }
    // Deleting a product deletes its variations: nothing is left to update
    // (the product deletion itself is captured by the base module).
    $product = $variation->getProduct();
    if (!$product instanceof ProductInterface || $product->isNew()) {
      return;
    }
    $sources = $this->sourceMatcher->forEntity($product);
    foreach ($sources as $source) {
      foreach (array_keys($product->getTranslationLanguages()) as $langcode) {
        $langcode = (string) $langcode;
        if ($source->appliesToLangcode($langcode)) {
          $this->outbox->record(OutboxItem::OPERATION_UPSERT, $source, $product, $langcode);
        }
      }
    }
  }

}
