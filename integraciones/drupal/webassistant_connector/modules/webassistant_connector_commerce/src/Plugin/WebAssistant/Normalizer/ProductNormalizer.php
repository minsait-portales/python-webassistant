<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Plugin\WebAssistant\Normalizer;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\file\FileInterface;
use Drupal\webassistant_connector\Attribute\Normalizer;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Normalizer\NormalizationContext;
use Drupal\webassistant_connector\Normalizer\NormalizerInterface;
use Drupal\webassistant_connector\Normalizer\NormalizerPluginBase;
use Drupal\webassistant_connector\Sync\AclResolver;
use Drupal\webassistant_connector\Sync\UrlResolver;
use Drupal\webassistant_connector_commerce\Price\PriceHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Normalizes Drupal Commerce products (design section 12).
 *
 * One document per product translation. The field roles of the source are
 * applied by the content_entity normalizer (title, description and other
 * main_content/context fields, metadata/filter fields, image and date), with
 * the same access rules (amendments B1-B3). This normalizer then appends the
 * variations to the text (title, SKU, attributes and price of every
 * published variation viewable by every principal of the ACL) and completes
 * the metadata read by the catalog processors of the platform:
 * - product_id, variation_id (default variation), sku, url (canonical URL),
 *   price (formatted by Commerce), price_amount and currency: computed here,
 *   they replace mapped fields with the same keys;
 * - image_url/image_alt: the image role, else the first image field of the
 *   default variation;
 * - category, brand, store_name, availability, rating and badges: fields
 *   mapped with the metadata role and those keys (store_name defaults to
 *   the store label). availability is only sent when a field holds real
 *   stock data: Commerce core has no stock.
 *
 * Prices are resolved by the Commerce chain price resolver for the least
 * privileged principal of the ACL (the current user while documents are
 * built) in the default store of the product.
 */
#[Normalizer(
  id: 'commerce_product',
  label: new TranslatableMarkup('Commerce product'),
  description: new TranslatableMarkup('One product document per product translation, with its variations (SKU, attributes, price) and the catalog metadata used by the product skills.'),
  entity_types: ['commerce_product'],
)]
final class ProductNormalizer extends NormalizerPluginBase {

  /**
   * The normalizer that applies the field roles of the source.
   */
  public const BASE_NORMALIZER = 'content_entity';

  /**
   * Recommended document type (chunked as a whole by the platform).
   */
  public const DOCUMENT_TYPE = 'product';

  /**
   * Maximum number of variations listed in the text.
   */
  public const MAX_VARIATIONS = 50;

  /**
   * Metadata keys computed from the product (mapped values are replaced).
   */
  public const COMPUTED_KEYS = ['product_id', 'variation_id', 'sku', 'url', 'price', 'price_amount', 'currency'];

  /**
   * String metadata keys of product_item, with their maximum length.
   */
  public const STRING_KEYS = [
    'image_alt' => 300,
    'category' => 300,
    'brand' => 300,
    'store_name' => 300,
    'availability' => 60,
  ];

  /**
   * Metadata keys never dropped when the 60 keys limit is reached.
   */
  private const KEPT_KEYS = [
    'drupal',
    'image_url',
    'image_alt',
    'category',
    'brand',
    'store_name',
    'availability',
    'rating',
    'badges',
  ];

