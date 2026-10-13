<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_commerce\Unit\HostAction;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\webassistant_connector_commerce\Unit\CommerceUnitTestBase;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionException;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Drupal\webassistant_connector_commerce\HostAction\CartActionBase;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\CartAdd;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\CartAddMany;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\CartCheckout;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\CartGet;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\CartRemove;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\CartUpdate;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction\SalesReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cart and analytics host actions (design section 12).
 *
 * @group webassistant_connector
 */
#[CoversClass(CartActionBase::class)]
#[CoversClass(CartGet::class)]
#[CoversClass(CartAdd::class)]
#[CoversClass(CartAddMany::class)]
#[CoversClass(CartUpdate::class)]
#[CoversClass(CartRemove::class)]
#[CoversClass(CartCheckout::class)]
#[CoversClass(SalesReport::class)]
#[Group('webassistant_connector')]
final class CartActionsTest extends CommerceUnitTestBase {

  /**
   * The account.
   */
  private AccountInterface $account;

  /**
   * Permissions of the account.
   *
   * @var list<string>
   */
  private array $permissions = ['use webassistant cart actions'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturnCallback(fn (string $permission): bool => in_array($permission, $this->permissions, TRUE));
    $this->account = $account;
  }

  /**
   * Builds a plugin from its #[HostAction] attribute.
   *
   * @param class-string<\Drupal\webassistant_connector\HostAction\HostActionPluginBase> $class
   *   The plugin class.
   * @param object ...$services
   *   The services of the constructor.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionPluginBase
   *   The plugin.
   */
  private function plugin(string $class, object ...$services): HostActionPluginBase {
    $attribute = (new \ReflectionClass($class))->getAttributes(HostAction::class)[0]->newInstance();
    $definition = [
      'id' => $attribute->id,
      'label' => $attribute->label,
      'permission' => $attribute->permission,
      'effects' => $attribute->effects,
      'class' => $class,
      'provider' => 'webassistant_connector_commerce',
    ];
    $plugin = new $class([], $attribute->id, $definition, ...$services);
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * Builds a request.
   *
   * @param string $name
   *   The action name.
   * @param array<string, mixed> $params
   *   The parameters.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionRequest
   *   The request.
   */
  private static function request(string $name, array $params = []): HostActionRequest {
    return new HostActionRequest($name, 'exec-1', 'corr-1', $params);
  }

  /**
   * Runs an action expecting a user-facing failure.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionPluginBase $plugin
   *   The plugin.
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $message
   *   The expected message.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionException
   *   The exception.
   */
  private function assertFailure(HostActionPluginBase $plugin, array $params, string $message): HostActionException {
    try {
      $plugin->execute(self::request($plugin->getPluginId(), $params), $this->account);
    }
    catch (HostActionException $exception) {
      $this->assertSame($message, $exception->getMessage());
      return $exception;
    }
    $this->fail('A HostActionException was expected: ' . $message);
  }

  /**
   * Sets up a shirt with one variation in one store and an empty cart.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The variation.
   */
  private function catalog(): ProductVariationInterface {
    $store = $this->store(1);
    $product = $this->product(10, 'Shirt', [$store]);
    $variation = $this->variation(21, $product, 'SHIRT-RED', '19.90');
    $product->method('getDefaultVariation')->willReturn($variation);
    return $variation;
  }

  /**
   * Creates the order item of a purchased variation (cart manager callback).
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $purchased
   *   The variation.
   * @param string $quantity
   *   The quantity.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The order item.
   */
  private function createOrderItem(ProductVariationInterface $purchased, string $quantity): OrderItemInterface {
    return $this->orderItem(600, $purchased, $quantity);
  }

  /**
   * The definitions follow the action contracts of the platform skills.
   *
   * @param class-string $class
   *   The plugin class.
   * @param string $id
   *   The expected host action name.
   * @param string $permission
   *   The expected permission.
   * @param bool $effects
   *   Whether the action changes data.
   */
  #[DataProvider('providerDefinitions')]
  public function testDefinitions(string $class, string $id, string $permission, bool $effects): void {
    $attribute = (new \ReflectionClass($class))->getAttributes(HostAction::class)[0]->newInstance();
    $this->assertSame($id, $attribute->id);
    $this->assertSame($permission, $attribute->permission);
    $this->assertSame($effects, $attribute->effects);
    $this->assertMatchesRegularExpression(HostActionRequest::NAME_PATTERN, $attribute->id);
  }

  /**
   * Data provider for testDefinitions().
   *
   * @return array<string, array{0: class-string, 1: string, 2: string, 3: bool}>
   *   Class, ID, permission and effects.
   */
  public static function providerDefinitions(): array {
    return [
      'cart.get' => [CartGet::class, 'cart.get', 'use webassistant cart actions', FALSE],
      'cart.add' => [CartAdd::class, 'cart.add', 'use webassistant cart actions', TRUE],
      'cart.add_many' => [CartAddMany::class, 'cart.add_many', 'use webassistant cart actions', TRUE],
      'cart.update' => [CartUpdate::class, 'cart.update', 'use webassistant cart actions', TRUE],
      'cart.remove' => [CartRemove::class, 'cart.remove', 'use webassistant cart actions', TRUE],
      'cart.checkout' => [CartCheckout::class, 'cart.checkout', 'use webassistant cart actions', FALSE],
      'analytics.sales_report' => [
        SalesReport::class,
        'analytics.sales_report',
        'use webassistant analytics actions',
        FALSE,
      ],
    ];
  }

  /**
   * Cart actions need their permission; the report also the admin pages.
   */
  public function testAccess(): void {
    $add = $this->plugin(CartAdd::class, $this->cartService());
    $report = $this->plugin(SalesReport::class, $this->accessManager, $this->createMock(UrlGeneratorInterface::class));
    $this->assertTrue($add->access($this->account)->isAllowed());
    $this->assertFalse($report->access($this->account)->isAllowed());

    $this->permissions = ['use webassistant analytics actions'];
    $this->assertFalse($add->access($this->account)->isAllowed());
    $this->assertFalse($report->access($this->account)->isAllowed());
    $this->permissions[] = SalesReport::ADMIN_PERMISSION;
    $this->assertTrue($report->access($this->account)->isAllowed());
  }

  /**
   * The cart.get action returns the cart and accepts no parameters.
   */
  public function testCartGet(): void {
    $variation = $this->catalog();
    $this->cart(7, [$this->orderItem(501, $variation, '2.00', '19.90')], '39.80');
    $plugin = $this->plugin(CartGet::class, $this->cartService());

    $result = $plugin->execute(self::request('cart.get'), $this->account);
    $this->assertTrue($result->isSucceeded());
    $this->assertNull($result->message);
    $this->assertCartView($result->data);
    $this->assertSame('39,80 EUR', $result->data['total']);
    $this->assertSame(1, $result->data['items_count']);
    $this->assertFailure($plugin, ['product_id' => '10'], 'Unexpected parameter product_id.');
  }

  /**
   * The cart.add action validates its parameters and returns the cart.
   */
  public function testCartAdd(): void {
    $variation = $this->catalog();
    $cart = $this->cart(7, [], '0');
    $this->cartProvider->method('getCart')->willReturn($cart);
    $this->cartManager->method('createOrderItem')->willReturnCallback($this->createOrderItem(...));
    $this->cartManager->expects($this->exactly(2))->method('addOrderItem')->willReturnArgument(1);
    $plugin = $this->plugin(CartAdd::class, $this->cartService());

    $result = $plugin->execute(self::request('cart.add', ['product_id' => '10', 'variation_id' => NULL]), $this->account);
    $this->assertTrue($result->isSucceeded());
    $this->assertSame('Added "Shirt - SHIRT-RED" to the cart.', $result->message);
    $this->assertSame(['product_id' => '10', 'variation_id' => '21', 'quantity' => 1], $result->data['added']);
    $this->assertSame(['items', 'total', 'items_count', 'added'], array_keys($result->data));

    $params = ['product_id' => 10, 'variation_id' => 'SHIRT-RED', 'quantity' => 3];
    $result = $plugin->execute(self::request('cart.add', $params), $this->account);
    $this->assertSame('Added 3 units of "Shirt - SHIRT-RED" to the cart.', $result->message);
    $this->assertSame($variation->id(), (int) $result->data['added']['variation_id']);

    $this->assertFailure($plugin, [], 'The parameter product_id is required.');
    $this->assertFailure($plugin, ['product_id' => '10', 'quantity' => 0], 'The parameter quantity is not valid.');
    $this->assertFailure($plugin, ['product_id' => '10', 'quantity' => 100], 'The parameter quantity is not valid.');
    $this->assertFailure($plugin, ['product_id' => '10', 'quantity' => 'two'], 'The parameter quantity is not valid.');
    $this->assertFailure($plugin, ['product_id' => str_repeat('1', 129)], 'The parameter product_id is not valid.');
    $this->assertFailure($plugin, ['product_id' => '10', 'price' => '0.01'], 'Unexpected parameter price.');
    $this->assertFailure($plugin, ['product_id' => '404'], 'The product is not available.');
  }

  /**
   * The cart.add_many action adds the valid items and reports the others.
   */
  public function testCartAddMany(): void {
    $this->catalog();
    $cart = $this->cart(7, [], '0');
    $this->cartProvider->method('getCart')->willReturn($cart);
    $this->cartManager->method('createOrderItem')->willReturnCallback($this->createOrderItem(...));
    $this->cartManager->method('addOrderItem')->willReturnArgument(1);
    $plugin = $this->plugin(CartAddMany::class, $this->cartService());

    $result = $plugin->execute(self::request('cart.add_many', [
      'items' => [
        ['product_id' => '10', 'variation_id' => '21', 'quantity' => 2],
        'SHIRT-RED',
        ['product_id' => '10', 'color' => 'red'],
        ['quantity' => 1],
        ['product_id' => '404'],
      ],
    ]), $this->account);
    $this->assertTrue($result->isSucceeded());
    $this->assertSame('1 product added to the cart. 4 products could not be added.', $result->message);
    $this->assertSame([
      ['product_id' => '10', 'variation_id' => '21', 'title' => 'Shirt - SHIRT-RED', 'quantity' => 2],
    ], $result->data['added']);
    $this->assertSame([
      ['position' => 1, 'message' => 'The item is not valid.'],
      ['position' => 2, 'product_id' => '10', 'message' => 'Unexpected parameter color.'],
      ['position' => 3, 'message' => 'The parameter product_id is required.'],
      ['product_id' => '404', 'message' => 'The product is not available.'],
    ], $result->data['failed']);

    $exception = $this->assertFailure($plugin, ['items' => [['product_id' => '404']]], 'No product could be added to the cart.');
    $this->assertSame([], $exception->getData()['added']);
    $this->assertCount(1, $exception->getData()['failed']);
    $this->assertFailure($plugin, ['items' => array_fill(0, 101, ['product_id' => '10'])], 'The parameter items may contain at most 100 items.');
    $this->assertFailure($plugin, ['items' => ['product_id' => '10']], 'The parameter items must be a list.');
    $this->assertFailure($plugin, ['items' => []], 'The parameter items is required.');
  }

  /**
   * The cart.update action sets quantities; 0 removes the product.
   */
  public function testCartUpdate(): void {
    $variation = $this->catalog();
    $line = $this->orderItem(501, $variation, '1.00');
    $this->cart(7, [$line], '19.90');
    $line->expects($this->once())->method('setQuantity')->with('3');
    $this->cartManager->expects($this->once())->method('updateOrderItem');
    $this->cartManager->expects($this->once())->method('removeOrderItem');
    $plugin = $this->plugin(CartUpdate::class, $this->cartService());

    $params = ['product_id' => '10', 'variation_id' => '21', 'quantity' => 3];
    $result = $plugin->execute(self::request('cart.update', $params), $this->account);
    $this->assertSame('Quantity updated to 3.', $result->message);
    $this->assertCartView($result->data);
    $result = $plugin->execute(self::request('cart.update', ['product_id' => '10', 'quantity' => 0]), $this->account);
    $this->assertSame('The product was removed from the cart.', $result->message);

    $this->assertFailure($plugin, ['product_id' => '10'], 'The parameter quantity is required.');
    $this->assertFailure($plugin, ['product_id' => '10', 'quantity' => -1], 'The parameter quantity is not valid.');
    $this->assertFailure($plugin, ['product_id' => '11', 'quantity' => 1], 'This product is not in your cart.');
  }

  /**
   * The cart.remove action removes the product from the cart.
   */
  public function testCartRemove(): void {
    $variation = $this->catalog();
    $this->cart(7, [$this->orderItem(501, $variation)], '19.90');
    $this->cartManager->expects($this->once())->method('removeOrderItem');
    $plugin = $this->plugin(CartRemove::class, $this->cartService());

    $result = $plugin->execute(self::request('cart.remove', ['product_id' => '10', 'variation_id' => '21']), $this->account);
    $this->assertSame('The product was removed from the cart.', $result->message);
    $this->assertFailure($plugin, ['product_id' => '10', 'quantity' => 1], 'Unexpected parameter quantity.');
  }

  /**
   * The cart.checkout action opens the checkout, else the cart page.
   */
  public function testCartCheckout(): void {
    $variation = $this->catalog();
    $plugin = $this->plugin(CartCheckout::class, $this->cartService());
    $this->assertFailure($plugin, [], 'Your cart is empty.');

    $this->cart(7, [$this->orderItem(501, $variation)], '19.90');
    $this->accessManager->method('checkNamedRoute')->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $result = $plugin->execute(self::request('cart.checkout'), $this->account);
    $this->assertSame('/checkout/7', $result->redirect);
    $this->assertSame('Opening the checkout.', $result->message);
    $this->assertCartView($result->data);
    $result = $plugin->execute(self::request('cart.checkout'), $this->account);
    $this->assertSame('/cart', $result->redirect);
    $this->assertSame('Opening your cart to complete the purchase.', $result->message);
  }

  /**
   * The analytics.sales_report action validates and redirects.
   */
  public function testSalesReport(): void {
    $accessManager = $this->createMock(AccessManagerInterface::class);
    $accessManager->method('checkNamedRoute')
      ->with(SalesReport::ORDERS_ROUTE, [], $this->account)
      ->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn((new GeneratedUrl())->setGeneratedUrl('/admin/commerce/orders'));
    $plugin = $this->plugin(SalesReport::class, $accessManager, $urlGenerator);

    $result = $plugin->execute(self::request('analytics.sales_report', [
      'range' => 'custom',
      'focus' => 'revenue',
      'from' => '2026-01-01',
      'to' => '2026-03-31',
    ]), $this->account);
    $this->assertEquals(HostActionResult::succeeded(
      ['range' => 'custom', 'focus' => 'revenue', 'from' => '2026-01-01', 'to' => '2026-03-31'],
      'Opening the order administration.',
      '/admin/commerce/orders',
    ), $result);
    $this->assertFailure($plugin, ['range' => 'month', 'focus' => 'orders'], 'You are not allowed to view the order administration.');

    $this->assertFailure($plugin, ['range' => 'week', 'focus' => 'orders'], 'The parameter range is not valid.');
    $this->assertFailure($plugin, ['range' => 'month'], 'The parameter focus is required.');
    $this->assertFailure($plugin, ['range' => 'custom', 'focus' => 'orders', 'from' => '2026-02-30'], 'The parameter from is not valid.');
    $this->assertFailure($plugin, ['range' => 'custom', 'focus' => 'orders', 'to' => '31/03/2026'], 'The parameter to is not valid.');
    $params = ['range' => 'custom', 'focus' => 'orders', 'from' => '2026-04-01', 'to' => '2026-03-31'];
    $this->assertFailure($plugin, $params, 'The start date must be before the end date.');
    $this->assertFailure($plugin, ['range' => 'all', 'focus' => 'orders', 'store' => '1'], 'Unexpected parameter store.');
  }

}
