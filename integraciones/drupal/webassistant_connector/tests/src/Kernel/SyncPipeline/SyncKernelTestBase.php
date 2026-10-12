<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\SyncPipeline;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector_sync_test\ScriptedPlatformClient;

/**
 * Base of the synchronization kernel tests.
 *
 * Articles (translatable, English and Spanish) are covered by the enabled
 * source "articles" (knowledge base "help", ACL public); pages are not.
 * The platform is ScriptedPlatformClient.
 */
abstract class SyncKernelTestBase extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * Prefix of the remote source IDs.
   */
  protected const PREFIX = 'drupal-test';

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
    'language',
    'webassistant_connector',
    'webassistant_connector_sync_test',
  ];

  /**
   * The source of articles.
   */
  protected SourceInterface $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ScriptedPlatformClient::reset();
    putenv('WEBASSISTANT_CLIENT_ID=test-client');
    putenv('WEBASSISTANT_CLIENT_SECRET=test-secret');

    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'user', 'language', 'webassistant_connector']);
    $this->installSchema('webassistant_connector', ['webassistant_connector_outbox', 'webassistant_connector_document']);

    ConfigurableLanguage::createFromLangcode('es')->save();
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $rid) {
      Role::load($rid)->grantPermission('access content')->save();
    }
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    $this->createContentType(['type' => 'page', 'name' => 'Page']);

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
      'acl_mode' => SourceInterface::ACL_PUBLIC,
      'fields' => [
        ['field' => 'title', 'role' => 'title'],
      ],
    ]);
    $this->source->save();
    // Creating the source scheduled a reindex job: start from empty queues.
    $this->clearQueues();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('WEBASSISTANT_CLIENT_ID');
    putenv('WEBASSISTANT_CLIENT_SECRET');
    parent::tearDown();
  }

  /**
   * Creates a published article with a Spanish translation.
   *
   * @param string $title
   *   The English title.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createArticle(string $title = 'Hello'): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'langcode' => 'en', 'status' => 1]);
    $node->addTranslation('es', ['title' => $title . ' (es)', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Returns the outbox rows keyed by "source:type:id:langcode".
   *
   * @return array<string, \Drupal\webassistant_connector\Sync\OutboxItem>
   *   The rows.
   */
  protected function outboxRows(): array {
    $rows = [];
    $result = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o')->orderBy('id')->execute()->fetchAll(FetchAs::Associative);
    foreach ($result as $row) {
      $item = OutboxItem::fromRow($row);
      $rows[$item->getKey()] = $item;
    }
    return $rows;
  }

  /**
   * Returns the map rows keyed by remote ID.
   *
   * @return array<string, array<string, mixed>>
   *   The rows.
   */
  protected function mapRows(): array {
    $rows = [];
    foreach ($this->container->get('webassistant_connector.document_map')->page(NULL, 1000) as $row) {
      $rows[$row['remote_id']] = $row;
    }
    return $rows;
  }

  /**
   * Builds the remote source ID of a node translation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The source ID.
   */
  protected function remoteId(NodeInterface $node, string $langcode): string {
    return self::PREFIX . ':node:' . $node->id() . ':' . $langcode;
  }

  /**
   * Builds the outbox key of a node translation in the articles source.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The key.
   */
  protected function key(NodeInterface $node, string $langcode): string {
    return 'articles:node:' . $node->id() . ':' . $langcode;
  }

  /**
   * Counts the items of a queue.
   *
   * @param string $name
   *   The queue name.
   *
   * @return int
   *   The number of items.
   */
  protected function queueCount(string $name): int {
    return $this->container->get('queue')->get($name)->numberOfItems();
  }

  /**
   * Empties the queues of the connector.
   */
  protected function clearQueues(): void {
    foreach ([Outbox::DISPATCH_QUEUE, Indexer::INDEX_QUEUE, 'webassistant_connector_reconcile'] as $name) {
      $this->container->get('queue')->get($name)->deleteQueue();
    }
  }

  /**
   * Updates outbox rows directly.
   *
   * @param array<string, mixed> $fields
   *   The columns.
   * @param string|null $key
   *   Only the row of this key, or NULL for all.
   */
  protected function updateOutbox(array $fields, ?string $key = NULL): void {
    $query = $this->container->get('database')->update(Outbox::TABLE)->fields($fields);
    if ($key !== NULL) {
      [$source, $type, $id, $langcode] = explode(':', $key);
      $query->condition('source', $source)->condition('entity_type', $type)->condition('entity_id', $id)->condition('langcode', $langcode);
    }
    $query->execute();
  }

  /**
   * Returns the document map service.
   */
  protected function documentMap(): DocumentMap {
    return $this->container->get('webassistant_connector.document_map');
  }

}
