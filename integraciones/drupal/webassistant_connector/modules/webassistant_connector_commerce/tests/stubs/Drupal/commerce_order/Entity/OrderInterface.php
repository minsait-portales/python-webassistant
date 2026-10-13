<?php

namespace Drupal\commerce_order\Entity;

use Drupal\commerce_store\Entity\EntityStoreInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the interface for orders.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface OrderInterface extends ContentEntityInterface, EntityChangedInterface, EntityStoreInterface {

  /**
   * Gets the customer user ID.
   *
   * @return int
   *   The customer user ID ('0' if anonymous).
   */
  public function getCustomerId();

  /**
   * Gets the order items.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface[]
   *   The order items.
   */
  public function getItems();

  /**
   * Gets whether the order has order items.
   *
   * @return bool
   *   TRUE if the order has order items, FALSE otherwise.
   */
  public function hasItems();

  /**
   * Adds an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return $this
   */
  public function addItem(OrderItemInterface $order_item);

  /**
   * Removes an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return $this
   */
  public function removeItem(OrderItemInterface $order_item);

  /**
   * Gets the order subtotal price.
   *
   * Represents a sum of all order item totals.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The order subtotal price, or NULL.
   */
  public function getSubtotalPrice();

  /**
   * Gets the order total price.
   *
   * Represents a sum of all order item totals along with adjustments.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The order total price, or NULL.
   */
  public function getTotalPrice();

  /**
   * Gets whether the order is locked.
   *
   * @return bool
   *   TRUE if the order is locked, FALSE otherwise.
   */
  public function isLocked();

}
