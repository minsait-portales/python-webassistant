<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Cart;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\Resolver\ChainOrderTypeResolverInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\ProductVariationStorageInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\file\FileInterface;
use Drupal\webassistant_connector\HostAction\HostActionException;
use Drupal\webassistant_connector\Sync\SourceIdGenerator;
use Drupal\webassistant_connector_commerce\Price\PriceHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The shopping cart of the host actions (design section 12).
 *
 * Single source of truth of the cart.* host actions: the parameters sent by
 * the widget only identify products; quantities are validated, prices and
 * totals always come from Drupal Commerce (carts are refreshed by Commerce
 * when saved or loaded) and only the carts of the account are touched.
 *
 * Products are identified by product_id (product ID, an own source_id such
 * as drupal:commerce_product:12:es, or a SKU) and the optional variation_id
 * (variation ID or SKU). Errors are HostActionException with user-safe,
 * translated messages; existence of products the account cannot view is
 * never disclosed.
 *
 * Service: webassistant_connector_commerce.cart.
 */
final class CartService {

  use StringTranslationTrait;

  /**
   * Maximum quantity of a variation in the cart (action schemas: 1..99).
   */
  public const MAX_QUANTITY = 99;

  /**
   * Maximum number of items of the cart view (cart@1 presentation).
   */
  public const MAX_ITEMS = 100;

  /**
   * Maximum quantity shown for an item (product_item.quantity).
   */
  public const MAX_ITEM_QUANTITY = 999;

  /**
   * Maximum length of an identifier (product_item.product_id).
   */
  public const MAX_ID_LENGTH = 128;

  /**
   * Prefix of the product_id of a line whose purchased entity is gone.
   */
  public const ORDER_ITEM_PREFIX = 'order_item:';

  /**
   * Stream wrapper schemes of images that may be shown in the widget.
   */
  public const IMAGE_SCHEMES = ['public', 'http', 'https'];

  /**
   * The checkout route (commerce_checkout, optional).
   */
  public const CHECKOUT_ROUTE = 'commerce_checkout.form';

  /**
   * The cart page route (commerce_cart).
   */
  public const CART_ROUTE = 'commerce_cart.page';

  /**
   * Constructs a CartService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   The cart provider.
   * @param \Drupal\commerce_cart\CartManagerInterface $cartManager
   *   The cart manager.
   * @param \Drupal\commerce_order\Resolver\ChainOrderTypeResolverInterface $orderTypeResolver
   *   The chain order type resolver.
   * @param \Drupal\commerce_store\CurrentStoreInterface $currentStore
   *   The current store.
   * @param \Drupal\webassistant_connector_commerce\Price\PriceHelper $priceHelper
   *   The price helper.
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator (product_id given as an own source_id).
   * @param \Drupal\Core\Access\AccessManagerInterface $accessManager
   *   The access manager (checkout route access).
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The URL generator.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'commerce_cart.cart_provider')]
    private readonly CartProviderInterface $cartProvider,
    #[Autowire(service: 'commerce_cart.cart_manager')]
    private readonly CartManagerInterface $cartManager,
    #[Autowire(service: 'commerce_order.chain_order_type_resolver')]
    private readonly ChainOrderTypeResolverInterface $orderTypeResolver,
    #[Autowire(service: 'commerce_store.current_store')]
    private readonly CurrentStoreInterface $currentStore,
    private readonly PriceHelper $priceHelper,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly AccessManagerInterface $accessManager,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Resolves the variation to add to the cart.
   *
   * Rules: product_id is a product ID or an own commerce_product source_id;
   * variation_id is a variation ID or a SKU and must belong to the product;
   * without variation_id the default variation is used (the one Commerce
   * picks, else the first available one); when nothing matches, product_id
   * is tried as a SKU. The product and the variation must be published and
   * viewable by the account.
   *
   * @param string $productId
   *   The product_id parameter.
   * @param string|null $variationId
   *   The variation_id parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The variation.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When the product is unknown, unpublished, not viewable or has no
   *   available variation.
   */
  public function resolveVariation(string $productId, ?string $variationId, AccountInterface $account): ProductVariationInterface {
    $product = $this->loadProduct($productId);
    $variation = NULL;
    if ($variationId !== NULL) {
      $variation = $this->loadVariation($variationId);
      if ($variation === NULL) {
        throw $this->fail($this->t('The product is not available.'));
      }
    }
    elseif ($product === NULL) {
      // Neither a product ID nor a source_id: the product_id may be a SKU.
      $variation = $this->loadVariationBySku($productId);
    }
    if ($product === NULL && $variation === NULL) {
      throw $this->fail($this->t('The product is not available.'));
    }

    if ($variation !== NULL) {
      if ($product !== NULL && (string) $variation->getProductId() !== (string) $product->id()) {
        // The variation of another product is never added instead.
        throw $this->fail($this->t('The product is not available.'));
      }
      $product = $product ?? $variation->getProduct();
    }
    if (!$product instanceof ProductInterface || !$this->isAvailable($product, $account)) {
      throw $this->fail($this->t('The product is not available.'));
    }
    if ($variation !== NULL) {
      if (!$this->isAvailable($variation, $account)) {
        throw $this->fail($this->t('The selected option of "@product" is not available.', ['@product' => $product->label()]));
      }
      return $variation;
    }

    $variation = $this->defaultVariation($product, $account);
    if ($variation === NULL) {
      throw $this->fail($this->t('"@product" cannot be purchased at the moment.', ['@product' => $product->label()]));
    }
    return $variation;
  }

