<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Sync;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\DocumentBuilder;
use Drupal\webassistant_connector\Sync\DocumentBuildResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Only the default revision of moderated content is indexed (DR-04.6).
 *
 * @group webassistant_connector
 */
#[CoversClass(DocumentBuilder::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class ContentModerationBuildTest extends KernelTestBase {

  use ContentModerationTestTrait;
  use ContentTypeCreationTrait;
  use NodeCreationTrait;

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
    'workflows',
    'content_moderation',
    'webassistant_connector',
  ];

  /**
   * The source of articles.
   */
  private SourceInterface $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['filter', 'node', 'user', 'content_moderation', 'webassistant_connector']);
    $this->installSchema('webassistant_connector', ['webassistant_connector_outbox', 'webassistant_connector_document']);
    $this->container->get('router.builder')->rebuild();
    $this->createContentType(['type' => 'article']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    $this->source = Source::create([
      'id' => 'articles',
      'label' => 'Articles',
      'entity_type' => 'node',
      'bundles' => ['article'],
      'knowledge_base' => 'ayuda',
      'document_type' => 'page',
      'fields' => [['field' => 'title', 'role' => 'title']],
    ]);
    $this->source->save();
  }

  /**
   * Builds the English document of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return \Drupal\webassistant_connector\Sync\DocumentBuildResult
   *   The result.
   *
   * @phpstan-impure
   */
  private function build(NodeInterface $node): DocumentBuildResult {
    return $this->container->get('webassistant_connector.document_builder')->build($this->source, 'node', (string) $node->id(), 'en');
  }

  /**
   * Drafts after a published revision keep the published one indexed.
   */
  public function testForwardRevisions(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'Version 1', 'moderation_state' => 'published']);
    $this->assertSame('Version 1', $this->build($node)->getMainDocument()?->title);

    $node->setTitle('Version 2 draft');
    $node->set('moderation_state', 'draft');
    $node->save();
    $this->assertFalse($node->isDefaultRevision());
    $this->assertSame('Version 1', $this->build($node)->getMainDocument()?->title);
    $this->assertSame(['public'], $this->build($node)->getMainDocument()?->acl);

    $node->setTitle('Version 3');
    $node->set('moderation_state', 'published');
    $node->save();
    $this->assertSame('Version 3', $this->build($node)->getMainDocument()?->title);

    $node->set('moderation_state', 'archived');
    $node->save();
    $this->assertSame(DocumentBuildResult::SKIP_UNPUBLISHED, $this->build($node)->getSkipReason());

    $draft = $this->createNode(['type' => 'article', 'title' => 'Only a draft', 'moderation_state' => 'draft']);
    $this->assertSame(DocumentBuildResult::SKIP_UNPUBLISHED, $this->build($draft)->getSkipReason());
  }

}
