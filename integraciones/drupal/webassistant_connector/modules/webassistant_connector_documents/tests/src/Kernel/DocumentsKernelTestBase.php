<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector_documents\DocumentsSettings;

/**
 * Base of the kernel tests of the Documents extension.
 *
 * Articles have a file field (field_document, public files with
 * descriptions) and a reference to "document" media items (file source).
 * The enabled source "articles" (knowledge base "help", ACL computed from
 * access) indexes their attachments. The platform client is the fake one.
 */
abstract class DocumentsKernelTestBase extends KernelTestBase {

  use ContentTypeCreationTrait;
  use EntityReferenceFieldCreationTrait;
  use MediaTypeCreationTrait;

  /**
   * Prefix of the remote source IDs.
   */
  protected const PREFIX = 'drupal-test';

  /**
   * Base URL with which the platform reaches Drupal.
   */
  protected const DOWNLOAD_BASE_URL = 'http://drupal.internal';

  /**
   * Bytes of the test PDF.
   */
  protected const PDF_BYTES = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'media',
    'webassistant_connector',
    'webassistant_connector_documents',
    'webassistant_connector_test',
  ];

  /**
   * The source of articles.
   */
  protected SourceInterface $source;

  /**
   * The media type with a file source.
   */
  protected MediaTypeInterface $mediaType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig([
      'system',
      'field',
      'filter',
      'node',
      'user',
      'file',
      'image',
      'media',
      'webassistant_connector',
    ]);
    $this->installSchema('webassistant_connector', [Outbox::TABLE, DocumentMap::TABLE]);
    $this->container->get('router.builder')->rebuild();

    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $rid) {
      Role::load($rid)->grantPermission('access content')->grantPermission('view media')->save();
    }

    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    FieldStorageConfig::create([
      'field_name' => 'field_document',
      'entity_type' => 'node',
      'type' => 'file',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'settings' => ['uri_scheme' => 'public', 'display_field' => TRUE, 'display_default' => TRUE],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_document',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Documents',
      'settings' => ['file_extensions' => 'pdf txt docx md', 'description_field' => TRUE],
    ])->save();
    $this->mediaType = $this->createMediaType('file', ['id' => 'document', 'label' => 'Document']);
    $this->createEntityReferenceField('node', 'article', 'field_media', 'Media', 'media', 'default', ['target_bundles' => ['document' => 'document']], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $this->config('webassistant_connector.settings')
      ->set('sync.source_id_prefix', self::PREFIX)
      ->set('sync.public_base_url', 'https://www.example.com')
      ->save();

    $this->source = Source::create([
      'id' => 'articles',
      'label' => 'Articles',
      'status' => TRUE,
      'entity_type' => 'node',
      'bundles' => ['article'],
      'knowledge_base' => 'help',
      'document_type' => 'guide',
      'acl_mode' => SourceInterface::ACL_ACCESS,
      'fields' => [
        ['field' => 'title', 'role' => 'title'],
        ['field' => 'body', 'role' => 'main_content'],
      ],
    ]);
    $this->setDocumentsSettings([]);
  }

  /**
   * Saves the source with attachment settings (enabled, both fields).
   *
   * @param array<string, mixed> $overrides
   *   Settings that replace the defaults of the tests.
   */
  protected function setDocumentsSettings(array $overrides): void {
    $settings = DocumentsSettings::fromArray($overrides + [
      'enabled' => TRUE,
      'fields' => ['field_document', 'field_media'],
      'download_base_url' => self::DOWNLOAD_BASE_URL,
    ]);
    foreach ($settings->toArray() as $key => $value) {
      $this->source->setThirdPartySetting(DocumentsSettings::MODULE, $key, $value);
    }
    $this->source->save();
    $this->container->get('webassistant_connector.source_matcher')->reset();
  }

  /**
   * Creates a file entity with content.
   *
   * @param string $filename
   *   The file name.
   * @param string $bytes
   *   The content.
   * @param bool $permanent
   *   Whether the file is permanent.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file.
   */
  protected function createFile(string $filename = 'manual.pdf', string $bytes = self::PDF_BYTES, bool $permanent = TRUE): FileInterface {
    $uri = 'public://' . $filename;
    file_put_contents($uri, $bytes);
    $file = File::create([
      'uri' => $uri,
      'filename' => $filename,
      'status' => $permanent ? 1 : 0,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Creates a published article.
   *
   * @param array<string, mixed> $values
   *   Field values (e.g. field_document, field_media).
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createArticle(array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'article',
      'title' => 'Installation guide',
      'langcode' => 'en',
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a document media item.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param string $name
   *   The media name.
   * @param bool $published
   *   Whether the media item is published.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media item.
   */
  protected function createMedia(FileInterface $file, string $name = 'Product sheet', bool $published = TRUE): MediaInterface {
    $field = (string) $this->mediaType->getSource()->getConfiguration()['source_field'];
    $media = Media::create([
      'bundle' => 'document',
      'name' => $name,
      'status' => $published ? 1 : 0,
      $field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Removes every outbox row.
   */
  protected function clearOutbox(): void {
    $this->container->get('database')->delete(Outbox::TABLE)->execute();
  }

  /**
   * Returns the outbox rows keyed by "source:type:id:langcode".
   *
   * @return array<string, \Drupal\webassistant_connector\Sync\OutboxItem>
   *   The rows.
   */
  protected function outboxRows(): array {
    $rows = [];
    $result = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o')->orderBy('id')->execute()->fetchAll();
    foreach ($result as $row) {
      $item = OutboxItem::fromRow($row);
      $rows[$item->getKey()] = $item;
    }
    return $rows;
  }

  /**
   * Returns the remote source ID of an attachment of an article.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The article.
   * @param \Drupal\file\FileInterface $file
   *   The file.
   *
   * @return string
   *   The source ID.
   */
  protected function attachmentId(NodeInterface $node, FileInterface $file): string {
    return self::PREFIX . ':node:' . $node->id() . ':en:file:' . $file->uuid();
  }

}
