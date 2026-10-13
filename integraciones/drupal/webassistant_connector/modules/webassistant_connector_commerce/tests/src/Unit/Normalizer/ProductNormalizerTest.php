<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_commerce\Unit\Normalizer;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Tests\webassistant_connector\Traits\CanonicalDocumentSchemaTrait;
use Drupal\Tests\webassistant_connector_commerce\Unit\CommerceUnitTestBase;
use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductAttributeValueInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\file\FileInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Identity\IdentityMapper;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Normalizer\NormalizationContext;
use Drupal\webassistant_connector\Normalizer\NormalizerInterface;
use Drupal\webassistant_connector\Sync\AclResolver;
use Drupal\webassistant_connector\Sync\UrlResolver;
use Drupal\webassistant_connector_commerce\Plugin\WebAssistant\Normalizer\ProductNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the product normalizer (design section 12).
 *
 * @group webassistant_connector
 */
#[CoversClass(ProductNormalizer::class)]
#[Group('webassistant_connector')]
final class ProductNormalizerTest extends CommerceUnitTestBase {

  use CanonicalDocumentSchemaTrait;

  /**
   * Metadata keys read by the catalog processors of the platform.
   *
   * See apps/api/src/webassistant/skills/processors.py (_PRODUCT_META_KEYS
   * plus product_id and variation_id).
   */
  private const CATALOG_KEYS = [
    'product_id', 'variation_id', 'url', 'image_url', 'image_alt', 'price', 'price_amount', 'currency',
    'category', 'brand', 'store_name', 'availability', 'rating', 'badges',
  ];

  /**
   * Metadata of the base (content_entity) document.
   *
   * @var array<string, mixed>
   */
  private array $baseMetadata = [];

  /**
   * The source.
   */
  private SourceInterface&MockObject $source;

