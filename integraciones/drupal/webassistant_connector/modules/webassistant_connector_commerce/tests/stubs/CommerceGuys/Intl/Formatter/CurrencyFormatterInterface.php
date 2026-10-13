<?php

namespace CommerceGuys\Intl\Formatter;

/**
 * Formats and parses currency amounts (commerceguys/intl 2.x).
 *
 * Test stub of the commerceguys/intl 2.x interface implemented by the
 * commerce_price.currency_formatter service: same namespace and signatures.
 * Autoloaded by the unit tests when Commerce is not installed.
 */
interface CurrencyFormatterInterface {

  /**
   * Formats a currency amount.
   *
   * Supported options: locale, use_grouping, minimum_fraction_digits,
   * maximum_fraction_digits, rounding_mode, style, currency_display.
   *
   * @param string $number
   *   The number.
   * @param string $currencyCode
   *   The currency code.
   * @param array $options
   *   The formatting options.
   *
   * @return string
   *   The formatted number.
   */
  public function format(string $number, string $currencyCode, array $options = []): string;

  /**
   * Parses a formatted currency amount.
   *
   * @param string $number
   *   The formatted number.
   * @param string $currencyCode
   *   The currency code.
   * @param array $options
   *   The parsing options.
   *
   * @return string|bool
   *   The parsed number or FALSE on error.
   */
  public function parse(string $number, string $currencyCode, array $options = []): string|bool;

}
