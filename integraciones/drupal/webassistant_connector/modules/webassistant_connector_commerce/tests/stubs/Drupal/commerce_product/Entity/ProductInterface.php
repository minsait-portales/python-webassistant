<?php

namespace Drupal\commerce_product\Entity;

use Drupal\commerce_store\Entity\EntityStoresInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for products.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface ProductInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, EntityStoresInterface {

  /**
   * Gets the product title.
   *
   * @return string
   *   The product title
   */
  public function getTitle();

  /**
   * Gets the variation IDs.
   *
   * @return int[]
   *   The variation IDs.
   */
  public function getVariationIds();

  /**
   * Gets the variations.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   *   The variations.
   */
  public function getVariations();

  /**
   * Gets whether the product has variations.
   *
   * A product must always have at least one variation, but a newly initialized
   * (or invalid) product entity might not have any.
   *
   * @return bool
   *   TRUE if the product has variations, FALSE otherwise.
   */
  public function hasVariations();

  /**
   * Gets the default variation.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The default variation, or NULL if none found.
   */
  public function getDefaultVariation();

}
