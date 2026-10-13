<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional\SyncPipeline;

use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector\Drush\Commands\WebAssistantCommands;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector_test\FakePlatformClient;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the Drush commands through the real drush executable.
 *
 * Checks that Drush 13 discovers the commands, that their attributes are
 * valid and that they exit with the documented codes.
 *
 * @group webassistant_connector
 */
#[CoversClass(WebAssistantCommands::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class DrushCommandsFunctionalTest extends BrowserTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'webassistant_connector', 'webassistant_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Decodes the JSON output of the last command.
   *
   * @return array<mixed>
   *   The data.
   */
  private function jsonOutput(): array {
    $data = json_decode($this->getOutput(), TRUE);
    $this->assertIsArray($data, $this->getOutput());
    return $data;
  }

  /**
   * Status, check, index, preview, sync, reconcile, retry and purge.
   */
  public function testCommands(): void {
    // Without credentials: status works, check fails.
    $this->drush('webassistant:status', [], ['format' => 'json']);
    $status = $this->jsonOutput();
    $this->assertSame('missing', $status['credentials']);
    $this->assertMatchesRegularExpression('/^drupal-[0-9a-f]{8}$/', $status['source_id_prefix']);
    $this->drush('wa-check', [], ['format' => 'json'], NULL, NULL, 1);
    $checks = array_column($this->jsonOutput(), NULL, 'check');
    $this->assertSame('error', $checks['client_secret']['status']);

    $settings['settings']['webassistant_connector'] = (object) [
      'value' => ['client_id' => 'wa_portal', 'client_secret' => 'very-secret-value'],
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    $this->drush('wa-check', [], ['format' => 'json']);
    $checks = array_column($this->jsonOutput(), NULL, 'check');
    $this->assertSame('ok', $checks['server_token']['status']);
    $this->assertSame('warning', $checks['bootstrap']['status'], 'No origin without --origin nor sync.public_base_url.');
    $this->drush('wa-check', [], ['origin' => 'https://www.example.com', 'format' => 'json']);
    $checks = array_column($this->jsonOutput(), NULL, 'check');
    $this->assertSame('ok', $checks['bootstrap']['status']);
    $this->assertSame([['https://www.example.com']], array_column(FakePlatformClient::calls($this->container->get('state'), 'bootstrap'), 1));
    $this->assertStringNotContainsString('very-secret-value', $this->getOutput() . $this->getErrorOutput());

    // Content and a source.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    user_role_grant_permissions('anonymous', ['access content']);
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Drush article']);
    Source::create([
      'id' => 'articles',
      'label' => 'Articles',
      'status' => TRUE,
      'entity_type' => 'node',
      'bundles' => ['article'],
      'knowledge_base' => 'help',
      'document_type' => 'guide',
      'acl_mode' => 'public',
      'fields' => [['field' => 'title', 'role' => 'title']],
    ])->save();
    $this->container->get('webassistant_connector.outbox')->clear();

    $this->drush('wa-index', ['missing'], [], NULL, NULL, 1);
    // Without a source argument every enabled source is indexed.
    $this->drush('wa-index', [], ['process' => NULL]);
    $this->assertStringContainsString('articles: 1 events recorded.', $this->getErrorOutput());
    $this->assertSame(1, $this->container->get('webassistant_connector.document_map')->count('help'));
    $titles = [];
    foreach (FakePlatformClient::calls($this->container->get('state'), 'ingest') as $call) {
      $titles = array_merge($titles, array_column($call[1][0], 'title'));
    }
    $this->assertSame(['Drush article'], $titles);

    // Without a langcode the default language of the entity is previewed.
    $this->drush('wa-preview', ['node', (string) $node->id()], ['format' => 'json']);
    $preview = $this->jsonOutput();
    $this->assertSame('articles', $preview[0]['source']);
    $this->assertSame('en', $preview[0]['langcode']);
    $this->assertNull($preview[0]['skip_reason']);
    $this->assertSame('Drush article', $preview[0]['documents'][0]['title']);
    $this->drush('wa-preview', ['node', '999'], [], NULL, NULL, 1);

    $this->drush('wa-sync', [], ['format' => 'json']);
    $this->assertSame(0, $this->jsonOutput()['processed']);

    $this->drush('wa-reconcile', [], ['dry-run' => NULL, 'format' => 'json']);
    $rows = $this->jsonOutput();
    $this->assertSame('help', $rows[0]['knowledge_base']);
    $this->assertSame(1, $rows[0]['missing'], 'The fake inventory is empty.');

    $this->drush('wa-retry', [], ['status' => 'bogus'], NULL, NULL, 1);
    $this->drush('wa-retry', [], ['status' => 'dead']);

    $this->drush('wa-migrate-legacy', [], ['format' => 'json']);
    $this->assertSame([], $this->jsonOutput()['created']);

    // Purge asks for confirmation: --yes deletes every mapped document.
    $this->drush('wa-purge', [], ['yes' => NULL]);
    $this->assertSame(0, $this->container->get('webassistant_connector.document_map')->count());
    $this->assertCount(1, FakePlatformClient::calls($this->container->get('state'), 'deleteDocument'));

    // A platform outage makes the delivery commands fail.
    $node->setTitle('Changed')->save();
    $this->container->get('state')->set(FakePlatformClient::FAIL_KEY, TRUE);
    $this->drush('wa-sync', [], ['format' => 'json'], NULL, NULL, 1);
    $this->assertTrue($this->jsonOutput()['suspended']);
  }

}
