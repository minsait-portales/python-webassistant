<?php

namespace Drupal\commerce_order\Resolver;

/**
 * Runs the added resolvers one by one until one of them returns the order type.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 *
 * Each resolver in the chain can be another chain, which is why this interface
 * extends the order type resolver one.
 */
interface ChainOrderTypeResolverInterface extends OrderTypeResolverInterface {

  /**
   * Adds a resolver.
   *
   * @param \Drupal\commerce_order\Resolver\OrderTypeResolverInterface $resolver
   *   The resolver.
   */
  public function addResolver(OrderTypeResolverInterface $resolver);

  /**
   * Gets all added resolvers.
   *
   * @return \Drupal\commerce_order\Resolver\OrderTypeResolverInterface[]
   *   The resolvers.
   */
  public function getResolvers();

}
