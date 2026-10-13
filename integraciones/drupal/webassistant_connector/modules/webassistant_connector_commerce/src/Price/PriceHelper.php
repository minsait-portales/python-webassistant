<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Price;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Resolver\ChainPriceResolverInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves and formats prices for documents and carts (design section 12).
 *
 * Prices are always computed by Drupal Commerce: the chain price resolver
 * (promotions, price lists...) with a Context of the customer and store,
 * falling back to the price field; formatting goes through the
 * commerce_price.currency_formatter service.
 *
 * Service: webassistant_connector_commerce.price_helper.
 */
final class PriceHelper {

  /**
   * Maximum length of a formatted price (product_item.price).
   */
  public const MAX_FORMATTED_LENGTH = 60;

  /**
   * Constructs a PriceHelper.
   *
   * @param \Drupal\commerce_price\Resolver\ChainPriceResolverInterface $chainPriceResolver
   *   The chain price resolver.
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currencyFormatter
   *   The Commerce currency formatter.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    #[Autowire(service: 'commerce_price.chain_price_resolver')]
    private readonly ChainPriceResolverInterface $chainPriceResolver,
    #[Autowire(service: 'commerce_price.currency_formatter')]
    private readonly CurrencyFormatterInterface $currencyFormatter,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves the price of a purchasable entity for a customer.
   *
   * @param \Drupal\commerce\PurchasableEntityInterface $entity
   *   The purchasable entity (e.g. a product variation).
   * @param \Drupal\Core\Session\AccountInterface $customer
   *   The customer of the price context.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store of the price context; without a store only the price field
   *   is used (Commerce resolvers need one).
   * @param int $quantity
   *   The quantity.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The resolved price, else the price field, else NULL.
   */
  public function resolve(PurchasableEntityInterface $entity, AccountInterface $customer, ?StoreInterface $store, int $quantity = 1): ?Price {
    if ($store !== NULL) {
      try {
        $price = $this->chainPriceResolver->resolve($entity, (string) max(1, $quantity), new Context($customer, $store));
        if ($price instanceof Price) {
          return $price;
        }
      }
      catch (\Throwable $exception) {
        $this->logger->warning('Price resolution of @type @id failed; the price field is used: @class: @message', [
          '@type' => $entity->getEntityTypeId(),
          '@id' => (string) $entity->id(),
          '@class' => get_class($exception),
          '@message' => $exception->getMessage(),
        ]);
      }
    }
    $price = $entity->getPrice();
    return $price instanceof Price ? $price : NULL;
  }

  /**
   * Formats a price with the Commerce currency formatter.
   *
   * @param \Drupal\commerce_price\Price $price
   *   The price.
   * @param string|null $locale
   *   A locale (e.g. es, pt-BR), or NULL for the current Commerce locale.
   *
   * @return string
   *   The formatted price (at most MAX_FORMATTED_LENGTH characters). When
   *   the formatter fails (unknown locale or currency) the current locale is
   *   tried, then "{number} {currency code}".
   */
  public function format(Price $price, ?string $locale = NULL): string {
    $attempts = $locale !== NULL && $locale !== '' ? [['locale' => $locale], []] : [[]];
    $formatted = NULL;
    foreach ($attempts as $options) {
      try {
        $formatted = trim($this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode(), $options));
        break;
      }
      catch (\Throwable) {
        $formatted = NULL;
      }
    }
    if ($formatted === NULL || $formatted === '') {
      $formatted = number_format((float) $price->getNumber(), 2, '.', '') . ' ' . $price->getCurrencyCode();
    }
    return Unicode::truncate($formatted, self::MAX_FORMATTED_LENGTH);
  }

  /**
   * Returns the numeric amount of a price (product_item.price_amount).
   *
   * @param \Drupal\commerce_price\Price $price
   *   The price.
   *
   * @return float
   *   The amount.
   */
  public function amount(Price $price): float {
    return round((float) $price->getNumber(), 6);
  }

  /**
   * Adds prices up, per currency.
   *
   * Used for display only (several carts of different stores); bcmath is
   * used when available, as Commerce does.
   *
   * @param list<\Drupal\commerce_price\Price> $prices
   *   The prices.
   *
   * @return list<\Drupal\commerce_price\Price>
   *   One price per currency, in order of appearance.
   */
  public function sum(array $prices): array {
    $sums = [];
    foreach ($prices as $price) {
      $code = $price->getCurrencyCode();
      $sums[$code] = isset($sums[$code]) ? self::add($sums[$code], $price->getNumber()) : $price->getNumber();
    }
    $result = [];
    foreach ($sums as $code => $number) {
      $result[] = new Price($number, (string) $code);
    }
    return $result;
  }

  /**
   * Formats a list of prices (e.g. cart totals) as one string.
   *
   * @param list<\Drupal\commerce_price\Price> $prices
   *   The prices.
   * @param string|null $locale
   *   The locale, or NULL for the current one.
   *
   * @return string
   *   The per-currency sums joined with " + ", or an empty string.
   */
  public function formatTotal(array $prices, ?string $locale = NULL): string {
    $parts = array_map(fn (Price $price): string => $this->format($price, $locale), $this->sum($prices));
    return Unicode::truncate(implode(' + ', $parts), self::MAX_FORMATTED_LENGTH);
  }

  /**
   * Adds two decimal strings.
   *
   * @param string $a
   *   A number.
   * @param string $b
   *   A number.
   *
   * @return string
   *   The sum, with 6 decimals.
   */
  private static function add(string $a, string $b): string {
    if (function_exists('bcadd')) {
      return bcadd($a, $b, 6);
    }
    return sprintf('%.6F', (float) $a + (float) $b);
  }

}
