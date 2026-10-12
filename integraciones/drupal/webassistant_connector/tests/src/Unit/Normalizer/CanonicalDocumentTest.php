<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\Normalizer;

use Drupal\Tests\UnitTestCase;
use Drupal\Tests\webassistant_connector\Traits\CanonicalDocumentSchemaTrait;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the canonical-document.v1 shape produced by CanonicalDocument.
 *
 * @group webassistant_connector
 */
#[CoversClass(CanonicalDocument::class)]
#[Group('webassistant_connector')]
final class CanonicalDocumentTest extends UnitTestCase {

  use CanonicalDocumentSchemaTrait;

  /**
   * Builds a complete text document.
   */
  private function textDocument(): CanonicalDocument {
    return new CanonicalDocument(
      sourceId: 'drupal-1a2b3c4d:node:42:es',
      knowledgeBase: 'ayuda',
      type: 'guide',
      title: 'Cómo pedir cita',
      version: 'm1.0123456789abcdef',
      locale: 'es',
      text: '<h1>Cómo pedir cita</h1><p>Texto</p>',
      contentFormat: CanonicalDocument::FORMAT_HTML,
      canonicalUrl: 'https://www.example.com/es/node/42',
      acl: ['public'],
      siteId: 'site_demo',
      metadata: ['drupal' => ['entity_type' => 'node', 'id' => '42'], 'tags' => ['a', 'b']],
      contentHash: hash('sha256', '<h1>Cómo pedir cita</h1><p>Texto</p>'),
      createdAt: CanonicalDocument::formatDate(1760000000),
      updatedAt: CanonicalDocument::formatDate(1760000500),
      attachment: '0f8e2c56-1111-4a2b-9c3d-123456789abc',
    );
  }

  /**
   * A full text document matches the schema and omits local members.
   */
  public function testTextDocumentMatchesSchema(): void {
    $document = $this->textDocument();
    $array = $document->toArray();
    $this->assertCanonicalDocument($array);
    $this->assertSame([], $document->violations());
    $this->assertArrayNotHasKey('attachment', $array);
    $this->assertArrayNotHasKey('reference', $array);
    $this->assertSame('2025-10-09T08:53:20+00:00', $array['created_at']);
    $this->assertSame(['public'], $array['acl']);
    $this->assertSame([
      'source_id', 'knowledge_base', 'site_id', 'type', 'locale', 'title', 'text', 'content_format',
      'canonical_url', 'acl', 'version', 'content_hash', 'metadata', 'created_at', 'updated_at',
    ], array_keys($array));
  }

  /**
   * NULL members and empty metadata are omitted.
   */
  public function testMinimalDocument(): void {
    $document = new CanonicalDocument('drupal:node:1:en', 'kb_1', 'page', 'T', 'm1.x', text: 'Hello', acl: ['authenticated']);
    $array = $document->toArray();
    $this->assertSame(['source_id', 'knowledge_base', 'type', 'title', 'text', 'acl', 'version'], array_keys($array));
    $this->assertCanonicalDocument($array);
  }

  /**
   * Reference documents carry no text and drop NULL reference members.
   */
  public function testReferenceDocument(): void {
    $document = $this->textDocument()->withReference([
      'download_url' => 'https://drupal.example.com/webassistant/document/u/1/s',
      'media_type' => 'application/pdf',
      'access_token' => NULL,
      'expires_at' => '2026-10-20T10:00:00+00:00',
    ])->withContentHash(NULL)->withContentFormat(NULL)->withAcl(['role:editor', 'role:editor', 'group:12']);
    $array = $document->toArray();
    $this->assertArrayNotHasKey('text', $array);
    $this->assertSame(['download_url', 'media_type', 'expires_at'], array_keys($array['reference']));
    $this->assertSame(['role:editor', 'group:12'], $array['acl']);
    $this->assertTrue($document->isReference());
    $this->assertCanonicalDocument($array);
  }

  /**
   * Documents that would widen access or break the contract are refused.
   */
  public function testInvalidDocumentsAreRefused(): void {
    $empty = $this->textDocument()->withAcl([]);
    $this->assertContains('acl is empty.', $empty->violations());
    try {
      $empty->toArray();
      $this->fail('An empty ACL must not be serialized.');
    }
    catch (\LogicException) {
      // Expected.
    }

    $invalid = new CanonicalDocument('bad id', 'KB', 'Page', ' ', str_repeat('v', 65), locale: 'pt-br', text: 'x', canonicalUrl: '/node/1', acl: ['role:bad id'], contentHash: 'abc');
    $violations = implode("\n", $invalid->violations());
    $members = [
      'source_id',
      'knowledge_base',
      'type',
      'title',
      'version',
      'locale',
      'canonical_url',
      'principal',
      'content_hash',
    ];
    foreach ($members as $member) {
      $this->assertStringContainsString($member, $violations);
    }
  }

  /**
   * Long titles are cut to the platform limit; with*() never mutates.
   */
  public function testImmutabilityAndTitleLimit(): void {
    $document = $this->textDocument();
    $long = $document->withTitle(str_repeat('á', 600));
    $this->assertSame('Cómo pedir cita', $document->title);
    $this->assertSame(500, mb_strlen($long->toArray()['title']));
    $this->assertSame(['x' => 1], $document->withMetadata([])->withMetadataValue('x', 1)->metadata);
    $this->assertArrayNotHasKey('tags', $document->withMetadataValue('tags', NULL)->metadata);
  }

}
