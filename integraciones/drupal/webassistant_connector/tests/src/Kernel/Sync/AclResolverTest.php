<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Sync;

use Drupal\Core\Session\AccountInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\AclResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ACL resolver with core access only (amendments B1 and B4).
 *
 * @group webassistant_connector
 */
#[CoversClass(AclResolver::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class AclResolverTest extends KernelTestBase {

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
    'webassistant_connector',
  ];

  /**
   * The resolver under test.
   */
  private AclResolver $resolver;

  /**
   * A source computing the ACL from Drupal access.
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
    $this->installConfig(['filter', 'node', 'user', 'webassistant_connector']);
    $this->installSchema('webassistant_connector', ['webassistant_connector_outbox', 'webassistant_connector_document']);
    $this->createContentType(['type' => 'article']);
    $this->source = Source::create([
      'id' => 'articles',
      'label' => 'Articles',
      'entity_type' => 'node',
      'knowledge_base' => 'ayuda',
      'document_type' => 'page',
    ]);
    $this->resolver = $this->container->get('webassistant_connector.acl_resolver');
  }

  /**
   * Published nodes: public, authenticated, roles, admin roles.
   */
  public function testAccessMode(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'One', 'status' => NodeInterface::PUBLISHED]);
    $other = $this->createNode(['type' => 'article', 'title' => 'Two', 'status' => NodeInterface::PUBLISHED]);
    Role::create(['id' => 'editor', 'label' => 'Editor'])->grantPermission('access content')->save();
    Role::create(['id' => 'admin', 'label' => 'Admin', 'is_admin' => TRUE])->save();
    Role::create(['id' => 'nobody', 'label' => 'Nobody'])->save();

    // Nobody has "access content": only roles holding it (or admin) count.
    $this->assertSame(['role:admin', 'role:editor'], $this->resolver->resolve($this->source, $node));

    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access content')->save();
    $this->assertSame(['authenticated'], $this->resolver->resolve($this->source, $node));
    $this->assertSame(['authenticated'], $this->resolver->resolve($this->source, $other));

    // Memoized results never hide permission changes.
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    $this->assertSame(['public'], $this->resolver->resolve($this->source, $other));
    Role::load(RoleInterface::ANONYMOUS_ID)->revokePermission('access content')->save();
    Role::load(RoleInterface::AUTHENTICATED_ID)->revokePermission('access content')->save();
    Role::load('editor')->revokePermission('access content')->save();
    $this->assertSame(['role:admin'], $this->resolver->resolve($this->source, $node));
  }

  /**
   * Synthetic principals used to render and to check field access (B1).
   */
  public function testPrincipalAccounts(): void {
    $anonymous = $this->resolver->renderAccount(['public', 'role:editor']);
    $this->assertTrue($anonymous->isAnonymous());

    $authenticated = $this->resolver->renderAccount(['authenticated']);
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE], $authenticated->getRoles());
    $this->assertGreaterThan(AclResolver::SYNTHETIC_UID - AclResolver::SYNTHETIC_UID_SPAN, (int) $authenticated->id());

    // Group and user principals: an authenticated account without roles.
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE], $this->resolver->renderAccount(['group:4', 'role:editor'])->getRoles());

    // Roles: the one with the fewest permissions, admin roles last.
    Role::create(['id' => 'admin', 'label' => 'Admin', 'is_admin' => TRUE])->save();
    Role::create(['id' => 'editor', 'label' => 'Editor'])->grantPermission('access content')->grantPermission('access user profiles')->save();
    Role::create(['id' => 'reviewer', 'label' => 'Reviewer'])->grantPermission('access content')->save();
    $reviewer = $this->resolver->renderAccount(['role:admin', 'role:editor', 'role:reviewer']);
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE, 'reviewer'], $reviewer->getRoles());
    $this->assertSame(['authenticated', 'editor'], $this->resolver->renderAccount(['role:admin', 'role:editor'])->getRoles());
    $this->assertNotSame(1, (int) $reviewer->id());

    // Every role set has its own stable user ID.
    $ids = array_map(static fn (AccountInterface $account): int => (int) $account->id(), [
      $authenticated,
      $reviewer,
      $this->resolver->authenticatedAccount(['editor']),
      $this->resolver->authenticatedAccount(['admin']),
    ]);
    $this->assertCount(4, array_unique($ids));
    $this->assertSame((int) $reviewer->id(), (int) $this->resolver->authenticatedAccount(['reviewer', 'authenticated'])->id());

    $accounts = $this->resolver->principalAccounts(['public', 'role:editor', 'role:reviewer', 'group:4', 'user:dabc']);
    $this->assertCount(4, $accounts);
    $this->assertTrue($accounts[0]->isAnonymous());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE, 'editor'], $accounts[1]->getRoles());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE, 'reviewer'], $accounts[2]->getRoles());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE], $accounts[3]->getRoles());
    $this->assertCount(1, $this->resolver->principalAccounts([]));
  }

  /**
   * Each principal is checked with its own permissions while rendering.
   *
   * Core only recomputes the permissions of an account whose user ID differs
   * from the current user's: with a shared ID, the render account (current
   * user while normalizing) would lend its permissions to the others.
   */
  public function testPrincipalsKeepTheirPermissionsWhileRendering(): void {
    Role::create(['id' => 'administrator', 'label' => 'Admin', 'is_admin' => TRUE])->save();
    Role::create(['id' => 'staff', 'label' => 'Staff'])->grantPermission('access content')->save();
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($this->resolver->authenticatedAccount(['administrator']));
    try {
      $this->assertFalse($this->resolver->authenticatedAccount(['staff'])->hasPermission('access user profiles'));
      $this->assertFalse($this->resolver->authenticatedAccount()->hasPermission('access content'));
    }
    finally {
      $switcher->switchBack();
    }

    // Private site: a reference that staff cannot view never reaches a
    // document whose ACL includes role:staff (amendment B3).
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML', 'filters' => []])->save();
    $this->config('webassistant_connector.settings')->set('sync.public_base_url', 'https://www.example.com/')->save();
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $author = User::create(['name' => 'hidden_author_name', 'status' => 1]);
    $author->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Internal document',
      'uid' => $author->id(),
      'status' => NodeInterface::PUBLISHED,
      'body' => ['value' => '<p>Internal body</p>', 'format' => 'full_html'],
    ]);
    $node->save();
    $source = Source::create([
      'id' => 'internal',
      'label' => 'Internal',
      'entity_type' => 'node',
      'bundles' => ['article'],
      'knowledge_base' => 'ayuda',
      'document_type' => 'guide',
      'acl_mode' => SourceInterface::ACL_ACCESS,
      'fields' => [
        ['field' => 'title', 'role' => 'title'],
        ['field' => 'body', 'role' => 'main_content'],
        ['field' => 'uid', 'role' => 'context', 'depth' => 2],
      ],
    ]);
    $source->save();
    $result = $this->container->get('webassistant_connector.document_builder')->build($source, 'node', $node->id(), 'en');
    $this->assertFalse($result->isSkipped(), (string) $result->getSkipReason());
    $document = $result->getDocuments()[0];
    $this->assertSame(['role:administrator', 'role:staff'], $document->acl);
    $this->assertStringContainsString('Internal body', (string) $document->text);
    $this->assertStringNotContainsString('hidden_author_name', (string) $document->text);
  }

  /**
   * Declared modes and invalid principals (B5).
   */
  public function testDeclaredModes(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'Declared', 'status' => NodeInterface::PUBLISHED]);
    $this->source->set('acl_mode', SourceInterface::ACL_PUBLIC);
    $this->assertSame(['public'], $this->resolver->resolve($this->source, $node));
    $this->source->set('acl_mode', SourceInterface::ACL_AUTHENTICATED);
    $this->assertSame(['authenticated'], $this->resolver->resolve($this->source, $node));
    // Field mode without an acl field: nobody.
    $this->source->set('acl_mode', SourceInterface::ACL_FIELD);
    $this->assertSame([], $this->resolver->resolve($this->source, $node));
  }

}
