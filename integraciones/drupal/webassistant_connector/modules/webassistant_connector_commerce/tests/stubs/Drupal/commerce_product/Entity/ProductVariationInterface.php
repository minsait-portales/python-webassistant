<?php

namespace Drupal\commerce_product\Entity;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for product variations.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface ProductVariationInterface extends PurchasableEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface {

  /**
   * Gets the parent product.
   *
   * @return ProductInterface|null
   *   The product entity, or null.
   */
  public function getProduct();

  /**
   * Gets the parent product ID.
   *
   * @return int|null
   *   The product ID, or null.
   */
  public function getProductId();

  /**
   * Get the variation SKU.
   *
   * @return string
   *   The variation SKU.
   */
  public function getSku();

  /**
   * Gets the variation title.
   *
   * @return string
   *   The variation title
   */
  public function getTitle();

  /**
   * Gets the list price.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The list price.
   */
  public function getListPrice();

  /**
   * Gets the attribute value IDs.
   *
   * @return int[]
   *   The attribute value IDs, keyed by field name.
   */
  public function getAttributeValueIds();

  /**
   * Gets the attribute values.
   *
   * @return \Drupal\commerce_product\Entity\ProductAttributeValueInterface[]
   *   The attribute values, keyed by field name.
   */
  public function getAttributeValues();

  /**
   * Gets the attribute value for the given field name.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\commerce_product\Entity\ProductAttributeValueInterface|null
   *   The attribute value, or NULL.
   */
  public function getAttributeValue($field_name);

}
