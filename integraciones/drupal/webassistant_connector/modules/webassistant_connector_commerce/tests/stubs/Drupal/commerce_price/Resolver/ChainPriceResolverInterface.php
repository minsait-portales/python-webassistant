<?php

namespace Drupal\commerce_price\Resolver;

/**
 * Runs the added resolvers one by one until one of them returns the price.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 *
 * Each resolver in the chain can be another chain, which is why this interface
 * extends the base price resolver one.
 */
interface ChainPriceResolverInterface extends PriceResolverInterface {

  /**
   * Adds a resolver.
   *
   * @param \Drupal\commerce_price\Resolver\PriceResolverInterface $resolver
   *   The resolver.
   */
  public function addResolver(PriceResolverInterface $resolver);

  /**
   * Gets all added resolvers.
   *
   * @return \Drupal\commerce_price\Resolver\PriceResolverInterface[]
   *   The resolvers.
   */
  public function getResolvers();

}
