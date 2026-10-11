<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\HostAction;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Unexpected plugin errors, redirect sanitization and plain-text messages.
 *
 * @group webassistant_connector
 */
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class HostActionFaultTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'webassistant_connector',
    'webassistant_connector_host_action_test',
  ];

  /**
   * The user running the actions.
   */
  private UserInterface $user;

  /**
   * The request shared by the executions (one session).
   */
  private Request $request;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user', 'webassistant_connector']);
    $this->createUser();
    $user = $this->createUser(['use webassistant connector']);
    $this->assertInstanceOf(UserInterface::class, $user);
    $this->user = $user;
    $this->setCurrentUser($user);
    $this->request = Request::create('http://localhost/webassistant/host-action/test.fault', 'POST');
    $this->request->setSession(new Session(new MockArraySessionStorage()));
  }

  /**
   * An error in an action with effects is stored: it never runs twice.
   */
  public function testErrorWithEffectsIsNotRetried(): void {
    $result = $this->runAction('test.fault', 'throw', 'act_e');
    $this->assertSame([
      'status' => 'failed',
      'message' => 'The action could not be completed. Please try again later.',
    ], $result->toArray(), 'Internal details are not returned.');
    $this->assertSame(1, $this->runs('effects'));

    $replayed = $this->runAction('test.fault', 'throw', 'act_e');
    $this->assertTrue($replayed->data['replayed']);
    $this->assertSame(1, $this->runs('effects'));
  }

  /**
   * An error in a side-effect free action may be retried.
   */
  public function testErrorWithoutEffectsCanBeRetried(): void {
    $result = $this->runAction('test.safe_fault', 'throw', 'act_s');
    $this->assertFalse($result->isSucceeded());
    $this->assertSame(1, $this->runs('safe'));

    $retried = $this->runAction('test.safe_fault', 'ok', 'act_s');
    $this->assertTrue($retried->isSucceeded());
    $this->assertArrayNotHasKey('replayed', $retried->data);
    $this->assertSame(2, $this->runs('safe'));
  }

  /**
   * External redirects are dropped; same-origin ones become paths.
   */
  public function testRedirectSanitization(): void {
    $result = $this->runAction('test.fault', 'external', 'act_x');
    $this->assertTrue($result->isSucceeded());
    $this->assertNull($result->redirect);
    $this->assertArrayNotHasKey('redirect', $this->runAction('test.fault', 'external', 'act_x')->toArray());

    $result = $this->runAction('test.fault', 'ok', 'act_ok');
    $this->assertSame('/node/1?x=1', $result->redirect);
    $this->assertSame('OK & done.', $result->message, 'Messages are plain text.');
  }

  /**
   * Runs a test action.
   *
   * @param string $name
   *   The action name.
   * @param string $mode
   *   The test mode.
   * @param string $executionId
   *   The execution ID.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result.
   */
  private function runAction(string $name, string $mode, string $executionId): HostActionResult {
    return $this->container->get('webassistant_connector.host_action_dispatcher')->dispatch($name, [
      'version' => '1',
      'name' => $name,
      'execution_id' => $executionId,
      'correlation_id' => $executionId,
      'params' => ['mode' => $mode],
    ], $this->user, $this->request);
  }

  /**
   * Returns how many times the test actions ran.
   *
   * @param string $kind
   *   The kind of action: "effects" or "safe".
   */
  private function runs(string $kind): int {
    return (int) $this->container->get('state')->get('webassistant_connector_host_action_test.runs.' . $kind, 0);
  }

}
