<?php

namespace Drupal\commerce_product\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Defines the interface for product attributes.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface ProductAttributeInterface extends ConfigEntityInterface {

  /**
   * Gets the attribute element type.
   *
   * @return string
   *   The element type name.
   */
  public function getElementType();

}