  /**
   * Constructs a ProductNormalizer.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $normalizerManager
   *   The normalizer plugin manager.
   * @param \Drupal\webassistant_connector\Sync\AclResolver $aclResolver
   *   The ACL resolver (entity and field access per principal).
   * @param \Drupal\webassistant_connector\Sync\UrlResolver $urlResolver
   *   The URL resolver (absolute image URLs).
   * @param \Drupal\webassistant_connector_commerce\Price\PriceHelper $priceHelper
   *   The price helper.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user: the least privileged principal while documents are
   *   built (price context).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    #[Autowire(service: 'plugin.manager.webassistant_connector.normalizer')]
    protected PluginManagerInterface $normalizerManager,
    protected AclResolver $aclResolver,
    protected UrlResolver $urlResolver,
    protected PriceHelper $priceHelper,
    protected AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context): array {
    $base = $this->normalizerManager->createInstance(self::BASE_NORMALIZER);
    if (!$base instanceof NormalizerInterface) {
      return [];
    }
    $documents = $base->normalize($translation, $source, $context);
    if ($documents === [] || !$translation instanceof ProductInterface) {
      return $documents;
    }
    if ($source->getDocumentType() !== self::DOCUMENT_TYPE) {
      $context->addWarning(sprintf('The document type is "%s": the platform chunks product documents as a whole only when the type is "%s".', $source->getDocumentType(), self::DOCUMENT_TYPE));
    }
    $main = array_shift($documents);
    $accounts = $context->principalAccounts !== [] ? $context->principalAccounts : $this->aclResolver->principalAccounts($context->acl);
    $customer = $this->currentUser->getAccount();
    $store = $this->store($translation);
    $variations = $this->variations($translation, $accounts, $context);
    $default = $this->defaultVariation($translation, $variations);

    $text = (string) $main->text;
    $section = $this->variationsSection($variations, $store, $customer, $accounts, $context);
    if ($section !== '') {
      $text .= "\n" . $section;
    }
    if (mb_strlen($text) > CanonicalDocument::MAX_TEXT_LENGTH) {
      $text = mb_substr($text, 0, CanonicalDocument::MAX_TEXT_LENGTH);
      $context->addWarning(sprintf('The text was cut to %d characters.', CanonicalDocument::MAX_TEXT_LENGTH));
    }
    $metadata = $this->metadata($main->metadata, $translation, $default, $store, $customer, $accounts, $context);
    $main = $main->withText($text, $main->contentFormat ?? CanonicalDocument::FORMAT_HTML)->withMetadata($metadata);
    return array_merge([$main], $documents);
  }

  /**
   * Returns the published variations every principal may view.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product translation.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (warnings).
   *
   * @return list<\Drupal\commerce_product\Entity\ProductVariationInterface>
   *   The variations, in the order of the product, in its language.
   */
  private function variations(ProductInterface $product, array $accounts, NormalizationContext $context): array {
    $variations = [];
    foreach ($product->getVariations() as $variation) {
      if (!$variation->isPublished()) {
        continue;
      }
      if (!$this->aclResolver->canAccessAll($variation, $accounts)) {
        $context->addWarning(sprintf('Variation %s dropped: not viewable by every principal of the ACL.', $variation->id()));
        continue;
      }
      $variations[] = $variation;
    }
    if ($variations === []) {
      $context->addWarning('The product has no published variation viewable by every principal of the ACL: variation_id, sku and price are not sent.');
    }
    return $variations;
  }

  /**
   * Returns the default variation among the listed ones.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param list<\Drupal\commerce_product\Entity\ProductVariationInterface> $variations
   *   The published variations every principal may view.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   Commerce's default variation when listed, else the first one.
   */
  private function defaultVariation(ProductInterface $product, array $variations): ?ProductVariationInterface {
    if ($variations === []) {
      return NULL;
    }
    $default = $product->getDefaultVariation();
    if ($default instanceof ProductVariationInterface) {
      foreach ($variations as $variation) {
        if ((string) $variation->id() === (string) $default->id()) {
          return $variation;
        }
      }
    }
    return $variations[0];
  }

  /**
   * Returns the store of the price context.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The default store when the product is sold there, else its first
   *   store, else NULL.
   */
  private function store(ProductInterface $product): ?StoreInterface {
    $first = NULL;
    foreach ($product->getStores() as $store) {
      if ($store->isDefault()) {
        return $store;
      }
      $first ??= $store;
    }
    return $first;
  }

  /**
   * Renders the variations section of the text.
   *
   * @param list<\Drupal\commerce_product\Entity\ProductVariationInterface> $variations
   *   The variations.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store of the price context.
   * @param \Drupal\Core\Session\AccountInterface $customer
   *   The customer of the price context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   *
   * @return string
   *   The HTML section, or an empty string.
   */
  private function variationsSection(array $variations, ?StoreInterface $store, AccountInterface $customer, array $accounts, NormalizationContext $context): string {
    $options = ['langcode' => $context->langcode];
    $lines = [];
    foreach (array_slice($variations, 0, self::MAX_VARIATIONS) as $variation) {
      $line = $this->variationLine($variation, $store, $customer, $accounts, $context);
      if ($line !== '') {
        $lines[] = '<li>' . $line . '</li>';
      }
    }
    if ($lines === []) {
      return '';
    }
    $hidden = count($variations) - self::MAX_VARIATIONS;
    if ($hidden > 0) {
      $lines[] = '<li>' . Html::escape((string) $this->formatPlural($hidden, '1 more option.', '@count more options.', [], $options)) . '</li>';
    }
    return '<section><h2>' . Html::escape((string) $this->t('Options', [], $options)) . '</h2><ul>' . implode('', $lines) . '</ul></section>';
  }

