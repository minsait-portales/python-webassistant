<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_commerce\Unit\Price;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\webassistant_connector_commerce\Unit\CommerceUnitTestBase;
use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Price;
use Drupal\webassistant_connector_commerce\Price\PriceHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests price resolution and formatting (design section 12).
 *
 * @group webassistant_connector
 */
#[CoversClass(PriceHelper::class)]
#[Group('webassistant_connector')]
final class PriceHelperTest extends CommerceUnitTestBase {

  /**
   * The resolver price wins; the price field is the fallback.
   */
  public function testResolve(): void {
    $store = $this->store(1);
    $customer = new AnonymousUserSession();
    $entity = $this->createMock(PurchasableEntityInterface::class);
    $entity->method('getPrice')->willReturn(new Price('20.00', 'EUR'));
    $this->priceResolver->method('resolve')->willReturnCallback(static function (PurchasableEntityInterface $purchased, string $quantity, Context $context) use ($store, $customer): ?Price {
      return $context->getStore() === $store && $context->getCustomer() === $customer && $quantity === '3' ? new Price('15.00', 'EUR') : NULL;
    });
    $helper = $this->priceHelper();

    $this->assertSame('15.00', $helper->resolve($entity, $customer, $store, 3)?->getNumber());
    $this->assertSame('20.00', $helper->resolve($entity, $customer, $store)?->getNumber());
    // Commerce resolvers need a store: without one, the price field.
    $this->assertSame('20.00', $helper->resolve($entity, $customer, NULL, 3)?->getNumber());

    $broken = $this->createMock(PurchasableEntityInterface::class);
    $broken->method('getPrice')->willReturn(NULL);
    $this->assertNull($helper->resolve($broken, $customer, NULL));
  }

  /**
   * A failing resolver is logged and the price field is used.
   */
  public function testResolverFailure(): void {
    $entity = $this->createMock(PurchasableEntityInterface::class);
    $entity->method('getPrice')->willReturn(new Price('20.00', 'EUR'));
    $this->priceResolver->method('resolve')->willThrowException(new \RuntimeException('Price list unavailable.'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $helper = new PriceHelper($this->priceResolver, $this->currencyFormatter, $logger);

    $this->assertSame('20.00', $helper->resolve($entity, new AnonymousUserSession(), $this->store(1))?->getNumber());
  }

  /**
   * Prices are formatted by Commerce, with fallbacks.
   */
  public function testFormat(): void {
    $helper = $this->priceHelper();
    $price = new Price('1234.5', 'EUR');
    $this->assertSame('1.234,50 EUR [es]', $helper->format($price, 'es'));
    $this->assertSame('1.234,50 EUR', $helper->format($price));
    $this->assertSame(1234.5, $helper->amount($price));

    // Unknown locale: the current locale; formatter failure: plain format.
    $formatter = $this->createMock(CurrencyFormatterInterface::class);
    $formatter->method('format')->willReturnCallback(static function (string $number, string $code, array $options = []): string {
      if (isset($options['locale'])) {
        throw new \InvalidArgumentException('Unknown locale.');
      }
      return $code === 'XXX' ? throw new \InvalidArgumentException('Unknown currency.') : '€' . $number;
    });
    $helper = new PriceHelper($this->priceResolver, $formatter, $this->createMock(LoggerInterface::class));
    $this->assertSame('€1234.5', $helper->format($price, 'xx'));
    $this->assertSame('1234.50 XXX', $helper->format(new Price('1234.5', 'XXX'), 'es'));
  }

  /**
   * Totals are added up per currency.
   */
  public function testTotals(): void {
    $helper = $this->priceHelper();
    $prices = [new Price('10.10', 'EUR'), new Price('5', 'USD'), new Price('0.20', 'EUR')];

    $sums = $helper->sum($prices);
    $this->assertCount(2, $sums);
    $this->assertSame('EUR', $sums[0]->getCurrencyCode());
    $this->assertEqualsWithDelta(10.3, (float) $sums[0]->getNumber(), 0.000001);
    $this->assertSame('10,30 EUR + 5,00 USD', $helper->formatTotal($prices));
    $this->assertSame('', $helper->formatTotal([]));
  }

}