  /**
   * Builds the normalizer around a mocked content_entity normalizer.
   *
   * @param list<\Drupal\webassistant_connector\Normalizer\CanonicalDocument>|null $base
   *   The documents of the base normalizer (NULL: one product document).
   *
   * @return \Drupal\webassistant_connector_commerce\Plugin\WebAssistant\Normalizer\ProductNormalizer
   *   The normalizer.
   */
  private function normalizer(?array $base = NULL): ProductNormalizer {
    $base ??= [
      new CanonicalDocument(
        sourceId: self::PREFIX . ':commerce_product:10:es',
        knowledgeBase: 'catalogo',
        type: 'product',
        title: 'Shirt',
        version: 'm1',
        locale: 'es',
        text: "<h1>Shirt</h1>\n<p>Cotton shirt.</p>",
        contentFormat: CanonicalDocument::FORMAT_HTML,
        canonicalUrl: 'https://shop.example/product/10',
        acl: ['public'],
        metadata: $this->baseMetadata,
      ),
    ];
    $baseNormalizer = $this->createMock(NormalizerInterface::class);
    $baseNormalizer->method('normalize')->willReturn($base);
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('createInstance')->with(ProductNormalizer::BASE_NORMALIZER)->willReturn($baseNormalizer);

    $handler = $this->createMock(EntityAccessControlHandlerInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getAccessControlHandler')->willReturn($handler);
    $aclResolver = new AclResolver(
      $entityTypeManager,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      (new \ReflectionClass(IdentityMapper::class))->newInstanceWithoutConstructor(),
      $this->createMock(LoggerInterface::class),
    );
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->method('generateString')->willReturnCallback(static fn (string $uri): string => '/sites/default/files/' . substr($uri, 9));
    $urlResolver = new UrlResolver(
      $this->getConfigFactoryStub(['webassistant_connector.settings' => ['sync' => ['public_base_url' => 'https://shop.example']]]),
      new RequestStack(),
      $fileUrlGenerator,
    );
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('getAccount')->willReturn(new AnonymousUserSession());

    $this->source = $this->createMock(SourceInterface::class);
    $this->source->method('getDocumentType')->willReturn('product');

    $definition = ['id' => 'commerce_product', 'entity_types' => ['commerce_product']];
    $normalizer = new ProductNormalizer([], 'commerce_product', $definition, $manager, $aclResolver, $urlResolver, $this->priceHelper(), $currentUser);
    $normalizer->setStringTranslation($this->getStringTranslationStub());
    return $normalizer;
  }

  /**
   * Builds the normalization context of a public product.
   */
  private static function context(): NormalizationContext {
    return new NormalizationContext(
      acl: ['public'],
      locale: 'es',
      langcode: 'es',
      baseSourceId: self::PREFIX . ':commerce_product:10:es',
      version: 'm1',
      canonicalUrl: 'https://shop.example/product/10',
      drupalMetadata: ['entity_type' => 'commerce_product', 'id' => '10'],
      principalAccounts: [new AnonymousUserSession()],
    );
  }

  /**
   * Creates a field item list.
   *
   * @param bool $viewable
   *   Whether every principal may view it.
   * @param string $label
   *   The field label.
   * @param list<\Drupal\Core\Field\FieldItemInterface> $items
   *   The items.
   *
   * @return \Drupal\Core\Field\FieldItemList&\PHPUnit\Framework\MockObject\MockObject
   *   The list.
   */
  private function field(bool $viewable = TRUE, string $label = '', array $items = []): FieldItemList&MockObject {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getLabel')->willReturn($label);
    $list = $this->createMock(FieldItemList::class);
    $list->method('isEmpty')->willReturn(FALSE);
    $list->method('access')->willReturn($viewable);
    $list->method('getFieldDefinition')->willReturn($definition);
    $list->method('getName')->willReturn($label);
    $list->method('getIterator')->willReturn(new \ArrayIterator($items));
    return $list;
  }

  /**
   * Creates a variation with title, SKU, price, a color and an image.
   *
   * @param int $id
   *   The ID.
   * @param string $sku
   *   The SKU.
   * @param string $color
   *   The color attribute value.
   * @param array<string, bool> $fieldAccess
   *   View access per field (default TRUE).
   * @param bool $published
   *   Whether it is published.
   * @param bool $viewable
   *   Whether every principal may view it.
   * @param bool $image
   *   Whether it has a public image.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The variation.
   */
  private function productVariation(int $id, string $sku, string $color, array $fieldAccess = [], bool $published = TRUE, bool $viewable = TRUE, bool $image = FALSE): ProductVariationInterface&MockObject {
    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('id')->willReturn($id);
    $variation->method('label')->willReturn('Shirt - ' . $color);
    $variation->method('getEntityTypeId')->willReturn('commerce_product_variation');
    $variation->method('getSku')->willReturn($sku);
    $variation->method('isPublished')->willReturn($published);
    $variation->method('access')->willReturn($viewable);
    $variation->method('getPrice')->willReturn(new Price('19.90', 'EUR'));
    $value = $this->createMock(ProductAttributeValueInterface::class);
    $value->method('label')->willReturn($color);
    $value->method('access')->willReturn(TRUE);
    $variation->method('getAttributeValues')->willReturn(['attribute_color' => $value]);

    $fields = [
      'title' => $this->field($fieldAccess['title'] ?? TRUE, 'Title'),
      'sku' => $this->field($fieldAccess['sku'] ?? TRUE, 'SKU'),
      'price' => $this->field($fieldAccess['price'] ?? TRUE, 'Price'),
      'attribute_color' => $this->field($fieldAccess['attribute_color'] ?? TRUE, 'Color'),
    ];
    $definitions = [];
    if ($image) {
      $file = $this->createMock(FileInterface::class);
      $file->method('isPermanent')->willReturn(TRUE);
      $file->method('getMimeType')->willReturn('image/png');
      $file->method('getFileUri')->willReturn('public://shirt-' . $id . '.png');
      $entity = $this->createMock(TypedDataInterface::class);
      $entity->method('getValue')->willReturn($file);
      $alt = $this->createMock(TypedDataInterface::class);
      $alt->method('getValue')->willReturn('A ' . strtolower($color) . ' shirt');
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('get')->willReturnMap([['entity', $entity], ['alt', $alt]]);
      $fields['field_image'] = $this->field(TRUE, 'Image', [$item]);
      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getType')->willReturn('image');
      $definitions['field_image'] = $definition;
    }
    $variation->method('getFieldDefinitions')->willReturn($definitions);
    $variation->method('hasField')->willReturnCallback(static fn (string $name): bool => isset($fields[$name]));
    $variation->method('get')->willReturnCallback(static fn (string $name): FieldItemList => $fields[$name]);
    return $variation;
  }

  /**
   * Creates the product with its variations and store.
   *
   * @param list<\Drupal\commerce_product\Entity\ProductVariationInterface> $variations
   *   The variations.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface|null $default
   *   Commerce's default variation.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The product.
   */
  private function shirt(array $variations, ?ProductVariationInterface $default = NULL): ProductInterface&MockObject {
    $product = $this->createMock(ProductInterface::class);
    $product->method('id')->willReturn(10);
    $product->method('getEntityTypeId')->willReturn('commerce_product');
    $product->method('getVariations')->willReturn($variations);
    $product->method('getDefaultVariation')->willReturn($default ?? ($variations[0] ?? NULL));
    $product->method('getStores')->willReturn([$this->store(2, 'Outlet'), $this->store(1, 'Main store')]);
    return $product;
  }

  /**
   * The product document has the variations and the catalog metadata.
   */
  public function testNormalize(): void {
    $this->baseMetadata = [
      'drupal' => ['entity_type' => 'commerce_product', 'id' => '10'],
      'category' => ['Shirts', 'Men'],
      'brand' => 'ACME',
      'price' => 'mapped price field',
      'rating' => '4.5',
      'badges' => ['New', str_repeat('x', 50), '', 'Eco', 'Sale', 'Top', 'Extra'],
      'availability' => 'In stock',
      'material' => 'Cotton',
    ];
    $red = $this->productVariation(21, 'SHIRT-RED', 'Red', [], TRUE, TRUE, TRUE);
    $blue = $this->productVariation(22, 'SHIRT-BLUE', 'Blue', ['attribute_color' => FALSE]);
    $draft = $this->productVariation(23, 'SHIRT-DRAFT', 'Green', [], FALSE);
    $hidden = $this->productVariation(24, 'SHIRT-WHOLESALE', 'Black', [], TRUE, FALSE);
    $contexts = [];
    $this->priceResolver->method('resolve')->willReturnCallback(static function (PurchasableEntityInterface $entity, string $quantity, Context $context) use (&$contexts): ?Price {
      $contexts[] = [$entity->id(), $quantity, $context->getStore()->id(), $context->getCustomer()->id()];
      return $entity->id() === 21 ? new Price('17.90', 'EUR') : NULL;
    });
    $context = self::context();

    $documents = $this->normalizer()->normalize($this->shirt([$red, $blue, $draft, $hidden], $red), $this->source, $context);

    $this->assertCount(1, $documents);
    $document = $documents[0];
    $metadata = $document->metadata;
    ksort($metadata);
    $this->assertSame([
      'availability' => 'In stock',
      'badges' => ['New', str_repeat('x', 39) . '…', 'Eco', 'Sale', 'Top'],
      'brand' => 'ACME',
      'category' => 'Shirts, Men',
      'currency' => 'EUR',
      'drupal' => ['entity_type' => 'commerce_product', 'id' => '10'],
      'image_alt' => 'A red shirt',
      'image_url' => 'https://shop.example/sites/default/files/shirt-21.png',
      'material' => 'Cotton',
      'price' => '17,90 EUR [es]',
      'price_amount' => 17.9,
      'product_id' => '10',
      'rating' => 4.5,
      'sku' => 'SHIRT-RED',
      'store_name' => 'Main store',
      'url' => 'https://shop.example/product/10',
      'variation_id' => '21',
    ], $metadata);
    $this->assertSame([], array_diff(self::CATALOG_KEYS, array_keys($metadata)), 'Every catalog key is present.');

    // Prices are resolved in the default store for the render account.
    $this->assertSame([[21, '1', 1, 0], [22, '1', 1, 0], [21, '1', 1, 0]], $contexts);
    $this->assertStringStartsWith("<h1>Shirt</h1>\n<p>Cotton shirt.</p>\n<section><h2>Options</h2><ul>", $document->text);
    $this->assertStringContainsString('<li><strong>Shirt - Red</strong> — SKU: SHIRT-RED · Color: Red · Price: 17,90 EUR [es]</li>', $document->text);
    // The blue color is not viewable by every principal; its price comes
    // from the price field (no resolver result).
    $this->assertStringContainsString('<li><strong>Shirt - Blue</strong> — SKU: SHIRT-BLUE · Price: 19,90 EUR [es]</li>', $document->text);
    $this->assertStringNotContainsString('SHIRT-DRAFT', $document->text);
    $this->assertStringNotContainsString('SHIRT-WHOLESALE', $document->text);
    $this->assertContains('Variation 24 dropped: not viewable by every principal of the ACL.', $context->getWarnings());
    $this->assertContains('attribute_color of variation 22 dropped: not viewable by every principal of the ACL.', $context->getWarnings());
    $this->assertContains('The metadata key "price" is computed from the product: the mapped field is ignored.', $context->getWarnings());
    $this->assertCanonicalDocument($document->withContentHash(hash('sha256', (string) $document->text))->toArray());
  }

  /**
   * Fields not viewable by every principal are never sent.
   */
  public function testFieldAccess(): void {
    $this->baseMetadata = ['drupal' => [], 'image_url' => 'https://shop.example/sites/default/files/product.png'];
    $red = $this->productVariation(21, 'SHIRT-RED', 'Red', ['price' => FALSE, 'sku' => FALSE, 'title' => FALSE], TRUE, TRUE, TRUE);
    $this->priceResolver->expects($this->never())->method('resolve');
    $context = self::context();

    $document = $this->normalizer()->normalize($this->shirt([$red]), $this->source, $context)[0];

    $this->assertSame('21', $document->metadata['variation_id']);
    foreach (['sku', 'price', 'price_amount', 'currency'] as $key) {
      $this->assertArrayNotHasKey($key, $document->metadata);
    }
    // The image role of the product wins over the variation image.
    $this->assertSame('https://shop.example/sites/default/files/product.png', $document->metadata['image_url']);
    $this->assertArrayNotHasKey('image_alt', $document->metadata);
    $this->assertStringContainsString('<li>Color: Red</li>', $document->text);
    $this->assertStringNotContainsString('SHIRT-RED', $document->text);
    $this->assertContains('price of variation 21 dropped: not viewable by every principal of the ACL.', $context->getWarnings());
  }

  /**
   * Variation values are escaped exactly once.
   */
  public function testEscaping(): void {
    $this->baseMetadata = ['drupal' => []];
    $variation = $this->productVariation(21, 'A&B<1>', '<b>Red</b> & "Blue"');

    $document = $this->normalizer()->normalize($this->shirt([$variation]), $this->source, self::context())[0];

    $this->assertStringContainsString('<li><strong>Shirt - &lt;b&gt;Red&lt;/b&gt; &amp; &quot;Blue&quot;</strong> — SKU: A&amp;B&lt;1&gt; · Color: &lt;b&gt;Red&lt;/b&gt; &amp; &quot;Blue&quot; · Price: 19,90 EUR [es]</li>', (string) $document->text);
    // Metadata is plain text (the widget renders it with textContent).
    $this->assertSame('A&B<1>', $document->metadata['sku']);
  }

  /**
   * Without available variations only product-level keys are sent.
   */
  public function testWithoutVariations(): void {
    $this->baseMetadata = ['drupal' => []];
    $draft = $this->productVariation(23, 'SHIRT-DRAFT', 'Green', [], FALSE);
    $context = self::context();

    $document = $this->normalizer()->normalize($this->shirt([$draft]), $this->source, $context)[0];

    $metadata = $document->metadata;
    ksort($metadata);
    $this->assertSame([
      'drupal' => [],
      'product_id' => '10',
      'store_name' => 'Main store',
      'url' => 'https://shop.example/product/10',
    ], $metadata);
    $this->assertSame("<h1>Shirt</h1>\n<p>Cotton shirt.</p>", $document->text);
    $this->assertContains('The product has no published variation viewable by every principal of the ACL: variation_id, sku and price are not sent.', $context->getWarnings());
  }

  /**
   * Other entities and empty results pass through; the type is checked.
   */
  public function testPassThroughAndWarnings(): void {
    $node = $this->createMock(ContentEntityInterface::class);
    $normalizer = $this->normalizer();
    $context = self::context();
    $documents = $normalizer->normalize($node, $this->source, $context);
    $this->assertCount(1, $documents);
    $this->assertSame([], $documents[0]->metadata);

    $this->assertSame([], $this->normalizer([])->normalize($this->shirt([]), $this->source, $context));

    $source = $this->createMock(SourceInterface::class);
    $source->method('getDocumentType')->willReturn('page');
    $context = self::context();
    $this->normalizer()->normalize($this->shirt([]), $source, $context);
    $this->assertContains('The document type is "page": the platform chunks product documents as a whole only when the type is "product".', $context->getWarnings());
    $this->assertTrue($normalizer->applies('commerce_product'));
    $this->assertFalse($normalizer->applies('node'));
  }

  /**
   * The computed keys never push the metadata over 60 keys.
   */
  public function testMetadataLimit(): void {
    $this->baseMetadata = ['drupal' => [], 'brand' => 'ACME'];
    for ($i = 1; $i <= 58; $i++) {
      $this->baseMetadata['extra_' . $i] = $i;
    }
    $red = $this->productVariation(21, 'SHIRT-RED', 'Red');
    $context = self::context();

    $metadata = $this->normalizer()->normalize($this->shirt([$red]), $this->source, $context)[0]->metadata;

    $this->assertCount(CanonicalDocument::MAX_METADATA_KEYS, $metadata);
    $kept = [
      'drupal', 'brand', 'product_id', 'variation_id', 'sku', 'url', 'price', 'price_amount', 'currency', 'store_name',
    ];
    foreach (array_merge($kept, ['extra_1']) as $key) {
      $this->assertArrayHasKey($key, $metadata);
    }
    $this->assertArrayNotHasKey('extra_58', $metadata);
    $this->assertContains('The metadata key "extra_58" was dropped: documents accept at most 60 metadata keys.', $context->getWarnings());
  }

}
