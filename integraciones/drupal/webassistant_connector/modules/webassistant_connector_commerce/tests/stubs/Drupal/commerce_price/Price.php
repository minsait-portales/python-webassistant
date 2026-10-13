<?php

namespace Drupal\commerce_price;

/**
 * Provides a value object for monetary values.
 *
 * Test stub of the Drupal Commerce 3.x class: same namespace and signatures,
 * only the members used by the connector. The real class validates numbers
 * with \Drupal\commerce_price\Calculator (bcmath); this stub uses
 * is_numeric(). Autoloaded by the unit tests when Commerce is not installed.
 */
final class Price {

  /**
   * The number.
   *
   * @var string
   */
  protected $number;

  /**
   * The currency code.
   *
   * @var string
   */
  protected $currencyCode;

  /**
   * Constructs a new Price object.
   *
   * @param string $number
   *   The number.
   * @param string $currency_code
   *   The currency code.
   */
  public function __construct(string $number, string $currency_code) {
    if (!is_numeric($number)) {
      throw new \InvalidArgumentException(sprintf('The provided value "%s" is not a numeric value.', $number));
    }
    if (!preg_match('/^[A-Z]{3}$/i', $currency_code)) {
      throw new \InvalidArgumentException(sprintf('Invalid currency code "%s".', $currency_code));
    }
    $this->number = $number;
    $this->currencyCode = strtoupper($currency_code);
  }

  /**
   * Creates a new price from the given array.
   *
   * @param array $price
   *   The price array, with the "number" and "currency_code" keys.
   *
   * @return static
   */
  public static function fromArray(array $price) : Price {
    if (!isset($price['number'], $price['currency_code'])) {
      throw new \InvalidArgumentException('Price::fromArray() called with a malformed array.');
    }
    return new static($price['number'], $price['currency_code']);
  }

  /**
   * Gets the number.
   *
   * @return string
   *   The number.
   */
  public function getNumber() : string {
    return $this->number;
  }

  /**
   * Gets the currency code.
   *
   * @return string
   *   The currency code.
   */
  public function getCurrencyCode() : string {
    return $this->currencyCode;
  }

  /**
   * Gets the string representation of the price.
   *
   * @return string
   *   The string representation of the price.
   */
  public function __toString() : string {
    return $this->number . ' ' . $this->currencyCode;
  }

  /**
   * Gets the array representation of the price.
   *
   * @return array
   *   The array representation of the price.
   */
  public function toArray() : array {
    return ['number' => $this->number, 'currency_code' => $this->currencyCode];
  }

}
