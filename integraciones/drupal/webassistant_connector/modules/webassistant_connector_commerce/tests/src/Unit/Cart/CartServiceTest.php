<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_commerce\Unit\Cart;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\webassistant_connector_commerce\Unit\CommerceUnitTestBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\webassistant_connector\HostAction\HostActionException;
use Drupal\webassistant_connector_commerce\Cart\CartService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart service of the host actions (design section 12).
 *
 * @group webassistant_connector
 */
#[CoversClass(CartService::class)]
#[Group('webassistant_connector')]
final class CartServiceTest extends CommerceUnitTestBase {

  /**
   * The account.
   */
  private AnonymousUserSession $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->account = new AnonymousUserSession();
  }

  /**
   * Asserts that a call fails with a user-facing message.
   *
   * @param callable $callback
   *   The call.
   * @param string $message
   *   The expected message.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionException
   *   The exception.
   */
  private function assertFails(callable $callback, string $message): HostActionException {
    try {
      $callback();
    }
    catch (HostActionException $exception) {
      $this->assertSame($message, $exception->getMessage());
      return $exception;
    }
    $this->fail('A HostActionException was expected: ' . $message);
  }

  /**
   * Without variation_id the default (or first available) variation is used.
   */
  public function testResolveDefaultVariation(): void {
    $product = $this->product(10, 'Shirt', [$this->store(1)]);
    $red = $this->variation(21, $product, 'SHIRT-RED');
    $blue = $this->variation(22, $product, 'SHIRT-BLUE');
    $product->method('getDefaultVariation')->willReturn($blue);
    $cart = $this->cartService();

    $this->assertSame($blue, $cart->resolveVariation('10', NULL, $this->account));

    // An unpublished default variation falls back to the first available.
    $this->products = [];
    $this->variations = [];
    $product = $this->product(11, 'Hat', [$this->store(1)]);
    $hidden = $this->variation(31, $product, 'HAT-S', '5.00', FALSE);
    $visible = $this->variation(32, $product, 'HAT-M');
    $product->method('getDefaultVariation')->willReturn($hidden);
    $this->assertSame($visible, $cart->resolveVariation('11', NULL, $this->account));
    $this->assertNotSame($red, $visible);
  }

  /**
   * A variation_id (ID or SKU) must belong to the product.
   */
  public function testResolveExplicitVariation(): void {
    $shirt = $this->product(10, 'Shirt', [$this->store(1)]);
    $red = $this->variation(21, $shirt, 'SHIRT-RED');
    $hat = $this->product(11, 'Hat', [$this->store(1)]);
    $this->variation(31, $hat, 'HAT-S');
    $cart = $this->cartService();

    $this->assertSame($red, $cart->resolveVariation('10', '21', $this->account));
    $this->assertSame($red, $cart->resolveVariation('10', 'SHIRT-RED', $this->account));
    // Product given as an own source_id (fallback of the catalog processors).
    $this->assertSame($red, $cart->resolveVariation(self::PREFIX . ':commerce_product:10:es', '21', $this->account));
    // A variation of another product is never added instead.
    $this->assertFails(fn () => $cart->resolveVariation('10', '31', $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('10', 'HAT-S', $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('10', '999', $this->account), 'The product is not available.');
  }

  /**
   * A SKU in product_id is accepted when it is not a product.
   */
  public function testResolveSkuFallback(): void {
    $product = $this->product(10, 'Shirt', [$this->store(1)]);
    $red = $this->variation(21, $product, 'SHIRT-RED');
    $cart = $this->cartService();

    $this->assertSame($red, $cart->resolveVariation('SHIRT-RED', NULL, $this->account));
    $this->assertSame($red, $cart->resolveVariation('SHIRT-RED', '21', $this->account));
    $this->assertFails(fn () => $cart->resolveVariation('UNKNOWN', NULL, $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('99', NULL, $this->account), 'The product is not available.');
    // Source IDs of other prefixes or entity types are not products.
    $this->assertFails(fn () => $cart->resolveVariation('other:commerce_product:10:es', NULL, $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation(self::PREFIX . ':node:10:es', NULL, $this->account), 'The product is not available.');
  }

  /**
   * Unpublished or inaccessible products and variations are refused.
   */
  public function testResolveRequiresPublishedAndViewable(): void {
    $draft = $this->product(10, 'Draft', [$this->store(1)], FALSE);
    $this->variation(21, $draft, 'DRAFT-1');
    $private = $this->product(11, 'Private', [$this->store(1)], TRUE, FALSE);
    $this->variation(31, $private, 'PRIVATE-1');
    $shirt = $this->product(12, 'Shirt', [$this->store(1)]);
    $this->variation(41, $shirt, 'SHIRT-OFF', '10.00', FALSE);
    $this->variation(42, $shirt, 'SHIRT-HIDDEN', '10.00', TRUE, FALSE);
    $empty = $this->product(13, 'Empty', [$this->store(1)]);
    $empty->method('getDefaultVariation')->willReturn(NULL);
    $cart = $this->cartService();

    // The same message whether the product exists or not.
    $this->assertFails(fn () => $cart->resolveVariation('10', NULL, $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('DRAFT-1', NULL, $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('11', '31', $this->account), 'The product is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('12', '41', $this->account), 'The selected option of "Shirt" is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('12', '42', $this->account), 'The selected option of "Shirt" is not available.');
    $this->assertFails(fn () => $cart->resolveVariation('12', NULL, $this->account), '"Shirt" cannot be purchased at the moment.');
    $this->assertFails(fn () => $cart->resolveVariation('13', NULL, $this->account), '"Empty" cannot be purchased at the moment.');
  }

  /**
   * Stores are chosen as Commerce's SelectStoreTrait does.
   */
  public function testResolveStore(): void {
    $main = $this->store(1);
    $outlet = $this->store(2, 'Outlet');
    $closed = $this->store(3, 'Closed', FALSE);
    $cart = $this->cartService();

    $single = $this->variation(21, $this->product(10, 'Shirt', [$outlet]), 'A');
    $this->assertSame($outlet, $cart->resolveStore($single));

    $several = $this->variation(22, $this->product(11, 'Hat', [$main, $outlet]), 'B');
    $this->currentStore->method('getStore')->willReturnOnConsecutiveCalls($outlet, $closed, NULL);
    $this->assertSame($outlet, $cart->resolveStore($several));
    $this->assertFails(fn () => $cart->resolveStore($several), 'The product cannot be purchased from the current store.');
    $this->assertFails(fn () => $cart->resolveStore($several), 'The product cannot be purchased from the current store.');

    $none = $this->variation(23, $this->product(12, 'Orphan'), 'C');
    $this->assertFails(fn () => $cart->resolveStore($none), 'The product cannot be purchased from this site.');
    $unpublished = $this->variation(24, $this->product(13, 'Closed', [$closed]), 'D');
    $this->assertFails(fn () => $cart->resolveStore($unpublished), 'The product cannot be purchased from the current store.');
  }

  /**
   * Adding creates the cart of the store when needed.
   */
  public function testAddCreatesCart(): void {
    $store = $this->store(1);
    $variation = $this->variation(21, $this->product(10, 'Shirt', [$store]), 'SHIRT-RED');
    $orderItem = $this->orderItem(501, $variation, '2');
    $cart = $this->cart(7, [], '0');
    $this->cartManager->expects($this->once())->method('createOrderItem')->with($variation, '2')->willReturn($orderItem);
    $this->cartProvider->expects($this->once())->method('getCart')->with('default', $store, $this->account)->willReturn(NULL);
    $this->cartProvider->expects($this->once())->method('createCart')->with('default', $store, $this->account)->willReturn($cart);
    $this->cartManager->expects($this->once())->method('addOrderItem')->with($cart, $orderItem, TRUE, TRUE)->willReturn($orderItem);

    $this->assertSame($orderItem, $this->cartService()->add($variation, 2, $this->account));
  }

  /**
   * Quantities are limited to 1..99 per variation, locked carts refused.
   */
  public function testAddLimits(): void {
    $store = $this->store(1);
    $variation = $this->variation(21, $this->product(10, 'Shirt', [$store]), 'SHIRT-RED');
    $this->cartManager->method('createOrderItem')->willReturn($this->orderItem(500, $variation));
    $existing = $this->cart(7, [$this->orderItem(501, $variation, '98.00')], '980');
    $this->cartProvider->method('getCart')->willReturn($existing);
    $this->cartManager->expects($this->never())->method('addOrderItem');
    $cart = $this->cartService();

    $this->assertFails(fn () => $cart->add($variation, 0, $this->account), 'The quantity must be between 1 and 99.');
    $this->assertFails(fn () => $cart->add($variation, 100, $this->account), 'The quantity must be between 1 and 99.');
    $this->assertFails(fn () => $cart->add($variation, 2, $this->account), 'Your cart can hold at most 99 units of "Shirt - SHIRT-RED".');
  }

  /**
   * Locked carts (checkout or payment in progress) are never changed.
   */
  public function testLockedCart(): void {
    $store = $this->store(1);
    $variation = $this->variation(21, $this->product(10, 'Shirt', [$store]), 'SHIRT-RED');
    $line = $this->orderItem(501, $variation);
    $locked = $this->cart(8, [$line], '10', $store, TRUE);
    $this->cartManager->method('createOrderItem')->willReturn($this->orderItem(500, $variation));
    $this->cartProvider->method('getCart')->willReturn($locked);
    $this->cartManager->expects($this->never())->method('addOrderItem');
    $this->cartManager->expects($this->never())->method('updateOrderItem');
    $this->cartManager->expects($this->never())->method('removeOrderItem');
    $cart = $this->cartService();

    $message = 'Your cart is being checked out and cannot be changed now.';
    $this->assertFails(fn () => $cart->add($variation, 1, $this->account), $message);
    $this->assertFails(fn () => $cart->update('10', '21', 2, $this->account), $message);
    $this->assertFails(fn () => $cart->remove('10', NULL, $this->account), $message);
  }

  /**
   * Adding several products reports failures and saves each cart once.
   */
  public function testAddManyPartialReport(): void {
    $store = $this->store(1);
    $shirt = $this->product(10, 'Shirt', [$store]);
    $red = $this->variation(21, $shirt, 'SHIRT-RED');
    $hat = $this->product(11, 'Hat', [$store]);
    $hatS = $this->variation(31, $hat, 'HAT-S');
    $hat->method('getDefaultVariation')->willReturn($hatS);
    $cart = $this->cart(7, [], '0', $store);
    $this->cartProvider->method('getCart')->willReturn($cart);
    $this->cartManager->method('createOrderItem')->willReturnCallback(fn (ProductVariationInterface $variation, string $quantity): OrderItemInterface => $this->orderItem(600, $variation, $quantity));
    $this->cartManager->expects($this->exactly(2))->method('addOrderItem')
      ->with($cart, $this->isInstanceOf(OrderItemInterface::class), TRUE, FALSE)
      ->willReturnArgument(1);
    $cart->expects($this->once())->method('save');

    $report = $this->cartService()->addMany([
      ['product_id' => '10', 'variation_id' => '21', 'quantity' => 2],
      ['product_id' => '404', 'variation_id' => NULL, 'quantity' => 1],
      ['product_id' => 'HAT-S'],
      ['product_id' => '11', 'variation_id' => '21'],
    ], $this->account);

    $this->assertSame([
      ['product_id' => '10', 'variation_id' => '21', 'title' => 'Shirt - SHIRT-RED', 'quantity' => 2],
      ['product_id' => '11', 'variation_id' => '31', 'title' => 'Hat - HAT-S', 'quantity' => 1],
    ], $report['added']);
    $this->assertSame([
      ['product_id' => '404', 'message' => 'The product is not available.'],
      ['product_id' => '11', 'variation_id' => '21', 'message' => 'The product is not available.'],
    ], $report['failed']);
    $this->assertNotSame($red, $hatS);
  }

  /**
   * The view lists product_item entries of every cart of the account.
   */
  public function testView(): void {
    $main = $this->store(1, 'Main store');
    $outlet = $this->store(2, 'Outlet', TRUE, FALSE);
    $shirt = $this->product(10, 'Shirt', [$main]);
    $red = $this->variation(21, $shirt, 'SHIRT-RED');
    $private = $this->product(11, 'Private', [$outlet], TRUE, FALSE);
    $secret = $this->variation(31, $private, 'SECRET');
    $this->cart(7, [$this->orderItem(501, $red, '2.00', '19.90')], '39.80', $main);
    $this->cart(8, [$this->orderItem(502, $secret, '1.00', '5.00'), $this->orderItem(503, NULL, '1.00', '1.00')], '6.00', $outlet);
    $this->cart(9, [], '0', $main);

    $view = $this->cartService()->view($this->account);

    $this->assertCartView($view);
    $this->assertSame([
      'product_id' => '10',
      'variation_id' => '21',
      'title' => 'Shirt - SHIRT-RED',
      'url' => '/product/10',
      'price' => '19,90 EUR',
      'price_amount' => 19.9,
      'currency' => 'EUR',
      'quantity' => 2,
      'store_name' => 'Main store',
    ], $view['items'][0]);
    // No URL for a product the account cannot view, no label of a store it
    // cannot view.
    $this->assertSame([
      'product_id' => '11',
      'variation_id' => '31',
      'title' => 'Private - SECRET',
      'price' => '5,00 EUR',
      'price_amount' => 5.0,
      'currency' => 'EUR',
      'quantity' => 1,
    ], $view['items'][1]);
    $this->assertSame('order_item:503', $view['items'][2]['product_id']);
    $this->assertArrayNotHasKey('variation_id', $view['items'][2]);
    $this->assertSame('45,80 EUR', $view['total']);
    $this->assertSame(3, $view['items_count']);

    $this->carts = [];
    $this->assertSame(['items' => [], 'total' => '', 'items_count' => 0], $this->cartService()->view($this->account));
  }

  /**
   * Update sets the quantity of the matching line; 0 removes it.
   */
  public function testUpdate(): void {
    $store = $this->store(1);
    $shirt = $this->product(10, 'Shirt', [$store]);
    $red = $this->variation(21, $shirt, 'SHIRT-RED');
    $line = $this->orderItem(501, $red, '1.00');
    $cart = $this->cart(7, [$line], '10', $store);
    $line->expects($this->once())->method('setQuantity')->with('3');
    $this->cartManager->expects($this->once())->method('updateOrderItem')->with($cart, $line);
    $this->cartManager->expects($this->once())->method('removeOrderItem')->with($cart, $line);
    $service = $this->cartService();

    $this->assertSame(1, $service->update('10', '21', 3, $this->account));
    $this->assertSame(1, $service->update('10', NULL, 0, $this->account));
    $this->assertFails(fn () => $service->update('10', '21', 100, $this->account), 'The quantity must be between 0 and 99.');
    $this->assertFails(fn () => $service->update('11', NULL, 2, $this->account), 'This product is not in your cart.');
  }

  /**
   * Update is refused when ambiguous or when the product is unavailable.
   */
  public function testUpdateAmbiguousOrUnavailable(): void {
    $store = $this->store(1);
    $shirt = $this->product(10, 'Shirt', [$store]);
    $red = $this->variation(21, $shirt, 'SHIRT-RED');
    $blue = $this->variation(22, $shirt, 'SHIRT-BLUE', '10.00', FALSE);
    $this->cart(7, [$this->orderItem(501, $red), $this->orderItem(502, $blue)], '20', $store);
    $this->cartManager->expects($this->never())->method('updateOrderItem');
    $service = $this->cartService();

    $exception = $this->assertFails(fn () => $service->update('10', NULL, 2, $this->account), 'Several options of this product are in your cart. Please choose one.');
    $this->assertSame([
      ['product_id' => '10', 'variation_id' => '21', 'title' => 'Shirt - SHIRT-RED'],
      ['product_id' => '10', 'variation_id' => '22', 'title' => 'Shirt - SHIRT-BLUE'],
    ], $exception->getData()['candidates']);
    $this->assertFails(fn () => $service->update('10', '22', 2, $this->account), 'This product is no longer available. You can remove it from your cart.');
  }

  /**
   * Remove deletes every matching line of the account's carts only.
   */
  public function testRemove(): void {
    $store = $this->store(1);
    $shirt = $this->product(10, 'Shirt', [$store]);
    $red = $this->variation(21, $shirt, 'SHIRT-RED');
    $blue = $this->variation(22, $shirt, 'SHIRT-BLUE');
    $hat = $this->variation(31, $this->product(11, 'Hat', [$store]), 'HAT-S');
    $redLine = $this->orderItem(501, $red);
    $blueLine = $this->orderItem(502, $blue);
    $hatLine = $this->orderItem(503, $hat);
    $orphan = $this->orderItem(504, NULL);
    $cart = $this->cart(7, [$redLine, $blueLine, $hatLine, $orphan], '40', $store);
    $removed = [];
    $this->cartManager->method('removeOrderItem')->willReturnCallback(function (OrderInterface $from, OrderItemInterface $item) use ($cart, &$removed): void {
      $this->assertSame($cart, $from);
      $removed[] = $item->id();
    });
    $service = $this->cartService();

    // Every option of the product.
    $this->assertSame(2, $service->remove('10', NULL, $this->account));
    // By SKU, in variation_id or in product_id.
    $this->assertSame(1, $service->remove('11', 'HAT-S', $this->account));
    $this->assertSame(1, $service->remove('SHIRT-BLUE', NULL, $this->account));
    // A line whose product was deleted, as identified by view().
    $this->assertSame(1, $service->remove('order_item:504', NULL, $this->account));
    $this->assertSame([501, 502, 503, 502, 504], $removed);
    $this->assertFails(fn () => $service->remove('12', NULL, $this->account), 'This product is not in your cart.');
    $this->assertFails(fn () => $service->remove('10', '99', $this->account), 'This product is not in your cart.');
  }

  /**
   * The checkout form of the cart, else the cart page.
   */
  public function testCheckoutUrl(): void {
    $store = $this->store(1);
    $red = $this->variation(21, $this->product(10, 'Shirt', [$store]), 'SHIRT-RED');
    $service = $this->cartService();
    $this->assertFails(fn () => $service->checkoutUrl($this->account), 'Your cart is empty.');

    $this->cart(7, [$this->orderItem(501, $red)], '10', $store);
    $this->accessManager->method('checkNamedRoute')
      ->with(CartService::CHECKOUT_ROUTE, ['commerce_order' => 7], $this->account)
      ->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $this->assertSame('/checkout/7', $service->checkoutUrl($this->account));
    // No access (no checkout module, no permission, locked cart...).
    $this->assertSame('/cart', $service->checkoutUrl($this->account));

    // Several carts: the cart page lets the user choose.
    $this->cart(8, [$this->orderItem(502, $red)], '10', $this->store(2));
    $this->assertSame('/cart', $service->checkoutUrl($this->account));
  }

}
