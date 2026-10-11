<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\HostAction;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\webassistant_connector\HostAction\HostActionDispatcher;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Host action dispatcher and core host action plugins.
 *
 * @group webassistant_connector
 */
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class HostActionDispatcherTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

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
   * The dispatcher under test.
   */
  private HostActionDispatcher $dispatcher;

  /**
   * An editor with content actions.
   */
  private UserInterface $editor;

  /**
   * Counter for unique execution IDs.
   */
  private int $executions = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'user', 'webassistant_connector']);
    $this->container->get('router.builder')->rebuild();
    $this->config('user.role.' . RoleInterface::AUTHENTICATED_ID)->set('permissions', [])->save();

    // User 1 must not be one of the users under test.
    $this->createUser();
    $this->createContentType(['type' => 'article', 'name' => 'Artículo']);
    $this->createContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->createContentType(['type' => 'faq', 'name' => 'Q&A']);

    $editor = $this->createUser([
      'use webassistant connector',
      'use webassistant content actions',
      'access content',
      'create article content',
      'create faq content',
      'edit any article content',
      'edit own page content',
    ]);
    $this->assertInstanceOf(UserInterface::class, $editor);
    $this->editor = $editor;
    $this->dispatcher = $this->container->get('webassistant_connector.host_action_dispatcher');
  }

  /**
   * Unknown names are 404 even when the name is well formed.
   */
  public function testUnknownAction(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->dispatch('content.delete', [], $this->editor);
  }

  /**
   * Invalid payloads and name mismatches are 400.
   */
  public function testInvalidPayload(): void {
    $request = $this->newRequest();
    $this->setCurrentUser($this->editor);

    $payload = $this->payload('content.create', ['content_type' => 'article']);
    $payload['version'] = '2';
    $this->assertHttpException(BadRequestHttpException::class, fn () => $this->dispatcher->dispatch('content.create', $payload, $this->editor, $request));

    $payload = $this->payload('content.edit', ['content_title' => 'x']);
    $this->assertHttpException(BadRequestHttpException::class, fn () => $this->dispatcher->dispatch('content.create', $payload, $this->editor, $request));

    $payload = $this->payload('content.create', ['content_type' => 'article']);
    $payload['expires_at'] = '2000-01-01T00:00:00Z';
    $this->assertHttpException(BadRequestHttpException::class, fn () => $this->dispatcher->dispatch('content.create', $payload, $this->editor, $request));

    $payload = $this->payload('content.create', ['content_type' => 'article']);
    $payload['execution_id'] = 'act 1';
    $this->assertHttpException(BadRequestHttpException::class, fn () => $this->dispatcher->dispatch('content.create', $payload, $this->editor, $request));
  }

  /**
   * Plugin access is enforced: permission of the action.
   */
  public function testAccessDenied(): void {
    $reader = $this->createUser(['use webassistant connector', 'access content', 'create article content']);
    $this->assertInstanceOf(UserInterface::class, $reader);
    $this->expectException(AccessDeniedHttpException::class);
    $this->dispatch('content.create', ['content_type' => 'article'], $reader);
  }

  /**
   * Parameters are validated by the plugins: failed results, safe messages.
   */
  public function testParameterValidation(): void {
    $result = $this->dispatch('content.create', [], $this->editor);
    $this->assertSame(['status' => 'failed', 'message' => 'The parameter content_type is required.'], $result->toArray());

    $result = $this->dispatch('content.create', ['content_type' => 'article', 'foo' => 'bar'], $this->editor);
    $this->assertSame('Unexpected parameter foo.', $result->message);

    $result = $this->dispatch('content.create', ['content_type' => str_repeat('a', 129)], $this->editor);
    $this->assertSame('The parameter content_type is not valid.', $result->message);

    $result = $this->dispatch('content.create', ['content_type' => ['article']], $this->editor);
    $this->assertSame('The parameter content_type must be a text.', $result->message);

    $result = $this->dispatch('content.edit', ['content_type' => 'article'], $this->editor);
    $this->assertSame('The parameter content_title is required.', $result->message);
  }

  /**
   * The same execution is replayed only for the same user and session.
   */
  public function testIdempotentReplay(): void {
    $request = $this->newRequest();
    $first = $this->dispatch('content.create', ['content_type' => 'article'], $this->editor, $request, 'act_same');
    $this->assertTrue($first->isSucceeded());
    $this->assertArrayNotHasKey('replayed', $first->data);

    $second = $this->dispatch('content.create', ['content_type' => 'article'], $this->editor, $request, 'act_same');
    $this->assertSame($first->data + ['replayed' => TRUE], $second->data);
    $this->assertSame($first->redirect, $second->redirect);
    $this->assertSame($first->message, $second->message);

    // Replays return the stored result even if the parameters differ.
    $third = $this->dispatch('content.create', ['content_type' => 'faq'], $this->editor, $request, 'act_same');
    $this->assertTrue($third->data['replayed']);
    $this->assertSame($first->redirect, $third->redirect);

    // Another user with the same execution ID and session gets no replay.
    $other = $this->createUser([
      'use webassistant connector',
      'use webassistant content actions',
      'access content',
      'create faq content',
    ]);
    $this->assertInstanceOf(UserInterface::class, $other);
    $result = $this->dispatch('content.create', ['content_type' => 'faq'], $other, $request, 'act_same');
    $this->assertArrayNotHasKey('replayed', $result->data);
    $this->assertStringEndsWith('/node/add/faq', (string) $result->redirect);

    // The same user in another session gets no replay either.
    $result = $this->dispatch('content.create', ['content_type' => 'faq'], $this->editor, $this->newRequest(), 'act_same');
    $this->assertArrayNotHasKey('replayed', $result->data);
    $this->assertStringEndsWith('/node/add/faq', (string) $result->redirect);

    // Failed business results are replayed too.
    $failed = $this->dispatch('content.create', ['content_type' => 'event'], $this->editor, $request, 'act_failed');
    $this->assertFalse($failed->isSucceeded());
    $replayed = $this->dispatch('content.create', ['content_type' => 'event'], $this->editor, $request, 'act_failed');
    $this->assertFalse($replayed->isSucceeded());
    $this->assertTrue($replayed->data['replayed']);
  }

  /**
   * Anonymous executions start a session and are bound to it.
   */
  public function testAnonymousSession(): void {
    $role = Role::load(RoleInterface::ANONYMOUS_ID);
    $this->assertInstanceOf(RoleInterface::class, $role);
    $this->grantPermissions($role, ['use webassistant connector', 'use webassistant profile actions']);
    $anonymous = new AnonymousUserSession();
    $request = $this->newRequest();
    $result = $this->dispatch('user.profile', [], $anonymous, $request, 'act_anon');
    $this->assertTrue($result->isSucceeded());
    $this->assertSame(['login_required' => TRUE], $result->data);
    $this->assertStringContainsString('/user/login?destination=', (string) $result->redirect);
    $this->assertIsString($request->getSession()->get(HostActionDispatcher::SESSION_KEY));

    $replayed = $this->dispatch('user.profile', [], $anonymous, $request, 'act_anon');
    $this->assertTrue($replayed->data['replayed']);

    // Another anonymous visitor (another session) does not get the replay.
    $result = $this->dispatch('user.profile', [], $anonymous, $this->newRequest(), 'act_anon');
    $this->assertArrayNotHasKey('replayed', $result->data);
  }

  /**
   * A running duplicate is a conflict (409).
   */
  public function testConcurrentDuplicate(): void {
    $request = $this->newRequest();
    $nonce = str_repeat('n', 43);
    $request->getSession()->set(HostActionDispatcher::SESSION_KEY, $nonce);
    $key = hash('sha256', implode('|', [$this->editor->id(), $nonce, 'content.create', 'act_running']));
    /** @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $factory */
    $factory = $this->container->get('keyvalue.expirable');
    $factory->get(HostActionDispatcher::IDEMPOTENCY_COLLECTION)
      ->setWithExpire($key, ['state' => 'running', 'owner' => 'other', 'started' => 1], 60);

    $this->expectException(ConflictHttpException::class);
    $this->dispatch('content.create', ['content_type' => 'article'], $this->editor, $request, 'act_running');
  }

  /**
   * Host actions are rate limited per user.
   */
  public function testFlood(): void {
    $request = $this->newRequest();
    for ($i = 0; $i < HostActionDispatcher::FLOOD_THRESHOLD; $i++) {
      $this->dispatch('content.create', ['content_type' => 'article'], $this->editor, $request);
    }
    $this->expectException(TooManyRequestsHttpException::class);
    $this->dispatch('content.create', ['content_type' => 'article'], $this->editor, $request);
  }

  /**
   * Content types are resolved by machine name or label among creatable ones.
   */
  public function testContentCreateBundleResolution(): void {
    $result = $this->dispatch('content.create', ['content_type' => 'article', 'content_title' => 'Hello'], $this->editor);
    $this->assertTrue($result->isSucceeded());
    $this->assertStringEndsWith('/node/add/article', (string) $result->redirect);
    $this->assertStringStartsWith('/', (string) $result->redirect);
    $this->assertSame('article', $result->data['content_type']);
    $this->assertSame('Hello', $result->data['content_title']);

    foreach (['Artículo', 'ARTICULO', 'articulo'] as $requested) {
      $result = $this->dispatch('content.create', ['content_type' => $requested], $this->editor);
      $this->assertStringEndsWith('/node/add/article', (string) $result->redirect, $requested);
    }

    // Messages are plain text: the label placeholder is not HTML-escaped.
    $result = $this->dispatch('content.create', ['content_type' => 'Q&A'], $this->editor);
    $this->assertStringEndsWith('/node/add/faq', (string) $result->redirect);
    $this->assertSame('Opening the form to create a new Q&A.', $result->message);

    // The editor cannot create pages: not offered, available types listed.
    $result = $this->dispatch('content.create', ['content_type' => 'Basic page'], $this->editor);
    $this->assertFalse($result->isSucceeded());
    $this->assertNull($result->redirect);
    $this->assertSame([
      ['id' => 'article', 'label' => 'Artículo'],
      ['id' => 'faq', 'label' => 'Q&A'],
    ], $result->data['content_types']);
    $this->assertSame('The content type "Basic page" does not exist or you are not allowed to create it.', $result->message);

    // Without any creatable type the action fails cleanly.
    $limited = $this->createUser(['use webassistant connector', 'use webassistant content actions']);
    $this->assertInstanceOf(UserInterface::class, $limited);
    $result = $this->dispatch('content.create', ['content_type' => 'article'], $limited);
    $this->assertSame(['status' => 'failed', 'message' => 'You are not allowed to create content.'], $result->toArray());
  }

  /**
   * Title search returns candidates the user can view and update.
   */
  public function testContentEditCandidatesAndAccess(): void {
    $author = $this->createUser(['access content']);
    $this->assertInstanceOf(UserInterface::class, $author);
    $report2024 = $this->createNode(['type' => 'article', 'title' => 'Annual report 2024', 'uid' => $author->id()]);
    $report2025 = $this->createNode(['type' => 'article', 'title' => 'Annual report 2025', 'uid' => $author->id()]);
    // A page of another user: the editor may only edit their own pages.
    $this->createNode(['type' => 'page', 'title' => 'Annual report draft', 'uid' => $author->id()]);
    $ownPage = $this->createNode(['type' => 'page', 'title' => 'Annual report summary', 'uid' => $this->editor->id()]);
    // An unpublished article of another user: updatable but not viewable.
    $hidden = $this->createNode([
      'type' => 'article',
      'title' => 'Annual report secret',
      'uid' => $author->id(),
      'status' => 0,
    ]);

    $result = $this->dispatch('content.edit', ['content_title' => 'annual report'], $this->editor);
    $this->assertFalse($result->isSucceeded());
    $this->assertSame(3, $result->data['total']);
    $ids = array_column($result->data['candidates'], 'content_id');
    sort($ids);
    $this->assertSame([(string) $report2024->id(), (string) $report2025->id(), (string) $ownPage->id()], $ids);
    foreach ($result->data['candidates'] as $candidate) {
      $this->assertSame(['content_id', 'title', 'url'], array_keys($candidate));
      $this->assertStringEndsWith('/node/' . $candidate['content_id'] . '/edit', $candidate['url']);
      $this->assertStringStartsWith('Annual report', $candidate['title']);
    }

    // An exact title wins over partial matches.
    $result = $this->dispatch('content.edit', ['content_title' => 'annual report 2024'], $this->editor);
    $this->assertTrue($result->isSucceeded());
    $this->assertStringEndsWith('/node/' . $report2024->id() . '/edit', (string) $result->redirect);
    $this->assertSame((string) $report2024->id(), $result->data['content_id']);

    // The content type narrows the search (by label).
    $result = $this->dispatch('content.edit', ['content_title' => 'Annual report', 'content_type' => 'basic page'], $this->editor);
    $this->assertStringEndsWith('/node/' . $ownPage->id() . '/edit', (string) $result->redirect);

    $result = $this->dispatch('content.edit', ['content_title' => 'Annual report', 'content_type' => 'event'], $this->editor);
    $this->assertSame('The content type "event" was not found.', $result->message);

    $result = $this->dispatch('content.edit', ['content_title' => 'Nothing like this'], $this->editor);
    $this->assertFalse($result->isSucceeded());
    $this->assertSame('No content titled "Nothing like this" was found that you can edit.', $result->message);

    // Hidden nodes are never disclosed, by title or by ID.
    $result = $this->dispatch('content.edit', ['content_title' => 'Annual report secret'], $this->editor);
    $this->assertFalse($result->isSucceeded());
    $this->assertArrayNotHasKey('candidates', $result->data);
    $result = $this->dispatch('content.edit', ['content_id' => (int) $hidden->id(), 'content_title' => 'x'], $this->editor);
    $this->assertSame('The requested content was not found.', $result->message);
    $result = $this->dispatch('content.edit', ['content_id' => '999999'], $this->editor);
    $this->assertSame('The requested content was not found.', $result->message);
    $result = $this->dispatch('content.edit', ['content_id' => 'not-an-id'], $this->editor);
    $this->assertSame('The requested content was not found.', $result->message);

    // Viewable but not updatable.
    $draft = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['title' => 'Annual report draft']);
    $draft = reset($draft);
    $this->assertNotFalse($draft);
    $result = $this->dispatch('content.edit', ['content_id' => (string) $draft->id()], $this->editor);
    $this->assertSame('You are not allowed to edit this content.', $result->message);

    // By ID and by UUID.
    $result = $this->dispatch('content.edit', ['content_id' => (int) $report2025->id()], $this->editor);
    $this->assertStringEndsWith('/node/' . $report2025->id() . '/edit', (string) $result->redirect);
    $result = $this->dispatch('content.edit', ['content_id' => $report2025->uuid()], $this->editor);
    $this->assertStringEndsWith('/node/' . $report2025->id() . '/edit', (string) $result->redirect);

    // At most five candidates are returned.
    for ($i = 1; $i <= 7; $i++) {
      $this->createNode(['type' => 'article', 'title' => 'Bulk item ' . $i, 'uid' => $author->id()]);
    }
    $result = $this->dispatch('content.edit', ['content_title' => 'bulk item'], $this->editor);
    $this->assertCount(5, $result->data['candidates']);
    $this->assertSame(7, $result->data['total']);
  }

  /**
   * With node installed, content actions are offered by permission.
   */
  public function testRegistryWithNode(): void {
    $registry = $this->container->get('webassistant_connector.host_action_registry');
    $this->assertSame(['content.create', 'content.edit'], $registry->availableFor($this->editor));
    $member = $this->createUser(['use webassistant connector', 'use webassistant profile actions']);
    $this->assertInstanceOf(UserInterface::class, $member);
    $this->assertSame(['user.profile'], $registry->availableFor($member));
  }

  /**
   * The profile action redirects to the edit form or to the login form.
   */
  public function testUserProfile(): void {
    $member = $this->createUser(['use webassistant connector', 'use webassistant profile actions']);
    $this->assertInstanceOf(UserInterface::class, $member);
    $result = $this->dispatch('user.profile', [], $member);
    $this->assertTrue($result->isSucceeded());
    $this->assertStringEndsWith('/user/' . $member->id() . '/edit', (string) $result->redirect);
    $this->assertSame(['login_required' => FALSE], $result->data);

    $result = $this->dispatch('user.profile', ['user_id' => 1], $member);
    $this->assertSame('Unexpected parameter user_id.', $result->message);

    $this->expectException(AccessDeniedHttpException::class);
    $this->dispatch('user.profile', [], $this->editor);
  }

  /**
   * Builds a POST request with its own session.
   */
  private function newRequest(): Request {
    $request = Request::create('http://localhost/webassistant/host-action/x', 'POST');
    $request->setSession(new Session(new MockArraySessionStorage()));
    return $request;
  }

  /**
   * Builds a valid host action payload.
   *
   * @param string $name
   *   The action name.
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string|null $executionId
   *   The execution ID (unique if NULL).
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function payload(string $name, array $params, ?string $executionId = NULL): array {
    $executionId ??= 'act_' . (++$this->executions);
    return [
      'version' => '1',
      'name' => $name,
      'execution_id' => $executionId,
      'correlation_id' => $executionId,
      'skill' => 'test',
      'action' => 'test',
      'run_id' => NULL,
      'params' => $params,
      'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 120),
    ];
  }

  /**
   * Dispatches an action as the given account.
   *
   * @param string $name
   *   The action name.
   * @param array<string, mixed> $params
   *   The parameters.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account (also set as current user for entity queries).
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request (a new one with its own session if NULL).
   * @param string|null $executionId
   *   The execution ID (unique if NULL).
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result.
   */
  private function dispatch(string $name, array $params, AccountInterface $account, ?Request $request = NULL, ?string $executionId = NULL): HostActionResult {
    $this->setCurrentUser($account);
    return $this->dispatcher->dispatch($name, $this->payload($name, $params, $executionId), $account, $request ?? $this->newRequest());
  }

  /**
   * Asserts that a callable throws an HTTP exception of the given class.
   *
   * @param class-string<\Throwable> $class
   *   The expected exception class.
   * @param callable $callable
   *   The code to run.
   */
  private function assertHttpException(string $class, callable $callable): void {
    try {
      $callable();
    }
    catch (\Throwable $e) {
      $this->assertInstanceOf($class, $e);
      return;
    }
    $this->fail(sprintf('%s was not thrown.', $class));
  }

}
