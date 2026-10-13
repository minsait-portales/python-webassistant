<?php

namespace Drupal\commerce_store\Entity;

/**
 * Defines a common interface for entities that belong to one or more stores.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface EntityStoresInterface {

  /**
   * Gets the stores.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface[]
   *   The stores.
   */
  public function getStores();

  /**
   * Sets the stores.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface[] $stores
   *   The stores.
   *
   * @return $this
   */
  public function setStores(array $stores);

  /**
   * Gets the store IDs.
   *
   * @return int[]
   *   The store IDs.
   */
  public function getStoreIds();

  /**
   * Sets the store IDs.
   *
   * @param int[] $store_ids
   *   The store IDs.
   *
   * @return $this
   */
  public function setStoreIds(array $store_ids);

}
