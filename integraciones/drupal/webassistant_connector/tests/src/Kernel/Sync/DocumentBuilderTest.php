<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Sync;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\webassistant_connector\Traits\CanonicalDocumentSchemaTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Plugin\WebAssistant\Normalizer\ContentEntityNormalizer;
use Drupal\webassistant_connector\Sync\AclResolver;
use Drupal\webassistant_connector\Sync\DocumentBuilder;
use Drupal\webassistant_connector\Sync\DocumentBuildResult;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Builds canonical documents of nodes (design section 8.3, amendments B1-B5).
 *
 * @group webassistant_connector
 */
#[CoversClass(DocumentBuilder::class)]
#[CoversClass(ContentEntityNormalizer::class)]
#[CoversClass(AclResolver::class)]
#[CoversClass(SourceMatcher::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class DocumentBuilderTest extends KernelTestBase {

  use CanonicalDocumentSchemaTrait;
  use ContentTypeCreationTrait;
  use EntityReferenceFieldCreationTrait;
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
    'options',
    'file',
    'image',
    'language',
    'taxonomy',
    'webassistant_connector',
    'webassistant_connector_source_test',
  ];

  /**
   * The builder under test.
   */
  private DocumentBuilder $builder;

  /**
   * The source of articles.
   */
  private SourceInterface $source;

  /**
   * Prefix of the remote source IDs.
   */
  private string $prefix;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['filter', 'node', 'user', 'language', 'webassistant_connector']);
    $this->installSchema('webassistant_connector', ['webassistant_connector_outbox', 'webassistant_connector_document']);
    $this->container->get('router.builder')->rebuild();

    ConfigurableLanguage::createFromLangcode('es')->save();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'filters' => []])->save();
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $rid) {
      Role::load($rid)->grantPermission('access content')->save();
    }
    Role::create(['id' => 'reviewer', 'label' => 'Reviewer'])->grantPermission('access content')->save();

    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    $this->createContentType(['type' => 'page', 'name' => 'Page']);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_tags', 'Tags', 'taxonomy_term', 'default', ['target_bundles' => ['tags' => 'tags']], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createEntityReferenceField('node', 'article', 'field_related', 'Related', 'node', 'default', [], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createField('field_exclude', 'boolean');
    $this->createField('field_secret', 'string');
    $this->createField('field_acl', 'string', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->createField('field_subtitle', 'string');
    $this->createField('field_url', 'string');
    $this->createField('field_image', 'image');
    $this->createField('field_level', 'list_integer', 1, [
      'allowed_values' => [1 => 'Basic', 2 => 'Advanced'],
    ]);

    $this->config('webassistant_connector.settings')
      ->set('site_id', 'site_demo')
      ->set('sync.public_base_url', 'https://www.example.com/')
      ->save();

    $this->source = Source::create([
      'id' => 'articles',
      'label' => 'Articles',
      'status' => TRUE,
      'entity_type' => 'node',
      'bundles' => ['article'],
      'knowledge_base' => 'ayuda',
      'document_type' => 'guide',
      'acl_mode' => SourceInterface::ACL_ACCESS,
      'fields' => [
        ['field' => 'title', 'role' => 'title'],
        ['field' => 'body', 'role' => 'main_content'],
        ['field' => 'field_related', 'role' => 'context', 'depth' => 2],
        ['field' => 'field_secret', 'role' => 'context'],
        ['field' => 'field_tags', 'role' => 'metadata', 'key' => 'tags'],
        ['field' => 'field_exclude', 'role' => 'exclusion'],
        ['field' => 'field_acl', 'role' => 'acl'],
      ],
    ]);
    $this->source->save();
    $this->builder = $this->container->get('webassistant_connector.document_builder');
    $this->prefix = $this->container->get('webassistant_connector.source_id_generator')->getPrefix();
  }

  /**
   * Adds a configurable field to articles.
   *
   * @param string $name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param int $cardinality
   *   The cardinality.
   * @param array<string, mixed> $settings
   *   The storage settings.
   */
  private function createField(string $name, string $type, int $cardinality = 1, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => ucfirst(str_replace(['field_', '_'], ['', ' '], $name)),
    ])->save();
  }

  /**
   * Creates an article.
   *
   * @param string $title
   *   The title.
   * @param array<string, mixed> $values
   *   Other values.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  private function article(string $title, array $values = []): NodeInterface {
    return $this->createNode($values + [
      'type' => 'article',
      'title' => $title,
      'body' => ['value' => '<p>Body of ' . $title . '</p>', 'format' => 'full_html'],
      'status' => NodeInterface::PUBLISHED,
    ]);
  }

  /**
   * Builds the documents of a node translation and validates them.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $langcode
   *   The language.
   * @param bool $preview
   *   Preview mode.
   *
   * @return \Drupal\webassistant_connector\Sync\DocumentBuildResult
   *   The result.
   *
   * @phpstan-impure
   */
  private function build(NodeInterface $node, string $langcode = 'en', bool $preview = FALSE): DocumentBuildResult {
    $result = $this->builder->build($this->source, 'node', (string) $node->id(), $langcode, $preview);
    foreach ($result->getDocuments() as $document) {
      $this->assertCanonicalDocument($document->toArray());
    }
    return $result;
  }

  /**
   * Returns the main document array of a node translation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $langcode
   *   The language.
   *
   * @return array<string, mixed>
   *   The canonical document.
   *
   * @phpstan-impure
   */
  private function document(NodeInterface $node, string $langcode = 'en'): array {
    $result = $this->build($node, $langcode);
    $this->assertNull($result->getSkipReason(), 'Unexpected skip: ' . $result->getSkipReason());
    $main = $result->getMainDocument();
    $this->assertInstanceOf(CanonicalDocument::class, $main);
    return $main->toArray();
  }

  /**
   * Common rules: IDs, locale, version, hash, URL, metadata and site.
   */
  public function testTranslationsAndCommonRules(): void {
    $tag = Term::create(['vid' => 'tags', 'name' => 'Citas']);
    $tag->save();
    $node = $this->article('Appointments', ['field_tags' => [$tag]]);
    $node->addTranslation('es', [
      'title' => 'Citas previas',
      'body' => ['value' => '<p>Cuerpo <strong>es</strong></p>', 'format' => 'full_html'],
      'status' => NodeInterface::PUBLISHED,
    ])->save();
    $uid = $this->container->get('current_user')->id();

    $en = $this->document($node);
    $this->assertSame($this->prefix . ':node:' . $node->id() . ':en', $en['source_id']);
    $this->assertSame('ayuda', $en['knowledge_base']);
    $this->assertSame('guide', $en['type']);
    $this->assertSame('en', $en['locale']);
    $this->assertSame('Appointments', $en['title']);
    $this->assertSame(['public'], $en['acl']);
    $this->assertSame('html', $en['content_format']);
    $this->assertStringStartsWith('<h1>Appointments</h1>', $en['text']);
    $this->assertStringContainsString('<p>Body of Appointments</p>', $en['text']);
    $this->assertSame(hash('sha256', $en['text']), $en['content_hash']);
    $this->assertMatchesRegularExpression('/^m1\.[0-9a-f]{16}$/', $en['version']);
    $this->assertSame('m1.' . substr($en['content_hash'], 0, 16), $en['version']);
    $this->assertLessThanOrEqual(64, strlen($en['version']));
    $this->assertSame('https://www.example.com/node/' . $node->id(), $en['canonical_url']);
    $this->assertSame(['Citas'], $en['metadata']['tags']);
    $this->assertSame([
      'entity_type' => 'node',
      'bundle' => 'article',
      'id' => (string) $node->id(),
      'uuid' => $node->uuid(),
      'langcode' => 'en',
      'source' => 'articles',
      'mapping' => substr($this->source->getMappingHash(), 0, 12),
    ], $en['metadata']['drupal']);
    $this->assertSame(CanonicalDocument::formatDate((int) $node->getChangedTime()), $en['updated_at']);
    $this->assertArrayNotHasKey('site_id', $en);
    $this->assertSame($uid, $this->container->get('current_user')->id(), 'The current user is restored after the build.');

    $es = $this->document($node, 'es');
    $this->assertSame($this->prefix . ':node:' . $node->id() . ':es', $es['source_id']);
    $this->assertSame('es', $es['locale']);
    $this->assertSame('Citas previas', $es['title']);
    $this->assertStringContainsString('<p>Cuerpo <strong>es</strong></p>', $es['text']);
    $this->assertStringStartsWith('https://www.example.com/', $es['canonical_url']);
    $this->assertStringEndsWith('/node/' . $node->id(), $es['canonical_url']);
    $this->assertNotSame($en['version'], $es['version']);

    // The version only depends on the mapping version and the text.
    $this->assertSame($en['version'], $this->document($node)['version']);
    $this->source->set('mapping_version', 2)->set('site_scoped', TRUE)->save();
    $en2 = $this->document($node);
    $this->assertSame('m2.' . substr($en2['content_hash'], 0, 16), $en2['version']);
    $this->assertSame('site_demo', $en2['site_id']);

    // Missing translation, excluded language, deleted entity.
    $this->assertSame(DocumentBuildResult::SKIP_TRANSLATION_MISSING, $this->build($node, 'fr')->getSkipReason());
    $this->source->set('langcodes', ['en'])->save();
    $this->assertSame(DocumentBuildResult::SKIP_LANGUAGE_EXCLUDED, $this->build($node, 'es')->getSkipReason());
    $this->assertNull($this->build($node)->getSkipReason());
    $id = (string) $node->id();
    $node->delete();
    $this->assertSame(DocumentBuildResult::SKIP_DELETED, $this->builder->build($this->source, 'node', $id, 'en')->getSkipReason());
  }

  /**
   * Publication: unpublished translations and forward revisions.
   */
  public function testPublicationAndRevisions(): void {
    $node = $this->article('Published title');
    $node->addTranslation('es', ['title' => 'Título', 'status' => NodeInterface::NOT_PUBLISHED])->save();
    $this->assertSame(DocumentBuildResult::SKIP_UNPUBLISHED, $this->build($node, 'es')->getSkipReason());
    $this->assertNull($this->build($node)->getSkipReason());

    // A forward (non-default) revision is never indexed.
    $draft = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $draft->setTitle('Draft title');
    $draft->setNewRevision();
    $draft->isDefaultRevision(FALSE);
    $draft->save();
    $this->assertSame('Published title', $this->document($node)['title']);

    $unpublished = $this->article('Hidden', ['status' => NodeInterface::NOT_PUBLISHED]);
    $this->assertSame(DocumentBuildResult::SKIP_UNPUBLISHED, $this->build($unpublished)->getSkipReason());

    // The exclusion role skips truthy values only.
    $excluded = $this->article('Excluded', ['field_exclude' => TRUE]);
    $this->assertSame(DocumentBuildResult::SKIP_EXCLUDED, $this->build($excluded)->getSkipReason());
    $this->assertNull($this->build($this->article('Included', ['field_exclude' => FALSE]))->getSkipReason());

    // Other bundles and disabled sources are not applicable (preview aside).
    $page = $this->createNode(['type' => 'page', 'title' => 'Page']);
    $this->assertSame(DocumentBuildResult::SKIP_NOT_APPLICABLE, $this->build($page)->getSkipReason());
    $this->source->setStatus(FALSE)->save();
    $this->assertSame(DocumentBuildResult::SKIP_NOT_APPLICABLE, $this->build($node)->getSkipReason());
    $this->assertNull($this->build($node, 'en', TRUE)->getSkipReason());
  }

  /**
   * Escaped strings, metadata scalars, image, date and URL roles.
   */
  public function testFieldRoles(): void {
    $fields = $this->source->getFields();
    $fields[] = ['field' => 'field_subtitle', 'role' => 'context'];
    $fields[] = ['field' => 'promote', 'role' => 'metadata', 'key' => 'promoted'];
    $fields[] = ['field' => 'field_image', 'role' => 'image'];
    $fields[] = ['field' => 'created', 'role' => 'date'];
    $fields[] = ['field' => 'field_url', 'role' => 'url'];
    $fields[] = ['field' => 'field_level', 'role' => 'metadata', 'key' => 'level'];
    $this->source->set('fields', $fields)->save();

    $file = File::create([
      'uri' => 'public://photo.png',
      'filename' => 'photo.png',
      'filemime' => 'image/png',
      'status' => 1,
    ]);
    $file->save();
    $node = $this->article('Roles', [
      'field_subtitle' => '<b>Bold</b> & "co"',
      'promote' => TRUE,
      'field_image' => ['target_id' => $file->id(), 'alt' => 'A photo'],
      'created' => 1700000000,
      'field_url' => '/custom/landing',
      'field_level' => 2,
    ]);
    $document = $this->document($node);
    $this->assertStringContainsString('<section><h2>Subtitle</h2><p>&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;co&quot;</p></section>', $document['text']);
    $this->assertStringNotContainsString('<b>Bold</b>', $document['text']);
    $this->assertTrue($document['metadata']['promoted']);
    $this->assertSame('Advanced', $document['metadata']['level']);
    $this->assertStringStartsWith('https://www.example.com/', $document['metadata']['image_url']);
    $this->assertStringEndsWith('/photo.png', $document['metadata']['image_url']);
    $this->assertSame('A photo', $document['metadata']['image_alt']);
    $this->assertSame('2023-11-14T22:13:20+00:00', $document['updated_at']);
    $this->assertSame('2023-11-14T22:13:20+00:00', $document['created_at']);
    $this->assertSame('https://www.example.com/custom/landing', $document['canonical_url']);

    // Values that are not URLs fall back to the entity link, with a warning.
    $node->set('field_url', 'javascript:alert(1)')->save();
    $result = $this->build($node);
    $this->assertSame('https://www.example.com/node/' . $node->id(), $result->getMainDocument()?->canonicalUrl);
    $this->assertNotEmpty(array_filter($result->getWarnings(), static fn (string $warning): bool => str_starts_with($warning, 'field_url ignored')));
  }

  /**
   * ACL modes, role-restricted content and the alter event (B4, B5).
   */
  public function testAcl(): void {
    $public = $this->article('Public page');
    $restricted = $this->article('Restricted page');
    $this->assertSame(['public'], $this->document($public)['acl']);
    $this->assertSame(['role:reviewer'], $this->document($restricted)['acl']);

    // Subscribers may add principals; invalid ones are dropped.
    $this->assertSame(['group:42', 'public'], $this->document($this->article('Public [group]'))['acl']);

    Role::load(RoleInterface::ANONYMOUS_ID)->revokePermission('access content')->save();
    $this->assertSame(['authenticated'], $this->document($public)['acl']);

    // Without any role that can view it, the item is not indexable.
    Role::load('reviewer')->delete();
    $this->assertSame(DocumentBuildResult::SKIP_NO_ACCESS, $this->build($restricted)->getSkipReason());

    // Declared modes ignore Drupal access.
    $this->source->set('acl_mode', SourceInterface::ACL_PUBLIC)->save();
    $this->assertSame(['public'], $this->document($restricted)['acl']);
    $this->source->set('acl_mode', SourceInterface::ACL_AUTHENTICATED)->save();
    $this->assertSame(['authenticated'], $this->document($restricted)['acl']);

    // Field mode: valid principals only, empty means not indexable.
    $this->source->set('acl_mode', SourceInterface::ACL_FIELD)->save();
    $withAcl = $this->article('ACL field', ['field_acl' => ['role:editor', 'not a principal', 'group:7', ' public ']]);
    $this->assertSame(['group:7', 'public', 'role:editor'], $this->document($withAcl)['acl']);
    $this->assertSame(DocumentBuildResult::SKIP_NO_ACCESS, $this->build($this->article('No ACL'))->getSkipReason());

    $this->assertSame('role:editor', AclResolver::rolePrincipal('editor'));
    $this->assertNull(AclResolver::rolePrincipal('bad role'));
    $this->assertNull(AclResolver::rolePrincipal(RoleInterface::AUTHENTICATED_ID));
  }

  /**
   * Fields every principal cannot view are dropped with a warning (B2).
   */
  public function testFieldAccess(): void {
    $public = $this->article('Public secret', ['field_secret' => 'Hidden value']);
    $result = $this->build($public);
    $text = $result->getMainDocument()->text ?? '';
    $this->assertStringNotContainsString('Hidden value', $text);
    $this->assertNotEmpty(array_filter($result->getWarnings(), static fn (string $warning): bool => str_starts_with($warning, 'field_secret dropped')));

    // A reviewer-only document is rendered for reviewers: the field stays.
    $restricted = $this->article('Restricted secret', ['field_secret' => 'Reviewer value']);
    $result = $this->build($restricted);
    $this->assertSame(['role:reviewer'], $result->getMainDocument()?->acl);
    $this->assertStringContainsString('Reviewer value', $result->getMainDocument()->text ?? '');
    $this->assertSame([], $result->getWarnings());
  }

  /**
   * References: depth, cycles, unpublished and restricted targets (B3).
   */
  public function testReferences(): void {
    $beta = $this->article('Beta');
    $hidden = $this->article('Gamma', ['status' => NodeInterface::NOT_PUBLISHED]);
    $restricted = $this->article('Restricted delta');
    $alpha = $this->article('Alpha', ['field_related' => [$beta, $hidden, $restricted]]);
    // Cycles: alpha references itself and beta references alpha back.
    $alpha->set('field_related', [$beta, $hidden, $restricted, $alpha])->save();
    $beta->set('field_related', [$alpha])->save();

    $result = $this->build($alpha);
    $text = $result->getMainDocument()->text ?? '';
    // Depth 2: the content of Beta, and the labels of its references.
    $this->assertStringContainsString('<section><h3>Beta</h3>', $text);
    $this->assertStringContainsString('<p>Body of Beta</p>', $text);
    // Alpha itself (a cycle) is only labelled.
    $this->assertStringContainsString('<li>Alpha</li>', $text);
    $this->assertStringNotContainsString('<h3>Alpha</h3>', $text);
    $this->assertSame(1, substr_count($text, '<p>Body of Alpha</p>'));
    // Unpublished and restricted references are not emitted.
    $this->assertStringNotContainsString('Gamma', $text);
    $this->assertStringNotContainsString('Restricted delta', $text);
    $this->assertNotEmpty(array_filter($result->getWarnings(), static fn (string $warning): bool => str_contains($warning, 'node ' . $restricted->id() . ' in field_related dropped')));

    // Depth 1: labels only.
    $fields = $this->source->getFields();
    $fields[2]['depth'] = 1;
    $this->source->set('fields', $fields)->save();
    $text = $this->build($alpha)->getMainDocument()->text ?? '';
    $this->assertStringContainsString('<li>Beta</li>', $text);
    $this->assertStringNotContainsString('Body of Beta', $text);

    // Allowed bundles of the reference.
    $fields[2]['target_bundles'] = ['page'];
    $this->source->set('fields', $fields)->save();
    $this->assertStringNotContainsString('Beta', $this->build($alpha)->getMainDocument()->text ?? '');
  }

  /**
   * The matcher finds enabled sources by bundle and follows config changes.
   */
  public function testSourceMatcher(): void {
    $matcher = $this->container->get('webassistant_connector.source_matcher');
    $article = $this->article('Matched');
    $page = $this->createNode(['type' => 'page', 'title' => 'Not matched']);
    $this->assertSame(['articles'], array_keys($matcher->forEntity($article)));
    $this->assertSame([], $matcher->forEntity($page));
    $this->assertSame(['ayuda'], $matcher->getKnowledgeBases());
    $this->assertTrue($matcher->hasSourcesFor('node'));

    Source::create([
      'id' => 'pages',
      'label' => 'Pages',
      'entity_type' => 'node',
      'bundles' => [],
      'knowledge_base' => 'web',
      'document_type' => 'page',
    ])->save();
    $this->assertSame(['articles', 'pages'], array_keys($matcher->forEntity($article)));
    $this->assertSame(['pages'], array_keys($matcher->forEntity($page)));

    $this->source->setStatus(FALSE)->save();
    $this->assertSame(['pages'], array_keys($matcher->forEntity($article)));
    $this->assertSame(['web'], $matcher->getKnowledgeBases());
  }

  /**
   * Removing a mapped field or a bundle disables the source (amendment A9).
   */
  public function testDependencyRemoval(): void {
    $dependencies = $this->source->getDependencies();
    $this->assertContains('field.storage.node.field_secret', $dependencies['config']);
    $this->assertContains('node.type.article', $dependencies['config']);
    $this->assertContains('node', $dependencies['module']);

    FieldStorageConfig::loadByName('node', 'field_secret')->delete();
    $source = Source::load('articles');
    $this->assertInstanceOf(SourceInterface::class, $source);
    $this->assertFalse($source->status());
    $this->assertNotContains('field_secret', array_column($source->getFields(), 'field'));

    // Removing the only bundle deletes the source (an empty list would
    // widen it to every bundle).
    $this->container->get('entity_type.manager')->getStorage('node_type')->load('article')->delete();
    $this->assertNull(Source::load('articles'));
  }

}
