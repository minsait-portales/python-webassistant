<?php

namespace Drupal\commerce_store;

/**
 * Holds a reference to the active store, resolved on demand.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface CurrentStoreInterface {

  /**
   * Gets the active store for the current request.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   The active store.
   */
  public function getStore();

}