  /**
   * Renders one variation: title, SKU, attributes and price.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store of the price context.
   * @param \Drupal\Core\Session\AccountInterface $customer
   *   The customer of the price context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   *
   * @return string
   *   The HTML fragment, or an empty string. Every value is escaped exactly
   *   once: by the @placeholders of t() or by Html::escape().
   */
  private function variationLine(ProductVariationInterface $variation, ?StoreInterface $store, AccountInterface $customer, array $accounts, NormalizationContext $context): string {
    $options = ['langcode' => $context->langcode];
    $title = $this->viewable($variation, 'title', $accounts, $context) ? self::plain((string) $variation->label()) : '';
    $parts = [];
    $sku = $this->sku($variation, $accounts, $context);
    if ($sku !== NULL) {
      $parts[] = (string) $this->t('SKU: @sku', ['@sku' => $sku], $options);
    }
    foreach ($variation->getAttributeValues() as $field => $value) {
      if (!$this->viewable($variation, (string) $field, $accounts, $context)) {
        continue;
      }
      if (!$this->aclResolver->canAccessAll($value, $accounts)) {
        $context->addWarning(sprintf('Attribute %s of variation %s dropped: not viewable by every principal of the ACL.', $field, $variation->id()));
        continue;
      }
      $label = self::plain((string) $variation->get((string) $field)->getFieldDefinition()->getLabel());
      $name = self::plain((string) $value->label());
      if ($name !== '') {
        $parts[] = Html::escape($label !== '' ? $label . ': ' . $name : $name);
      }
    }
    $price = $this->price($variation, $store, $customer, $accounts, $context);
    if ($price !== NULL) {
      $parts[] = (string) $this->t('Price: @price', ['@price' => $this->priceHelper->format($price, $context->locale)], $options);
    }
    if ($title === '' && $parts === []) {
      return '';
    }
    $html = $title !== '' ? '<strong>' . Html::escape($title) . '</strong>' : '';
    if ($parts !== []) {
      $html .= ($html !== '' ? ' — ' : '') . implode(' · ', $parts);
    }
    return $html;
  }

  /**
   * Builds the product metadata.
   *
   * @param array<string, mixed> $metadata
   *   The metadata of the content_entity normalizer.
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product translation.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface|null $default
   *   The default variation, if any.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store of the price context.
   * @param \Drupal\Core\Session\AccountInterface $customer
   *   The customer of the price context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   *
   * @return array<string, mixed>
   *   The metadata (at most 60 keys).
   */
  private function metadata(array $metadata, ProductInterface $product, ?ProductVariationInterface $default, ?StoreInterface $store, AccountInterface $customer, array $accounts, NormalizationContext $context): array {
    $computed = ['product_id' => (string) $product->id()];
    if ($context->canonicalUrl !== NULL && strlen($context->canonicalUrl) <= 2048) {
      $computed['url'] = $context->canonicalUrl;
    }
    if ($default !== NULL) {
      $computed['variation_id'] = (string) $default->id();
      $sku = $this->sku($default, $accounts, $context);
      if ($sku !== NULL) {
        $computed['sku'] = $sku;
      }
      $price = $this->price($default, $store, $customer, $accounts, $context);
      if ($price !== NULL) {
        $computed['price'] = $this->priceHelper->format($price, $context->locale);
        $computed['price_amount'] = $this->priceHelper->amount($price);
        $computed['currency'] = $price->getCurrencyCode();
      }
    }
    foreach (self::COMPUTED_KEYS as $key) {
      if (array_key_exists($key, $metadata)) {
        $context->addWarning(sprintf('The metadata key "%s" is computed from the product: the mapped field is ignored.', $key));
        unset($metadata[$key]);
      }
    }
    $metadata = array_merge($metadata, $computed);

    if (!isset($metadata['image_url']) && $default !== NULL) {
      $metadata += $this->image($default, $accounts, $context);
    }
    if (!isset($metadata['store_name']) && $store !== NULL && $this->aclResolver->canAccessAll($store, $accounts, 'view label')) {
      $metadata['store_name'] = (string) $store->label();
    }
    foreach (self::STRING_KEYS as $key => $length) {
      if (array_key_exists($key, $metadata)) {
        $metadata[$key] = self::stringValue($metadata[$key], $length);
      }
    }
    if (array_key_exists('rating', $metadata)) {
      $rating = is_array($metadata['rating']) ? reset($metadata['rating']) : $metadata['rating'];
      $metadata['rating'] = is_numeric($rating) ? max(0.0, min(5.0, (float) $rating)) : NULL;
    }
    if (array_key_exists('badges', $metadata)) {
      $metadata['badges'] = self::badges($metadata['badges']);
    }
    $metadata = array_filter($metadata, static fn (mixed $value): bool => $value !== NULL);

    // The computed keys may push the map over the platform limit: drop the
    // last mapped keys that the catalog does not read.
    while (count($metadata) > CanonicalDocument::MAX_METADATA_KEYS) {
      $removable = array_diff(array_keys($metadata), self::KEPT_KEYS, self::COMPUTED_KEYS);
      if ($removable === []) {
        break;
      }
      $key = (string) end($removable);
      unset($metadata[$key]);
      $context->addWarning(sprintf('The metadata key "%s" was dropped: documents accept at most %d metadata keys.', $key, CanonicalDocument::MAX_METADATA_KEYS));
    }
    return $metadata;
  }