  /**
   * Selects the store of a variation (Commerce SelectStoreTrait logic).
   *
   * @param \Drupal\commerce\PurchasableEntityInterface $entity
   *   The purchasable entity.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   Its only store, or the current store when it is one of several.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When the entity has no store, is not sold in the current store or the
   *   store is unpublished.
   */
  public function resolveStore(PurchasableEntityInterface $entity): StoreInterface {
    $stores = array_values($entity->getStores());
    if (count($stores) === 1) {
      $store = $stores[0];
    }
    elseif ($stores === []) {
      throw $this->fail($this->t('The product cannot be purchased from this site.'));
    }
    else {
      $store = self::store($this->currentStore->getStore());
      $ids = array_map(static fn (StoreInterface $candidate): string => (string) $candidate->id(), $stores);
      if ($store === NULL || !in_array((string) $store->id(), $ids, TRUE)) {
        throw $this->fail($this->t('The product cannot be purchased from the current store.'));
      }
    }
    if (!$store->isPublished()) {
      throw $this->fail($this->t('The product cannot be purchased from the current store.'));
    }
    return $store;
  }

  /**
   * Adds a variation to the cart of its store.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation (from resolveVariation()).
   * @param int $quantity
   *   The quantity, 1..MAX_QUANTITY.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The saved order item.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   On an invalid quantity, a locked cart or a store problem.
   */
  public function add(ProductVariationInterface $variation, int $quantity, AccountInterface $account): OrderItemInterface {
    $carts = [];
    return $this->addToCart($variation, $quantity, $account, $carts, TRUE);
  }

