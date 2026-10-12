<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Sync;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\user\Entity\Role;
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
    $this->assertSame(AclResolver::SYNTHETIC_UID, (int) $authenticated->id());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE], $authenticated->getRoles());

    $editor = $this->resolver->renderAccount(['group:4', 'role:editor']);
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE, 'editor'], $editor->getRoles());
    $this->assertNotSame(1, (int) $editor->id());

    $accounts = $this->resolver->principalAccounts(['public', 'role:editor', 'role:reviewer', 'group:4', 'user:dabc']);
    $this->assertCount(4, $accounts);
    $this->assertTrue($accounts[0]->isAnonymous());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE, 'editor'], $accounts[1]->getRoles());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE, 'reviewer'], $accounts[2]->getRoles());
    $this->assertSame([AccountInterface::AUTHENTICATED_ROLE], $accounts[3]->getRoles());
    $this->assertCount(1, $this->resolver->principalAccounts([]));
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