  /**
   * Returns the price of a variation when its price is viewable by all.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store of the price context.
   * @param \Drupal\Core\Session\AccountInterface $customer
   *   The customer of the price context.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (warnings).
   *
   * @return \Drupal\commerce_price\Price|null
   *   The resolved price, or NULL.
   */
  private function price(ProductVariationInterface $variation, ?StoreInterface $store, AccountInterface $customer, array $accounts, NormalizationContext $context): ?Price {
    if (!$this->viewable($variation, 'price', $accounts, $context)) {
      return NULL;
    }
    return $this->priceHelper->resolve($variation, $customer, $store);
  }

  /**
   * Returns the SKU of a variation when it is viewable by all.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (warnings).
   *
   * @return string|null
   *   The SKU (at most 128 characters), or NULL.
   */
  private function sku(ProductVariationInterface $variation, array $accounts, NormalizationContext $context): ?string {
    if (!$this->viewable($variation, 'sku', $accounts, $context)) {
      return NULL;
    }
    $sku = self::plain((string) $variation->getSku());
    return $sku === '' ? NULL : Unicode::truncate($sku, 128);
  }

  /**
   * Whether a non-empty field of a variation is viewable by every principal.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation.
   * @param string $field
   *   The field name.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (warnings).
   *
   * @return bool
   *   FALSE when the field is missing, empty or not viewable by all.
   */
  private function viewable(ProductVariationInterface $variation, string $field, array $accounts, NormalizationContext $context): bool {
    if (!$variation->hasField($field) || $variation->get($field)->isEmpty()) {
      return FALSE;
    }
    if (!$this->aclResolver->fieldViewableByAll($variation->get($field), $accounts)) {
      $context->addWarning(sprintf('%s of variation %s dropped: not viewable by every principal of the ACL.', $field, $variation->id()));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Returns the first public image of a variation (metadata fallback).
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The default variation.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (warnings).
   *
   * @return array<string, string>
   *   image_url (absolute) and image_alt, or an empty array.
   */
  private function image(ProductVariationInterface $variation, array $accounts, NormalizationContext $context): array {
    foreach ($variation->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'image' || !$this->viewable($variation, (string) $name, $accounts, $context)) {
        continue;
      }
      foreach ($variation->get((string) $name) as $item) {
        $file = $item->get('entity')->getValue();
        if (!$file instanceof FileInterface || !$file->isPermanent() || !str_starts_with((string) $file->getMimeType(), 'image/')) {
          continue;
        }
        if (!in_array(StreamWrapperManager::getScheme($file->getFileUri()), ['public', 'http', 'https'], TRUE)) {
          continue;
        }
        $image = ['image_url' => $this->urlResolver->fileUrl($file->getFileUri())];
        $alt = self::plain((string) $item->get('alt')->getValue());
        if ($alt !== '') {
          $image['image_alt'] = $alt;
        }
        return $image;
      }
    }
    return [];
  }

  /**
   * Converts a metadata value into a product_item string.
   *
   * @param mixed $value
   *   A scalar or a list of scalars (joined with ", ").
   * @param int $length
   *   The maximum length.
   *
   * @return string|null
   *   The string, or NULL when empty.
   */
  private static function stringValue(mixed $value, int $length): ?string {
    if (is_array($value)) {
      $value = implode(', ', array_filter(array_map(
        static fn (mixed $part): string => is_scalar($part) && !is_bool($part) ? self::plain((string) $part) : '',
        $value,
      ), static fn (string $part): bool => $part !== ''));
    }
    if (!is_scalar($value) || is_bool($value)) {
      return NULL;
    }
    $value = self::plain((string) $value);
    return $value === '' ? NULL : Unicode::truncate($value, $length, TRUE, TRUE);
  }

  /**
   * Converts a metadata value into product_item badges.
   *
   * @param mixed $value
   *   A scalar or a list of scalars.
   *
   * @return list<string>|null
   *   At most 5 badges of at most 40 characters, or NULL.
   */
  private static function badges(mixed $value): ?array {
    $badges = [];
    foreach (is_array($value) ? $value : [$value] as $badge) {
      if (!is_scalar($badge) || is_bool($badge)) {
        continue;
      }
      $badge = self::plain((string) $badge);
      if ($badge !== '') {
        $badges[] = Unicode::truncate($badge, 40, TRUE, TRUE);
      }
    }
    return $badges === [] ? NULL : array_slice($badges, 0, 5);
  }

  /**
   * Collapses whitespace of a plain string.
   */
  private static function plain(string $value): string {
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

}
