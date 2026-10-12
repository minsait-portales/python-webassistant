<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional\Source;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Entity\SourceListBuilder;
use Drupal\webassistant_connector\Form\SourceForm;
use Drupal\webassistant_connector\Form\SourcePreviewForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the source administration: form, list and preview (section 8.1).
 *
 * @group webassistant_connector
 */
#[CoversClass(SourceForm::class)]
#[CoversClass(SourcePreviewForm::class)]
#[CoversClass(SourceListBuilder::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class SourceUiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'webassistant_connector', 'webassistant_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The administrator.
   */
  private UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->drupalPlaceBlock('local_actions_block');
    $this->drupalPlaceBlock('local_tasks_block');
    $this->admin = $this->drupalCreateUser(['administer webassistant connector', 'access content']);
    $this->drupalLogin($this->admin);
  }

  /**
   * Loads a source from storage.
   *
   * @param string $id
   *   The source ID.
   *
   * @return \Drupal\webassistant_connector\Entity\SourceInterface|null
   *   The source.
   *
   * @phpstan-impure
   */
  private function loadSource(string $id): ?SourceInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('webassistant_source');
    $storage->resetCache([$id]);
    $source = $storage->load($id);
    return $source instanceof SourceInterface ? $source : NULL;
  }

  /**
   * Adding, validating, editing and listing sources.
   */
  public function testSourceForm(): void {
    $this->drupalGet('admin/config/services/webassistant-connector/sources');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('There are no sources yet.');
    $this->clickLink('Add source');
    $this->assertSession()->addressEquals('admin/config/services/webassistant-connector/sources/add');

    // Fields are discovered with their compatible roles only.
    $this->assertSession()->fieldExists('field_roles[title][roles][title]');
    $this->assertSession()->fieldExists('field_roles[body][roles][main_content]');
    $this->assertSession()->fieldNotExists('field_roles[changed][roles][title]');
    $this->assertSession()->fieldExists('field_roles[changed][roles][date]');
    $this->assertSession()->fieldNotExists('field_roles[nid][roles][metadata]');
    $this->assertSession()->fieldNotExists('field_roles[status][roles][exclusion]');
    // The owner reference is flagged as personal data.
    $this->assertSession()->pageTextContains('May contain personal data.');
    $this->assertSession()->checkboxNotChecked('status');

    $edit = [
      'label' => 'Help articles',
      'id' => 'help_articles',
      'entity_type' => 'node',
      'bundles[article]' => TRUE,
      'knowledge_base' => 'Bad KB',
      'document_type' => 'guide',
      'acl_mode' => SourceInterface::ACL_FIELD,
      'field_roles[title][roles][title]' => TRUE,
      'field_roles[title][roles][metadata]' => TRUE,
      'field_roles[title][key]' => 'drupal',
      'field_roles[body][roles][main_content]' => TRUE,
      'field_roles[changed][roles][date]' => TRUE,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('The knowledge base must start with a lowercase letter');
    $this->assertSession()->pageTextContains('The metadata key drupal is reserved.');
    $this->assertSession()->pageTextContains('The "field" access mode needs a field with the Access role.');
    $this->assertNull($this->loadSource('help_articles'));

    $edit['knowledge_base'] = 'ayuda';
    $edit['acl_mode'] = SourceInterface::ACL_ACCESS;
    $edit['field_roles[title][key]'] = 'heading';
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('Created the source Help articles.');
    $this->assertSession()->pageTextContains('Preview its documents, then enable it');
    $this->assertSession()->addressEquals('admin/config/services/webassistant-connector/sources/help_articles/preview');

    $source = $this->loadSource('help_articles');
    $this->assertNotNull($source);
    $this->assertFalse($source->status());
    $this->assertSame('node', $source->getTargetEntityTypeId());
    $this->assertSame(['article'], $source->getBundles());
    $this->assertSame([], $source->getLangcodes());
    $this->assertSame('ayuda', $source->getKnowledgeBase());
    $this->assertSame('guide', $source->getDocumentType());
    $this->assertSame('content_entity', $source->getNormalizerId());
    $this->assertSame(SourceInterface::ACL_ACCESS, $source->getAclMode());
    $fields = $source->getFields();
    $this->assertContains(['field' => 'title', 'role' => 'title'], $fields);
    $this->assertContains(['field' => 'title', 'role' => 'metadata', 'key' => 'heading'], $fields);
    $this->assertContains(['field' => 'body', 'role' => 'main_content'], $fields);
    $this->assertContains(['field' => 'changed', 'role' => 'date'], $fields);
    $this->assertCount(4, $fields);
    $this->assertContains('node.type.article', $source->getDependencies()['config']);

    // Edit: the entity type is locked, enabling works.
    $this->drupalGet('admin/config/services/webassistant-connector/sources/help_articles');
    $this->assertSession()->fieldDisabled('entity_type');
    $this->assertSession()->checkboxChecked('field_roles[body][roles][main_content]');
    $this->assertSession()->fieldValueEquals('field_roles[title][key]', 'heading');
    $this->submitForm(['status' => TRUE], 'Save');
    $this->assertSession()->pageTextContains('Saved the source Help articles.');
    $this->assertSession()->addressEquals('admin/config/services/webassistant-connector/sources');
    $this->assertTrue($this->loadSource('help_articles')?->status());

    // The list shows the source with its preview operation.
    $this->assertSession()->pageTextContains('Help articles');
    $this->assertSession()->pageTextContains('Content: Article');
    $this->assertSession()->pageTextContains('ayuda');
    $this->assertSession()->pageTextContains('Enabled');
    $this->assertSession()->linkByHrefExists('admin/config/services/webassistant-connector/sources/help_articles/preview');

    // Overlapping enabled sources are rejected; a disabled one is accepted.
    $this->drupalGet('admin/config/services/webassistant-connector/sources/add');
    $overlap = [
      'label' => 'All content',
      'id' => 'all_content',
      'entity_type' => 'node',
      'knowledge_base' => 'ayuda',
      'document_type' => 'page',
      'acl_mode' => SourceInterface::ACL_PUBLIC,
      'status' => TRUE,
      'field_roles[body][roles][main_content]' => TRUE,
    ];
    $this->submitForm($overlap, 'Save');
    $this->assertSession()->pageTextContains('The enabled source Help articles already sends some of these items to the knowledge base ayuda.');
    $overlap['bundles[page]'] = TRUE;
    $this->submitForm($overlap, 'Save');
    $this->assertSession()->pageTextContains('Created the source All content.');
    $this->assertSame(['page'], $this->loadSource('all_content')?->getBundles());
  }

  /**
   * The preview shows the canonical JSON or the skip reason.
   */
  public function testPreview(): void {
    Source::create([
      'id' => 'articles',
      'label' => 'Articles',
      'status' => FALSE,
      'entity_type' => 'node',
      'bundles' => ['article'],
      'knowledge_base' => 'ayuda',
      'document_type' => 'guide',
      'fields' => [
        ['field' => 'title', 'role' => 'title'],
        ['field' => 'body', 'role' => 'main_content'],
      ],
    ])->save();
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Preview me',
      'body' => [['value' => 'Preview body text', 'format' => 'plain_text']],
    ]);
    $hidden = $this->drupalCreateNode(['type' => 'article', 'title' => 'Hidden draft', 'status' => 0]);
    $prefix = $this->container->get('webassistant_connector.source_id_generator')->getPrefix();

    $this->drupalGet('admin/config/services/webassistant-connector/sources/articles/preview');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('This source is disabled');
    $this->submitForm(['entity_id' => (string) $node->id()], 'Preview');
    $this->assertSession()->pageTextContains('Documents that would be sent: 1.');
    $this->assertSession()->pageTextContains('"source_id": "' . $prefix . ':node:' . $node->id() . ':en"');
    $this->assertSession()->pageTextContains('"knowledge_base": "ayuda"');
    $this->assertSession()->pageTextContains('"acl": [');
    $this->assertSession()->pageTextContains('Preview body text');
    $this->assertSession()->pageTextContains('"mapping": "');
    // The source is never saved by the preview.
    $this->assertFalse($this->loadSource('articles')?->status());

    // Unknown IDs and items the administrator cannot view are refused.
    $this->submitForm(['entity_id' => '999999'], 'Preview');
    $this->assertSession()->pageTextContains('There is no item with this ID.');
    $this->submitForm(['entity_id' => (string) $hidden->id()], 'Preview');
    $this->assertSession()->pageTextContains('You are not allowed to view this item.');
    $this->assertSession()->pageTextNotContains('"source_id"');

    $permissions = ['administer webassistant connector', 'access content', 'bypass node access'];
    $this->drupalLogin($this->drupalCreateUser($permissions));
    $this->drupalGet('admin/config/services/webassistant-connector/sources/articles/preview');
    $this->submitForm(['entity_id' => (string) $hidden->id()], 'Preview');
    $this->assertSession()->pageTextContains('Not indexed: The default revision of this translation is not published. (unpublished)');
    $this->submitForm(['entity_id' => 'not valid!'], 'Preview');
    $this->assertSession()->pageTextContains('Enter a valid ID.');

    // Access to the administration requires the permission.
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet('admin/config/services/webassistant-connector/sources/articles/preview');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('admin/config/services/webassistant-connector/sources/add');
    $this->assertSession()->statusCodeEquals(403);
  }

}
