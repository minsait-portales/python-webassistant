<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\Tests\webassistant_connector\Traits\CanonicalDocumentSchemaTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Sync\DocumentBuildResult;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector_documents\Contributor\AttachmentContributor;
use Drupal\webassistant_connector_documents\Download\SignedUrlGenerator;
use Drupal\webassistant_connector_documents\MediaTypes;
use Drupal\webassistant_connector_test\FakePlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the reference documents of attached files (design section 11).
 *
 * @group webassistant_connector
 */
#[CoversClass(AttachmentContributor::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class AttachmentContributorTest extends DocumentsKernelTestBase {

  use CanonicalDocumentSchemaTrait;

  /**
   * Builds the documents of an article.
   *
   * @param int|string $nid
   *   The node ID.
   * @param bool $preview
   *   Whether to build a preview.
   *
   * @return \Drupal\webassistant_connector\Sync\DocumentBuildResult
   *   The result.
   */
  private function build(int|string $nid, bool $preview = FALSE): DocumentBuildResult {
    return $this->container->get('webassistant_connector.document_builder')->build($this->source, 'node', $nid, 'en', $preview);
  }

  /**
   * A PDF in a file field becomes a reference document.
   */
  public function testFileFieldDocument(): void {
    $file = $this->createFile();
    $node = $this->createArticle([
      'field_document' => [['target_id' => $file->id(), 'description' => 'User manual', 'display' => 1]],
    ]);

    $result = $this->build($node->id());
    $this->assertFalse($result->isSkipped());
    $documents = $result->getDocuments();
    $this->assertCount(2, $documents);
    [$parent, $attachment] = $documents;
    $uuid = (string) $file->uuid();
    $changed = (int) $file->getChangedTime();
    $size = strlen(self::PDF_BYTES);
    $hash = hash('sha256', $uuid . '|' . $changed . '|' . $size);

    $this->assertSame($this->attachmentId($node, $file), $attachment->sourceId);
    $this->assertSame('help', $attachment->knowledgeBase);
    $this->assertSame('document', $attachment->type);
    $this->assertSame('User manual', $attachment->title);
    $this->assertSame('en', $attachment->locale);
    $this->assertNull($attachment->text);
    $this->assertSame($hash, $attachment->contentHash);
    $this->assertSame('m1.' . substr($hash, 0, 16), $attachment->version);
    $this->assertSame(['public'], $attachment->acl);
    $this->assertSame($uuid, $attachment->attachment);
    $this->assertSame('https://www.example.com/node/' . $node->id(), $parent->canonicalUrl);
    $this->assertSame($parent->canonicalUrl, $attachment->canonicalUrl, 'Citations link to the parent, never to the signed URL.');

    // The signed URL uses the internal base URL and is valid now.
    $reference = $attachment->reference;
    $this->assertSame(MediaTypes::PDF, $reference['media_type']);
    $this->assertMatchesRegularExpression('#^http://drupal\.internal/webassistant/document/' . $uuid . '/(\d+)/([A-Za-z0-9_-]{43})$#', $reference['download_url']);
    preg_match('#/(\d+)/([A-Za-z0-9_-]{43})$#', $reference['download_url'], $matches);
    $generator = $this->container->get('webassistant_connector_documents.signed_url_generator');
    $this->assertTrue($generator->verify($uuid, (int) $matches[1], $changed, $matches[2]));
    $this->assertSame(CanonicalDocument::formatDate((int) $matches[1]), $reference['expires_at']);
    $this->assertEqualsWithDelta(\Drupal::time()->getCurrentTime() + 604800, (int) $matches[1], 5);

    $this->assertSame([
      'file_name' => 'manual.pdf',
      'mime_type' => 'application/pdf',
      'size' => $size,
      'parent_title' => 'Installation guide',
      'parent_url' => $parent->canonicalUrl,
    ], array_diff_key($attachment->metadata, ['drupal' => TRUE]));
    $this->assertSame($uuid, $attachment->metadata['drupal']['attachment']);
    $this->assertSame((string) $node->id(), $attachment->metadata['drupal']['id']);

    $array = $attachment->toArray();
    $this->assertArrayNotHasKey('attachment', $array);
    $this->assertArrayNotHasKey('text', $array);
    $this->assertSame([], $attachment->violations());
    $this->assertCanonicalDocument($array);

    // Stable between builds: same version, hash and payload hash, although
    // the signed URL is minted again.
    $again = $this->build($node->id())->getDocuments()[1];
    $this->assertSame($attachment->version, $again->version);
    $this->assertSame($attachment->contentHash, $again->contentHash);
    $this->assertSame(DocumentMap::payloadHash($array), DocumentMap::payloadHash($again->toArray()));

    // The preview never issues a usable URL.
    $preview = $this->build($node->id(), TRUE)->getDocuments()[1];
    $this->assertStringEndsWith('/' . SignedUrlGenerator::PREVIEW_SIGNATURE, $preview->reference['download_url']);
    $this->assertSame($attachment->version, $preview->version);
  }

  /**
   * Files that cannot or must not be indexed are skipped with a warning.
   */
  public function testSkippedFiles(): void {
    $text = $this->createFile('notes.txt', "Plain notes\n");
    $temporary = $this->createFile('draft.pdf', self::PDF_BYTES, FALSE);
    $hidden = $this->createFile('hidden.pdf');
    $large = $this->createFile('large.pdf', str_repeat('x', 2048));
    $node = $this->createArticle([
      'field_document' => [
        ['target_id' => $text->id(), 'display' => 1],
        ['target_id' => $hidden->id(), 'display' => 0],
        ['target_id' => $large->id(), 'display' => 1],
      ],
    ]);
    $this->setDocumentsSettings(['max_bytes' => 1024]);
    $result = $this->build($node->id());
    $this->assertCount(1, $result->getDocuments(), 'Only the parent document is built.');
    $warnings = implode("\n", $result->getWarnings());
    $this->assertStringContainsString('Attachment ' . $text->uuid() . ' skipped: its media type is not allowed.', $warnings);
    $this->assertStringContainsString('Attachment ' . $large->uuid() . ' skipped: its size (2048 bytes)', $warnings);
    $this->assertStringNotContainsString((string) $hidden->uuid(), $warnings, 'Files hidden from the display are ignored silently.');

    // Allowing plain text and a larger size contributes both files.
    $this->setDocumentsSettings(['mime_types' => [MediaTypes::PDF, MediaTypes::TEXT]]);
    $documents = $this->build($node->id())->getDocuments();
    $this->assertCount(3, $documents);
    $this->assertSame(MediaTypes::TEXT, $documents[1]->reference['media_type']);
    $this->assertSame('notes.txt', $documents[1]->title);
    $this->assertSame((string) $large->uuid(), $documents[2]->attachment);

    // Temporary files are never indexed (saving the node made the file
    // permanent: make it temporary again).
    $node->get('field_document')->appendItem(['target_id' => $temporary->id(), 'display' => 1]);
    $node->save();
    $temporary->setTemporary();
    $temporary->save();
    $result = $this->build($node->id());
    $this->assertCount(3, $result->getDocuments());
    $this->assertStringContainsString('Attachment ' . $temporary->uuid() . ' skipped: the file is temporary.', implode("\n", $result->getWarnings()));

    // Disabled attachments: only the parent.
    $this->setDocumentsSettings(['enabled' => FALSE]);
    $this->assertCount(1, $this->build($node->id())->getDocuments());
  }

  /**
   * Media items with a file source contribute their file (amendment B6).
   */
  public function testMediaDocument(): void {
    $file = $this->createFile('sheet.pdf');
    $media = $this->createMedia($file, 'Product sheet');
    $node = $this->createArticle(['field_media' => [['target_id' => $media->id()]]]);
    $this->setDocumentsSettings(['knowledge_base' => 'manuals']);

    $documents = $this->build($node->id())->getDocuments();
    $this->assertCount(2, $documents);
    $attachment = $documents[1];
    $this->assertSame($this->attachmentId($node, $file), $attachment->sourceId);
    $this->assertSame('manuals', $attachment->knowledgeBase);
    $this->assertSame('Product sheet', $attachment->title);
    $this->assertSame(['public'], $attachment->acl);
    $this->assertSame((string) $media->id(), $attachment->metadata['drupal']['media']);
    $this->assertCanonicalDocument($attachment->toArray());

    // The same file referenced twice is contributed once.
    $node->set('field_document', [['target_id' => $file->id(), 'display' => 1]])->save();
    $this->assertCount(2, $this->build($node->id())->getDocuments());

    // Readers that may not view the media item are removed from the ACL:
    // with nobody left, the file is not contributed.
    $node->set('field_document', [])->save();
    Role::load(RoleInterface::ANONYMOUS_ID)->revokePermission('view media')->save();
    $result = $this->build($node->id());
    $this->assertSame(['public'], $result->getDocuments()[0]->acl);
    $this->assertCount(1, $result->getDocuments());
    $this->assertStringContainsString('no principal of the ACL may view the media item', implode("\n", $result->getWarnings()));
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('view media')->save();

    // Unpublished media items are skipped.
    $media->setUnpublished()->save();
    $result = $this->build($node->id());
    $this->assertCount(1, $result->getDocuments());
    $this->assertStringContainsString('Media item ' . $media->id() . ' of field_media was skipped: it is unpublished.', implode("\n", $result->getWarnings()));
  }

  /**
   * Private files follow the access of the entities that reference them.
   */
  public function testPrivateFile(): void {
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    // KernelTestBase::register() updates $this->container.
    $this->container->get('kernel')->rebuildContainer();
    $this->container->get('stream_wrapper_manager')->register();
    mkdir($this->siteDirectory . '/private', 0777, TRUE);
    FieldStorageConfig::create([
      'field_name' => 'field_private',
      'entity_type' => 'node',
      'type' => 'file',
      'settings' => ['uri_scheme' => 'private'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_private',
      'entity_type' => 'node',
      'bundle' => 'article',
      'settings' => ['file_extensions' => 'pdf'],
    ])->save();
    $this->setDocumentsSettings(['fields' => ['field_private']]);

    file_put_contents('private://secret.pdf', self::PDF_BYTES);
    $file = File::create(['uri' => 'private://secret.pdf', 'filename' => 'secret.pdf', 'status' => 1]);
    $file->save();
    $node = $this->createArticle(['field_private' => [['target_id' => $file->id()]]]);

    $documents = $this->build($node->id())->getDocuments();
    $this->assertCount(2, $documents);
    $this->assertSame(['public'], $documents[1]->acl);

    // Only authenticated users can view the article: so is the file.
    Role::load(RoleInterface::ANONYMOUS_ID)->revokePermission('access content')->save();
    $documents = $this->build($node->id())->getDocuments();
    $this->assertSame(['authenticated'], $documents[0]->acl);
    $this->assertCount(2, $documents);
    $this->assertSame(['authenticated'], $documents[1]->acl);
  }

  /**
   * The worker maps the attachment and sends it as a reference.
   */
  public function testSynchronization(): void {
    putenv('WEBASSISTANT_CLIENT_ID=test-client');
    putenv('WEBASSISTANT_CLIENT_SECRET=test-secret');
    try {
      $file = $this->createFile();
      $node = $this->createArticle(['field_document' => [['target_id' => $file->id(), 'display' => 1]]]);
      $report = $this->container->get('webassistant_connector.sync_processor')->process(50, 0);
      $this->assertSame(2, $report->getUpserted());

      $calls = FakePlatformClient::calls($this->container->get('state'), 'ingest');
      $this->assertCount(1, $calls);
      $sent = $calls[0][1][0];
      $this->assertCount(2, $sent);
      $this->assertSame($this->attachmentId($node, $file), $sent[1]['source_id']);
      $this->assertArrayHasKey('reference', $sent[1]);
      $this->assertArrayNotHasKey('attachment', $sent[1]);

      $row = $this->container->get('webassistant_connector.document_map')->load('help', $this->attachmentId($node, $file));
      $this->assertNotNull($row);
      $this->assertSame((string) $file->uuid(), $row['attachment']);
      $this->assertSame('queued', $row['remote_status']);
    }
    finally {
      putenv('WEBASSISTANT_CLIENT_ID');
      putenv('WEBASSISTANT_CLIENT_SECRET');
    }
  }

}
