<?php

namespace Drupal\commerce_order\Resolver;

use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Defines the interface for order type resolvers.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 *
 * Interface for services tagged as 'commerce_order.order_type_resolver'.
 */
interface OrderTypeResolverInterface {

  /**
   * Resolves the order type.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item being added to an order.
   *
   * @return string|null
   *   The order type ID, if resolved. Otherwise NULL, indicating that the
   *   next resolver in the chain should be called.
   */
  public function resolve(OrderItemInterface $order_item);

}
