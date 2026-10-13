<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_commerce\Unit;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\Resolver\ChainOrderTypeResolverInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Resolver\ChainPriceResolverInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\ProductVariationStorageInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\webassistant_connector\Sync\SourceIdGenerator;
use Drupal\webassistant_connector_commerce\Cart\CartService;
use Drupal\webassistant_connector_commerce\Price\PriceHelper;
use JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Base class of the Commerce extension unit tests.
 *
 * Drupal Commerce cannot be installed in the verification environment: the
 * interfaces and value objects the extension uses are loaded from
 * tests/stubs (same namespaces and signatures as Commerce 3.x) unless the
 * real Commerce classes are available. The base also builds a CartService
 * whose Commerce services are mocks, and in-memory products, variations,
 * stores, carts and order items.
 */
abstract class CommerceUnitTestBase extends UnitTestCase {

  /**
   * Source ID prefix used by the tests.
   */
  protected const PREFIX = 'drupal-test';

  /**
   * Whether the stub autoloader is registered.
   */
  private static bool $stubsRegistered = FALSE;

  /**
   * Products keyed by ID.
   *
   * @var array<string, \Drupal\commerce_product\Entity\ProductInterface&\PHPUnit\Framework\MockObject\MockObject>
   */
  protected array $products = [];

  /**
   * Variations keyed by ID.
   *
   * @var array<string, \Drupal\commerce_product\Entity\ProductVariationInterface&\PHPUnit\Framework\MockObject\MockObject>
   */
  protected array $variations = [];

  /**
   * Variations keyed by SKU.
   *
   * @var array<string, \Drupal\commerce_product\Entity\ProductVariationInterface>
   */
  protected array $skus = [];

  /**
   * Carts returned by the cart provider.
   *
   * @var list<\Drupal\commerce_order\Entity\OrderInterface>
   */
  protected array $carts = [];

  /**
   * The cart provider.
   */
  protected CartProviderInterface&MockObject $cartProvider;

  /**
   * The cart manager.
   */
  protected CartManagerInterface&MockObject $cartManager;

  /**
   * The current store.
   */
  protected CurrentStoreInterface&MockObject $currentStore;

  /**
   * The access manager.
   */
  protected AccessManagerInterface&MockObject $accessManager;

  /**
   * The chain price resolver.
   */
  protected ChainPriceResolverInterface&MockObject $priceResolver;