  /**
   * Adds several products, reporting the ones that could not be added.
   *
   * Every cart touched is saved once at the end (Commerce refreshes it).
   *
   * @param list<array{product_id: string, variation_id?: string|null, quantity?: int}> $items
   *   Validated items (at most MAX_ITEMS).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array{added: list<array{product_id: string, variation_id: string, title: string, quantity: int}>, failed: list<array{product_id: string, variation_id?: string, message: string}>}
   *   The added items and the failures with a user-safe message.
   */
  public function addMany(array $items, AccountInterface $account): array {
    $added = [];
    $failed = [];
    $carts = [];
    try {
      foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
        $productId = $item['product_id'];
        $variationId = $item['variation_id'] ?? NULL;
        $quantity = $item['quantity'] ?? 1;
        try {
          $variation = $this->resolveVariation($productId, $variationId, $account);
          $this->addToCart($variation, $quantity, $account, $carts, FALSE);
          $added[] = [
            'product_id' => (string) $variation->getProductId(),
            'variation_id' => (string) $variation->id(),
            'title' => self::truncate((string) $variation->label(), 300),
            'quantity' => $quantity,
          ];
        }
        catch (HostActionException $exception) {
          $failure = ['product_id' => self::truncate($productId, self::MAX_ID_LENGTH)];
          if ($variationId !== NULL) {
            $failure['variation_id'] = self::truncate($variationId, self::MAX_ID_LENGTH);
          }
          $failure['message'] = self::truncate($exception->getMessage(), 300);
          $failed[] = $failure;
        }
      }
    }
    finally {
      // Never leave saved order items unreferenced by their cart.
      foreach ($carts as $cart) {
        $cart->save();
      }
    }
    return ['added' => $added, 'failed' => $failed];
  }

  /**
   * Sets the quantity of a product of the cart (0 removes it).
   *
   * @param string $productId
   *   The product_id parameter (as returned by view()).
   * @param string|null $variationId
   *   The variation_id parameter.
   * @param int $quantity
   *   The new quantity, 0..MAX_QUANTITY.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return int
   *   The number of cart lines changed.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When the product is not in the cart, the request is ambiguous, the
   *   product is no longer available or the cart is locked.
   */
  public function update(string $productId, ?string $variationId, int $quantity, AccountInterface $account): int {
    if ($quantity < 0 || $quantity > self::MAX_QUANTITY) {
      throw $this->fail($this->t('The quantity must be between 0 and @max.', ['@max' => self::MAX_QUANTITY]));
    }
    if ($quantity === 0) {
      return $this->remove($productId, $variationId, $account);
    }
    $lines = $this->findLines($productId, $variationId, $account);
    $purchased = [];
    foreach ($lines as [, $orderItem]) {
      $purchased[self::lineKey($orderItem)] = $orderItem;
    }
    if (count($purchased) > 1) {
      $candidates = [];
      foreach (array_slice($purchased, 0, 5) as $orderItem) {
        $candidate = $this->lineIdentity($orderItem);
        $candidate['title'] = self::truncate((string) $orderItem->getTitle(), 300);
        $candidates[] = $candidate;
      }
      throw $this->fail($this->t('Several options of this product are in your cart. Please choose one.'), ['candidates' => $candidates]);
    }
    [$cart, $orderItem] = $lines[0];
    $entity = $orderItem->getPurchasedEntity();
    if ($entity !== NULL && !$this->isAvailable($entity, $account)) {
      throw $this->fail($this->t('This product is no longer available. You can remove it from your cart.'));
    }
    foreach ($lines as [$lineCart]) {
      $this->assertUnlocked($lineCart);
    }
    $orderItem->setQuantity((string) $quantity);
    $this->cartManager->updateOrderItem($cart, $orderItem);
    // Duplicated lines of the same product are merged into the first one.
    foreach (array_slice($lines, 1) as [$lineCart, $lineItem]) {
      $this->cartManager->removeOrderItem($lineCart, $lineItem);
    }
    return count($lines);
  }

  /**
   * Removes a product from the cart.
   *
   * @param string $productId
   *   The product_id parameter (as returned by view()).
   * @param string|null $variationId
   *   The variation_id parameter; without it every line of the product is
   *   removed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return int
   *   The number of cart lines removed.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When the product is not in the cart or the cart is locked.
   */
  public function remove(string $productId, ?string $variationId, AccountInterface $account): int {
    $lines = $this->findLines($productId, $variationId, $account);
    foreach ($lines as [$cart]) {
      $this->assertUnlocked($cart);
    }
    foreach ($lines as [$cart, $orderItem]) {
      $this->cartManager->removeOrderItem($cart, $orderItem);
    }
    return count($lines);
  }

  /**
   * Builds the cart view: product_item list, total and line count.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account (its carts only).
   *
   * @return array{items: list<array<string, mixed>>, total: string, items_count: int}
   *   Items in the presentations/_common.v1 product_item shape (at most
   *   MAX_ITEMS), the formatted total of every cart ('' when empty) and the
   *   number of cart lines.
   */
  public function view(AccountInterface $account): array {
    $items = [];
    $totals = [];
    $count = 0;
    foreach ($this->nonEmptyCarts($account) as $cart) {
      foreach ($cart->getItems() as $orderItem) {
        $count++;
        if (count($items) < self::MAX_ITEMS) {
          $items[] = $this->item($orderItem, $cart, $account);
        }
      }
      $total = $cart->getTotalPrice();
      if ($total instanceof Price) {
        $totals[] = $total;
      }
    }
    return [
      'items' => $items,
      'total' => $this->priceHelper->formatTotal($totals),
      'items_count' => $count,
    ];
  }

  /**
   * Returns the URL to complete the purchase.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string
   *   The checkout form of the cart (commerce_checkout.form) when there is a
   *   single non-empty cart and the account may check it out; otherwise the
   *   cart page. Root-relative.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When the cart is empty.
   */
  public function checkoutUrl(AccountInterface $account): string {
    $carts = $this->nonEmptyCarts($account);
    if ($carts === []) {
      throw $this->fail($this->t('Your cart is empty.'));
    }
    if (count($carts) === 1) {
      $parameters = ['commerce_order' => $carts[0]->id()];
      // FALSE as well when commerce_checkout is not installed.
      if ($this->accessManager->checkNamedRoute(self::CHECKOUT_ROUTE, $parameters, $account)) {
        return $this->urlGenerator->generateFromRoute(self::CHECKOUT_ROUTE, $parameters, [], TRUE)->getGeneratedUrl();
      }
    }
    return $this->cartUrl();
  }

  /**
   * Returns the root-relative URL of the cart page.
   *
   * URLs are generated collecting their bubbleable metadata: the host action
   * controller answers JSON, so nothing may be rendered early.
   */
  public function cartUrl(): string {
    return $this->urlGenerator->generateFromRoute(self::CART_ROUTE, [], [], TRUE)->getGeneratedUrl();
  }

  /**
   * Adds a variation to the cart of its store (see add()).
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param int $quantity
   *   The quantity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param array<string, \Drupal\commerce_order\Entity\OrderInterface> $carts
   *   Carts used so far, keyed by order type and store (reused, by
   *   reference).
   * @param bool $saveCart
   *   Whether the cart is saved now.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The saved order item.
   */
  private function addToCart(ProductVariationInterface $variation, int $quantity, AccountInterface $account, array &$carts, bool $saveCart): OrderItemInterface {
    if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
      throw $this->fail($this->t('The quantity must be between 1 and @max.', ['@max' => self::MAX_QUANTITY]));
    }
    $store = $this->resolveStore($variation);
    $orderItem = $this->cartManager->createOrderItem($variation, (string) $quantity);
    $orderType = (string) $this->orderTypeResolver->resolve($orderItem);
    $key = $orderType . ':' . $store->id();
    if (!isset($carts[$key])) {
      $carts[$key] = $this->cartProvider->getCart($orderType, $store, $account)
        ?? $this->cartProvider->createCart($orderType, $store, $account);
    }
    $cart = $carts[$key];
    $this->assertUnlocked($cart);
    $existing = 0.0;
    foreach ($cart->getItems() as $line) {
      if ((string) $line->getPurchasedEntityId() === (string) $variation->id()) {
        $existing += (float) $line->getQuantity();
      }
    }
    if ($existing + $quantity > self::MAX_QUANTITY) {
      throw $this->fail($this->t('Your cart can hold at most @max units of "@product".', [
        '@max' => self::MAX_QUANTITY,
        '@product' => $variation->label(),
      ]));
    }
    return $this->cartManager->addOrderItem($cart, $orderItem, TRUE, $saveCart);
  }

  /**
   * Finds the cart lines of the account matching the parameters.
   *
   * Matching (only lines of the account's carts, so nothing else leaks):
   * with variation_id, lines of that variation (ID or SKU); otherwise the
   * lines of the product (product ID or own source_id), else of the
   * variation whose SKU is product_id, else the "{entity_type}:{id}" or
   * "order_item:{id}" identifiers returned by view() for other lines.
   *
   * @param string $productId
   *   The product_id parameter.
   * @param string|null $variationId
   *   The variation_id parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return non-empty-list<array{0: \Drupal\commerce_order\Entity\OrderInterface, 1: \Drupal\commerce_order\Entity\OrderItemInterface}>
   *   The matching lines with their cart.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When no line matches.
   */
  private function findLines(string $productId, ?string $variationId, AccountInterface $account): array {
    $lines = [];
    foreach ($this->nonEmptyCarts($account) as $cart) {
      foreach ($cart->getItems() as $orderItem) {
        $lines[] = [$cart, $orderItem];
      }
    }
    $matchers = [];
    if ($variationId !== NULL) {
      $ids = [];
      if (self::isId($variationId)) {
        $ids[] = $variationId;
      }
      $bySku = $this->loadVariationBySku($variationId);
      if ($bySku !== NULL) {
        $ids[] = (string) $bySku->id();
      }
      $matchers[] = static fn (OrderItemInterface $item): bool => self::purchasedType($item) === 'commerce_product_variation'
        && in_array((string) $item->getPurchasedEntityId(), $ids, TRUE);
    }
    else {
      $product = $this->productIdFromParam($productId);
      if ($product !== NULL) {
        $matchers[] = static function (OrderItemInterface $item) use ($product): bool {
          $entity = $item->getPurchasedEntity();
          return $entity instanceof ProductVariationInterface && (string) $entity->getProductId() === $product;
        };
      }
      $bySku = $this->loadVariationBySku($productId);
      if ($bySku !== NULL) {
        $skuVariation = (string) $bySku->id();
        $matchers[] = static fn (OrderItemInterface $item): bool => self::purchasedType($item) === 'commerce_product_variation' && (string) $item->getPurchasedEntityId() === $skuVariation;
      }
      $matchers[] = fn (OrderItemInterface $item): bool => $this->lineIdentity($item)['product_id'] === $productId;
    }
    // The first matcher that finds something wins (product before SKU).
    foreach ($matchers as $matcher) {
      $matches = array_values(array_filter($lines, static fn (array $line): bool => $matcher($line[1])));
      if ($matches !== []) {
        return $matches;
      }
    }
    throw $this->fail($this->t('This product is not in your cart.'));
  }

  /**
   * Builds the product_item of a cart line.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $orderItem
   *   The order item.
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   Its cart.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array<string, mixed>
   *   Only keys of presentations/_common.v1 product_item: product_id,
   *   variation_id, title, url, image_url, image_alt, price, price_amount,
   *   currency, quantity and store_name.
   */
  private function item(OrderItemInterface $orderItem, OrderInterface $cart, AccountInterface $account): array {
    $entity = $orderItem->getPurchasedEntity();
    $item = $this->lineIdentity($orderItem);
    $title = trim((string) $orderItem->getTitle());
    if ($title === '' && $entity !== NULL) {
      $title = trim((string) $entity->label());
    }
    $item['title'] = self::truncate($title !== '' ? $title : (string) $this->t('Product'), 300);

    $product = $entity instanceof ProductVariationInterface ? $entity->getProduct() : NULL;
    if ($product instanceof ProductInterface && $product->access('view', $account)) {
      $url = $product->toUrl()->toString(TRUE)->getGeneratedUrl();
      if (preg_match('#^(https?://|/)\S*$#', $url) && strlen($url) <= 2048) {
        $item['url'] = $url;
      }
    }
    $image = NULL;
    foreach ([$entity, $product] as $candidate) {
      if ($image === NULL && $candidate instanceof FieldableEntityInterface) {
        $image = $this->image($candidate, $account);
      }
    }
    if ($image !== NULL) {
      $item += $image;
    }
    $unitPrice = $orderItem->getUnitPrice();
    if ($unitPrice instanceof Price) {
      $item['price'] = $this->priceHelper->format($unitPrice);
      $item['price_amount'] = $this->priceHelper->amount($unitPrice);
      $item['currency'] = $unitPrice->getCurrencyCode();
    }
    $item['quantity'] = max(1, min(self::MAX_ITEM_QUANTITY, (int) round((float) $orderItem->getQuantity())));
    $store = $cart->getStore();
    if ($store instanceof StoreInterface && $store->access('view label', $account)) {
      $item['store_name'] = self::truncate((string) $store->label(), 300);
    }
    return $item;
  }

  /**
   * Returns the identifiers of a cart line (as accepted by findLines()).
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $orderItem
   *   The order item.
   *
   * @return array{product_id: string, variation_id?: string}
   *   Product and variation IDs for product variations; otherwise
   *   "{entity_type}:{id}", or "order_item:{id}" when the purchased entity
   *   no longer exists.
   */
  private function lineIdentity(OrderItemInterface $orderItem): array {
    $entity = $orderItem->getPurchasedEntity();
    if ($entity instanceof ProductVariationInterface && $entity->getProductId() !== NULL) {
      return [
        'product_id' => (string) $entity->getProductId(),
        'variation_id' => (string) $entity->id(),
      ];
    }
    if ($entity !== NULL) {
      return ['product_id' => self::truncate($entity->getEntityTypeId() . ':' . $entity->id(), self::MAX_ID_LENGTH)];
    }
    return ['product_id' => self::ORDER_ITEM_PREFIX . $orderItem->id()];
  }

  /**
   * Returns the first public image of an entity the account may view.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   A variation or a product.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array{image_url: string, image_alt?: string}|null
   *   The root-relative image URL and its alternative text, or NULL.
   */
  private function image(FieldableEntityInterface $entity, AccountInterface $account): ?array {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'image') {
        continue;
      }
      $items = $entity->get($name);
      if ($items->isEmpty() || !$items->access('view', $account)) {
        continue;
      }
      $item = $items->first();
      $file = $item instanceof FieldItemInterface ? $item->get('entity')->getValue() : NULL;
      if (!$item instanceof FieldItemInterface || !$file instanceof FileInterface) {
        continue;
      }
      if (!in_array(StreamWrapperManager::getScheme($file->getFileUri()), self::IMAGE_SCHEMES, TRUE)) {
        continue;
      }
      $url = $this->fileUrlGenerator->generateString($file->getFileUri());
      if (!preg_match('#^(https?://|/)\S*$#', $url) || strlen($url) > 2048) {
        continue;
      }
      $image = ['image_url' => $url];
      $alt = self::truncate(trim((string) $item->get('alt')->getValue()), 300);
      if ($alt !== '') {
        $image['image_alt'] = $alt;
      }
      return $image;
    }
    return NULL;
  }

  /**
   * Returns the carts of the account that have items.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return list<\Drupal\commerce_order\Entity\OrderInterface>
   *   The carts.
   */
  private function nonEmptyCarts(AccountInterface $account): array {
    $carts = [];
    foreach ($this->cartProvider->getCarts($account) as $cart) {
      if ($cart->hasItems()) {
        $carts[] = $cart;
      }
    }
    return $carts;
  }

  /**
   * Returns the default variation of a product available to the account.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   Commerce's default variation when available to the account, else the
   *   first available variation, else NULL.
   */
  private function defaultVariation(ProductInterface $product, AccountInterface $account): ?ProductVariationInterface {
    $default = $product->getDefaultVariation();
    if ($default instanceof ProductVariationInterface && $this->isAvailable($default, $account)) {
      return $default;
    }
    foreach ($product->getVariations() as $variation) {
      if ($this->isAvailable($variation, $account)) {
        return $variation;
      }
    }
    return NULL;
  }

  /**
   * Whether an entity is published and viewable by the account.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A product, variation or other purchasable entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return bool
   *   TRUE if available.
   */
  private function isAvailable(EntityInterface $entity, AccountInterface $account): bool {
    if ($entity instanceof EntityPublishedInterface && !$entity->isPublished()) {
      return FALSE;
    }
    return $entity->access('view', $account) === TRUE;
  }

  /**
   * Fails when a cart is locked (e.g. a payment is in progress).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  private function assertUnlocked(OrderInterface $cart): void {
    if ($cart->isLocked()) {
      throw $this->fail($this->t('Your cart is being checked out and cannot be changed now.'));
    }
  }

  /**
   * Loads a product from a product ID or an own source_id.
   *
   * @param string $productId
   *   The parameter.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   The product, or NULL.
   */
  private function loadProduct(string $productId): ?ProductInterface {
    $id = $this->productIdFromParam($productId);
    if ($id === NULL) {
      return NULL;
    }
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($id);
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * Extracts a product ID from a product ID or an own source_id.
   *
   * @param string $productId
   *   The parameter.
   *
   * @return string|null
   *   The product ID, or NULL.
   */
  private function productIdFromParam(string $productId): ?string {
    if (self::isId($productId)) {
      return $productId;
    }
    $parsed = $this->sourceIdGenerator->parse($productId);
    if ($parsed !== NULL && $parsed['entity_type'] === 'commerce_product' && $parsed['attachment'] === NULL && self::isId($parsed['entity_id'])) {
      return $parsed['entity_id'];
    }
    return NULL;
  }

  /**
   * Loads a variation by ID, else by SKU.
   *
   * @param string $variationId
   *   The variation_id parameter.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The variation, or NULL.
   */
  private function loadVariation(string $variationId): ?ProductVariationInterface {
    if (self::isId($variationId)) {
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variationId);
      if ($variation instanceof ProductVariationInterface) {
        return $variation;
      }
    }
    return $this->loadVariationBySku($variationId);
  }

  /**
   * Loads a variation by SKU.
   *
   * @param string $sku
   *   The SKU.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The variation, or NULL.
   */
  private function loadVariationBySku(string $sku): ?ProductVariationInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    if (!$storage instanceof ProductVariationStorageInterface) {
      return NULL;
    }
    $variation = $storage->loadBySku($sku);
    return $variation instanceof ProductVariationInterface ? $variation : NULL;
  }

  /**
   * Returns a key identifying the purchased entity of a line.
   */
  private static function lineKey(OrderItemInterface $orderItem): string {
    $type = self::purchasedType($orderItem);
    return $type !== NULL ? $type . ':' . $orderItem->getPurchasedEntityId() : self::ORDER_ITEM_PREFIX . $orderItem->id();
  }

  /**
   * Narrows the current store, which is NULL when the site has no store.
   *
   * CurrentStoreInterface::getStore() documents a store, but the chain store
   * resolver returns NULL when no store can be resolved.
   *
   * @param mixed $store
   *   The value returned by the current store service.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store, or NULL.
   */
  private static function store(mixed $store): ?StoreInterface {
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Returns the entity type of the purchased entity of a line, if any.
   */
  private static function purchasedType(OrderItemInterface $orderItem): ?string {
    return $orderItem->getPurchasedEntity()?->getEntityTypeId();
  }

  /**
   * Whether a value is a positive integer ID.
   */
  private static function isId(string $value): bool {
    return (bool) preg_match('/^[1-9]\d{0,18}$/', $value);
  }

  /**
   * Truncates a string on a character boundary.
   */
  private static function truncate(string $value, int $length): string {
    return Unicode::truncate($value, $length);
  }

  /**
   * Builds a user-facing failure.
   *
   * @param string|\Stringable $message
   *   A translated, user-safe message.
   * @param array<string, mixed> $data
   *   Extra data for the failed result.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionException
   *   The exception to throw.
   */
  private function fail(string|\Stringable $message, array $data = []): HostActionException {
    return new HostActionException($message, $data);
  }

}
