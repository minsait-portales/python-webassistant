<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector_documents\Controller\DownloadController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the download route over HTTP, as the platform calls it.
 *
 * @group webassistant_connector
 */
#[CoversClass(DownloadController::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class DownloadRouteTest extends BrowserTestBase {

  /**
   * Bytes of the private test document.
   */
  private const BYTES = "%PDF-1.4\n% private manual\n%%EOF\n";

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'file',
    'webassistant_connector',
    'webassistant_connector_documents',
    'webassistant_connector_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The private file.
   */
  private FileInterface $file;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    FieldStorageConfig::create([
      'field_name' => 'field_document',
      'entity_type' => 'node',
      'type' => 'file',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'settings' => ['uri_scheme' => 'private'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_document',
      'entity_type' => 'node',
      'bundle' => 'article',
      'settings' => ['file_extensions' => 'pdf'],
    ])->save();

    file_put_contents('private://manual.pdf', self::BYTES);
    $this->file = File::create(['uri' => 'private://manual.pdf', 'filename' => 'manual.pdf', 'status' => 1]);
    $this->file->save();
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Private manual',
      'field_document' => [['target_id' => $this->file->id()]],
    ]);
    $this->container->get('webassistant_connector.document_map')->save([
      'knowledge_base' => 'help',
      'remote_id' => 'drupal-test:node:' . $node->id() . ':en:file:' . $this->file->uuid(),
      'source' => 'articles',
      'entity_type' => 'node',
      'entity_id' => (string) $node->id(),
      'langcode' => 'en',
      'attachment' => (string) $this->file->uuid(),
      'version' => 'm1.0123456789abcdef',
      'content_hash' => str_repeat('a', 64),
      'payload_hash' => str_repeat('b', 64),
      'acl' => ['authenticated'],
      'remote_status' => 'sending',
    ]);
  }

  /**
   * The platform downloads a private file without session nor redirects.
   */
  public function testAnonymousPrivateDownload(): void {
    $generator = $this->container->get('webassistant_connector_documents.signed_url_generator');
    $signed = $generator->generate((string) $this->file->uuid(), (int) $this->file->getChangedTime(), 600, $this->baseUrl);
    $this->assertStringStartsWith($this->baseUrl . '/webassistant/document/', $signed['url']);

    $this->drupalGet($signed['url']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame($signed['url'], $this->getSession()->getCurrentUrl(), 'No redirect.');
    // The page content of Mink is trimmed: compare the raw response.
    $this->assertSame(self::BYTES, $this->getSession()->getDriver()->getContent());
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/pdf');
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'attachment');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSession()->responseHeaderContains('X-Robots-Tag', 'noindex');
    $this->assertSession()->responseHeaderEquals('X-Content-Type-Options', 'nosniff');
    $this->assertSession()->responseHeaderDoesNotExist('Set-Cookie');
    $this->assertSession()->responseHeaderNotContains('X-Drupal-Cache', 'HIT');

    // A second request is never served from the page cache.
    $this->drupalGet($signed['url']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderNotContains('X-Drupal-Cache', 'HIT');

    // Tampered signature: 403 without session either.
    $tampered = substr($signed['url'], 0, -1) . (str_ends_with($signed['url'], 'A') ? 'B' : 'A');
    $this->drupalGet($tampered);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->responseHeaderDoesNotExist('Set-Cookie');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
  }

}