  /**
   * The currency formatter.
   */
  protected CurrencyFormatterInterface&MockObject $currencyFormatter;

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    self::registerCommerceStubs();
  }

  /**
   * Registers the autoloader of tests/stubs (only for missing classes).
   */
  public static function registerCommerceStubs(): void {
    if (self::$stubsRegistered) {
      return;
    }
    self::$stubsRegistered = TRUE;
    $root = dirname(__DIR__, 2) . '/stubs/';
    spl_autoload_register(static function (string $class) use ($root): void {
      if (!preg_match('/^(Drupal\\\\commerce(_[a-z]+)?\\\\|CommerceGuys\\\\Intl\\\\)/', $class)) {
        return;
      }
      $file = $root . str_replace('\\', '/', $class) . '.php';
      if (is_file($file)) {
        require_once $file;
      }
    });
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->cartProvider = $this->createMock(CartProviderInterface::class);
    $this->cartProvider->method('getCarts')->willReturnCallback(fn (): array => $this->carts);
    $this->cartManager = $this->createMock(CartManagerInterface::class);
    $this->currentStore = $this->createMock(CurrentStoreInterface::class);
    $this->accessManager = $this->createMock(AccessManagerInterface::class);
    $this->priceResolver = $this->createMock(ChainPriceResolverInterface::class);
    $this->currencyFormatter = $this->createMock(CurrencyFormatterInterface::class);
    // "1.234,50 EUR [es]": the locale is visible to assert it was passed.
    $this->currencyFormatter->method('format')->willReturnCallback(
      static fn (string $number, string $code, array $options = []): string => number_format((float) $number, 2, ',', '.') . ' ' . $code . (isset($options['locale']) ? ' [' . $options['locale'] . ']' : ''),
    );
  }

  /**
   * Builds the price helper with the mocked resolver and formatter.
   */
  protected function priceHelper(): PriceHelper {
    return new PriceHelper($this->priceResolver, $this->currencyFormatter, $this->createMock(LoggerInterface::class));
  }

  /**
   * Builds the cart service with the mocked Commerce services.
   */
  protected function cartService(): CartService {
    $productStorage = $this->createMock(EntityStorageInterface::class);
    $productStorage->method('load')->willReturnCallback(fn (mixed $id): ?ProductInterface => $this->products[(string) $id] ?? NULL);
    $variationStorage = $this->createMock(ProductVariationStorageInterface::class);
    $variationStorage->method('load')->willReturnCallback(fn (mixed $id): ?ProductVariationInterface => $this->variations[(string) $id] ?? NULL);
    $variationStorage->method('loadBySku')->willReturnCallback(fn (mixed $sku): ?ProductVariationInterface => $this->skus[(string) $sku] ?? NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_product', $productStorage],
      ['commerce_product_variation', $variationStorage],
    ]);

    $orderTypeResolver = $this->createMock(ChainOrderTypeResolverInterface::class);
    $orderTypeResolver->method('resolve')->willReturn('default');
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturnCallback(static function (string $route, array $parameters = []): GeneratedUrl {
      $path = match ($route) {
        CartService::CHECKOUT_ROUTE => '/checkout/' . $parameters['commerce_order'],
        CartService::CART_ROUTE => '/cart',
        default => '/admin/commerce/orders',
      };
      return (new GeneratedUrl())->setGeneratedUrl($path);
    });

    $service = new CartService(
      $entityTypeManager,
      $this->cartProvider,
      $this->cartManager,
      $orderTypeResolver,
      $this->currentStore,
      $this->priceHelper(),
      new SourceIdGenerator($this->getConfigFactoryStub([
        'webassistant_connector.settings' => ['sync' => ['source_id_prefix' => self::PREFIX]],
      ])),
      $this->accessManager,
      $urlGenerator,
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->getStringTranslationStub(),
    );
    return $service;
  }

  /**
   * Creates a product.
   *
   * @param int $id
   *   The product ID.
   * @param string $title
   *   The title.
   * @param list<\Drupal\commerce_store\Entity\StoreInterface> $stores
   *   The stores.
   * @param bool $published
   *   Whether it is published.
   * @param bool $viewable
   *   Whether the account may view it.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The product (variations are attached by variation()).
   */
  protected function product(int $id, string $title, array $stores = [], bool $published = TRUE, bool $viewable = TRUE): ProductInterface&MockObject {
    $product = $this->createMock(ProductInterface::class);
    $product->method('id')->willReturn($id);
    $product->method('label')->willReturn($title);
    $product->method('getTitle')->willReturn($title);
    $product->method('getEntityTypeId')->willReturn('commerce_product');
    $product->method('isPublished')->willReturn($published);
    $product->method('access')->willReturn($viewable);
    $product->method('getStores')->willReturn($stores);
    $product->method('getFieldDefinitions')->willReturn([]);
    $product->method('getVariations')->willReturnCallback(fn (): array => array_values(array_filter(
      $this->variations,
      static fn (ProductVariationInterface $variation): bool => $variation->getProductId() === $id,
    )));
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn((new GeneratedUrl())->setGeneratedUrl('/product/' . $id));
    $product->method('toUrl')->willReturn($url);
    $this->products[(string) $id] = $product;
    return $product;
  }

  /**
   * Creates a variation of a product.
   *
   * @param int $id
   *   The variation ID.
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param string $sku
   *   The SKU.
   * @param string $price
   *   The price number (EUR).
   * @param bool $published
   *   Whether it is published.
   * @param bool $viewable
   *   Whether the account may view it.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The variation.
   */
  protected function variation(int $id, ProductInterface $product, string $sku, string $price = '10.00', bool $published = TRUE, bool $viewable = TRUE): ProductVariationInterface&MockObject {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn($id);
    $variation->method('label')->willReturn($product->label() . ' - ' . $sku);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('getProductId')->willReturn((int) $product->id());
    $variation->method('getProduct')->willReturn($product);
    $variation->method('getSku')->willReturn($sku);
    $variation->method('isPublished')->willReturn($published);
    $variation->method('access')->willReturn($viewable);
    $variation->method('getStores')->willReturnCallback(static fn (): array => $product->getStores());
    $variation->method('getPrice')->willReturn(new Price($price, 'EUR'));
    $variation->method('getFieldDefinitions')->willReturn([]);
    $this->variations[(string) $id] = $variation;
    $this->skus[$sku] = $variation;
    return $variation;
  }

  /**
   * Creates a store.
   *
   * @param int $id
   *   The store ID.
   * @param string $name
   *   The name.
   * @param bool $published
   *   Whether it is published.
   * @param bool $viewable
   *   Whether its label is viewable.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The store.
   */
  protected function store(int $id, string $name = 'Main store', bool $published = TRUE, bool $viewable = TRUE): StoreInterface&MockObject {
    $store = $this->createMock(StoreInterface::class);
    $store->method('id')->willReturn($id);
    $store->method('label')->willReturn($name);
    $store->method('getName')->willReturn($name);
    $store->method('isPublished')->willReturn($published);
    $store->method('isDefault')->willReturn($id === 1);
    $store->method('access')->willReturn($viewable);
    return $store;
  }

  /**
   * Creates an order item.
   *
   * @param int $id
   *   The order item ID.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface|null $variation
   *   The purchased variation (NULL: deleted).
   * @param string $quantity
   *   The quantity (decimal string, as Commerce stores it).
   * @param string $unitPrice
   *   The unit price (EUR).
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The order item.
   */
  protected function orderItem(int $id, ?ProductVariationInterface $variation, string $quantity = '1.00', string $unitPrice = '10.00'): OrderItemInterface&MockObject {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('id')->willReturn($id);
    $item->method('getPurchasedEntity')->willReturn($variation);
    $item->method('getPurchasedEntityId')->willReturn($variation?->id());
    $item->method('getTitle')->willReturn($variation !== NULL ? (string) $variation->label() : 'Deleted product');
    $item->method('getQuantity')->willReturn($quantity);
    $item->method('getUnitPrice')->willReturn(new Price($unitPrice, 'EUR'));
    return $item;
  }

  /**
   * Creates a cart and adds it to the carts of the account.
   *
   * @param int $id
   *   The order ID.
   * @param list<\Drupal\commerce_order\Entity\OrderItemInterface> $items
   *   The order items.
   * @param string $total
   *   The total (EUR).
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store.
   * @param bool $locked
   *   Whether the cart is locked.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The cart.
   */
  protected function cart(int $id, array $items, string $total, ?StoreInterface $store = NULL, bool $locked = FALSE): OrderInterface&MockObject {
    $cart = $this->createMock(OrderInterface::class);
    $cart->method('id')->willReturn($id);
    $cart->method('getItems')->willReturn($items);
    $cart->method('hasItems')->willReturn($items !== []);
    $cart->method('getTotalPrice')->willReturn($items === [] ? NULL : new Price($total, 'EUR'));
    $cart->method('getStore')->willReturn($store ?? $this->store(1));
    $cart->method('isLocked')->willReturn($locked);
    $this->carts[] = $cart;
    return $cart;
  }

  /**
   * Asserts that a cart view matches the cart@1 presentation data.
   *
   * Items and total are validated against cart.v1.schema.json (product_item
   * of presentations/_common.v1, additionalProperties false) when the
   * schemas of the repository and justinrainbow/json-schema are available.
   *
   * @param array<string, mixed> $view
   *   The result of CartService::view().
   */
  protected function assertCartView(array $view): void {
    $this->assertSame(['items', 'total', 'items_count'], array_keys($view));
    $this->assertIsString($view['total']);
    $this->assertIsInt($view['items_count']);
    $allowed = [
      'product_id', 'variation_id', 'title', 'body', 'url', 'image_url', 'image_alt', 'price', 'price_amount',
      'currency', 'quantity', 'category', 'brand', 'store_name', 'availability', 'rating', 'badges', 'actions',
    ];
    foreach ($view['items'] as $item) {
      $this->assertSame([], array_diff(array_keys($item), $allowed));
      $this->assertIsString($item['product_id']);
      $this->assertIsString($item['title']);
    }
    $schema = dirname(__DIR__, 8) . '/packages/contracts/schemas/presentations/cart.v1.schema.json';
    if (!is_file($schema) || !class_exists(Validator::class)) {
      return;
    }
    $validator = new Validator();
    $data = json_decode((string) json_encode(['items' => $view['items'], 'total' => $view['total']], JSON_THROW_ON_ERROR));
    $validator->validate($data, (object) ['$ref' => 'file://' . $schema]);
    $errors = array_map(static fn (array $error): string => trim($error['property'] . ' ' . $error['message']), $validator->getErrors());
    $this->assertTrue($validator->isValid(), 'cart@1 violations: ' . implode('; ', $errors));
  }

}
