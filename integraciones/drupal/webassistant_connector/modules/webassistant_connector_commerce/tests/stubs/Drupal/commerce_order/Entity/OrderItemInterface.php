<?php

namespace Drupal\commerce_order\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the interface for order items.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface OrderItemInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Gets the parent order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or NULL.
   */
  public function getOrder();

  /**
   * Gets the parent order ID.
   *
   * @return int|null
   *   The order ID, or NULL.
   */
  public function getOrderId();

  /**
   * Gets the purchased entity.
   *
   * @return \Drupal\commerce\PurchasableEntityInterface|null
   *   The purchased entity, or NULL.
   */
  public function getPurchasedEntity();

  /**
   * Gets the purchased entity ID.
   *
   * @return int
   *   The purchased entity ID.
   */
  public function getPurchasedEntityId();

  /**
   * Gets the order item title.
   *
   * @return string
   *   The order item title
   */
  public function getTitle();

  /**
   * Gets the order item quantity.
   *
   * @return string
   *   The order item quantity
   */
  public function getQuantity();

  /**
   * Sets the order item quantity.
   *
   * @param string $quantity
   *   The order item quantity.
   *
   * @return $this
   */
  public function setQuantity($quantity);

  /**
   * Gets the order item unit price.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The order item unit price, or NULL.
   */
  public function getUnitPrice();

  /**
   * Gets the order item total price.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The order item total price, or NULL.
   */
  public function getTotalPrice();

}
