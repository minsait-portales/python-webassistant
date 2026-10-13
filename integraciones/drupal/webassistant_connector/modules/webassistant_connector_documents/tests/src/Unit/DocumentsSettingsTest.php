<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector_documents\DocumentsSettings;
use Drupal\webassistant_connector_documents\MediaTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the attachment settings and the media type allowlist.
 *
 * @group webassistant_connector
 */
#[CoversClass(DocumentsSettings::class)]
#[CoversClass(MediaTypes::class)]
#[Group('webassistant_connector')]
final class DocumentsSettingsTest extends UnitTestCase {

  /**
   * Defaults: disabled, PDF and DOCX, 50 MB, 7 days.
   */
  public function testDefaults(): void {
    $settings = DocumentsSettings::fromArray([]);
    $this->assertFalse($settings->enabled);
    $this->assertFalse($settings->isActive());
    $this->assertSame([MediaTypes::PDF, MediaTypes::DOCX], $settings->mimeTypes);
    $this->assertSame(50000000, $settings->maxBytes);
    $this->assertSame(604800, $settings->urlTtl);
    $this->assertSame([
      'enabled' => FALSE,
      'fields' => [],
      'knowledge_base' => '',
      'mime_types' => [MediaTypes::PDF, MediaTypes::DOCX],
      'max_bytes' => 50000000,
      'url_ttl' => 604800,
      'download_base_url' => '',
    ], $settings->toArray());
  }

  /**
   * Raw values are sanitized: nothing invalid widens what is sent.
   */
  public function testSanitize(): void {
    $settings = DocumentsSettings::fromArray([
      'enabled' => '1',
      // Checkboxes values: unchecked options are 0.
      'fields' => [
        'field_document' => 'field_document',
        'field_media' => 0,
        'bad name' => 'bad name',
        'x' => ['nested'],
      ],
      'knowledge_base' => 'Bad KB',
      'mime_types' => [MediaTypes::PDF, 'application/x-msdownload', MediaTypes::MARKDOWN],
      'max_bytes' => 999999999,
      'url_ttl' => 10,
      'download_base_url' => 'javascript:alert(1)',
    ]);
    $this->assertTrue($settings->isActive());
    $this->assertSame(['field_document'], $settings->fields);
    $this->assertSame('', $settings->knowledgeBase);
    $this->assertSame([MediaTypes::PDF, MediaTypes::MARKDOWN], $settings->mimeTypes);
    $this->assertSame(DocumentsSettings::MAX_BYTES, $settings->maxBytes);
    $this->assertSame(DocumentsSettings::MIN_URL_TTL, $settings->urlTtl);
    $this->assertSame('', $settings->downloadBaseUrl);

    $settings = DocumentsSettings::fromArray([
      'enabled' => TRUE,
      'fields' => ['field_document'],
      'knowledge_base' => 'manuals',
      'mime_types' => [],
      'url_ttl' => 99999999,
      'download_base_url' => 'http://drupal:8080/',
    ]);
    $this->assertFalse($settings->isActive(), 'No allowed media type: nothing to contribute.');
    $this->assertSame('manuals', $settings->knowledgeBase);
    $this->assertSame(DocumentsSettings::MAX_URL_TTL, $settings->urlTtl);
    $this->assertSame('http://drupal:8080', $settings->downloadBaseUrl);
  }

  /**
   * MIME types map to the media types the platform extracts.
   *
   * @param string $mimeType
   *   The MIME type of the file entity.
   * @param string $filename
   *   The file name.
   * @param string|null $expected
   *   The expected media type.
   */
  #[DataProvider('providerMediaTypes')]
  public function testResolveMediaType(string $mimeType, string $filename, ?string $expected): void {
    $this->assertSame($expected, MediaTypes::resolve($mimeType, $filename));
  }

  /**
   * Data provider of testResolveMediaType().
   *
   * @return array<string, array{string, string, string|null}>
   *   MIME type, file name and expected media type.
   */
  public static function providerMediaTypes(): array {
    return [
      'pdf' => ['application/pdf', 'manual.pdf', MediaTypes::PDF],
      'pdf alias' => ['application/x-pdf', 'manual.pdf', MediaTypes::PDF],
      'docx' => [MediaTypes::DOCX, 'guide.docx', MediaTypes::DOCX],
      'html with charset' => ['text/html; charset=UTF-8', 'page.html', MediaTypes::HTML],
      'xhtml' => ['application/xhtml+xml', 'page.xhtml', MediaTypes::HTML],
      'text' => ['text/plain', 'notes.txt', MediaTypes::TEXT],
      'markdown without MIME type' => ['application/octet-stream', 'README.md', MediaTypes::MARKDOWN],
      'markdown as text' => ['text/plain', 'README.markdown', MediaTypes::MARKDOWN],
      'generic pdf' => ['application/octet-stream', 'scan.PDF', MediaTypes::PDF],
      'old word' => ['application/msword', 'old.doc', NULL],
      'image' => ['image/png', 'logo.png', NULL],
      'executable named pdf' => ['application/x-msdownload', 'virus.pdf', NULL],
      'generic unknown' => ['application/octet-stream', 'archive.zip', NULL],
    ];
  }

}
