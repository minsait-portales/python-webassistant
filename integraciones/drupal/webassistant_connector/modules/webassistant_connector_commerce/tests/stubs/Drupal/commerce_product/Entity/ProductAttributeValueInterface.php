<?php

namespace Drupal\commerce_product\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the interface for product attribute values.
 *
 * Test stub of the Drupal Commerce 3.x interface: same namespace and
 * signatures, only the members used by the connector. Autoloaded by the
 * unit tests when Commerce is not installed.
 */
interface ProductAttributeValueInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Gets the attribute.
   *
   * @return \Drupal\commerce_product\Entity\ProductAttributeInterface
   *   The attribute.
   */
  public function getAttribute();

  /**
   * Gets the attribute ID.
   *
   * The attribute id is also the bundle of the attribute value.
   *
   * @return string
   *   The attribute ID.
   */
  public function getAttributeId();

  /**
   * Gets the attribute value name.
   *
   * @return string
   *   The attribute value name.
   */
  public function getName();

}
