<?php

namespace Drupal\commerce_store\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for stores.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface StoreInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface, EntityPublishedInterface {

  /**
   * Gets the store name.
   *
   * @return string
   *   The store name.
   */
  public function getName();

  /**
   * Gets the default store currency code.
   *
   * @return string
   *   The default store currency code.
   */
  public function getDefaultCurrencyCode();

  /**
   * Gets whether this is the default store.
   *
   * @return bool
   *   TRUE if this is the default store, FALSE otherwise.
   */
  public function isDefault();

}
