<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\SyncPipeline;

use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\block\Entity\Block;
use Drupal\webassistant_connector\Drush\Commands\WebAssistantCommands;
use Drupal\webassistant_connector\Migration\LegacyMigrator;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector_sync_test\ScriptedPlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Drush commands by calling the command methods.
 *
 * Deprecations are ignored: loading Drush\Commands\DrushCommands in a
 * Drupal test triggers a DebugClassLoader notice about vendor code
 * (SavableState::currentState() return type) that this module cannot fix.
 * DrushCommandsFunctionalTest runs the real drush executable.
 *
 * @group webassistant_connector
 */
#[CoversClass(WebAssistantCommands::class)]
#[CoversClass(LegacyMigrator::class)]
#[Group('webassistant_connector')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class DrushCommandsTest extends SyncKernelTestBase {

  /**
   * The commands.
   */
  private WebAssistantCommands $commands;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->commands = WebAssistantCommands::create($this->container);
  }

  /**
   * Returns the output data of a command result as an array.
   *
   * @param \Consolidation\AnnotatedCommand\CommandResult $result
   *   The result.
   *
   * @return array<mixed>
   *   The data.
   */
  private static function data(CommandResult $result): array {
    $data = $result->getOutputData();
    return $data instanceof \ArrayObject ? $data->getArrayCopy() : (array) $data;
  }

  /**
   * Status, check, sync and retry report and exit with the right codes.
   */
  public function testStatusCheckSyncAndRetry(): void {
    $node = $this->createArticle();
    $status = $this->commands->status()->getArrayCopy();
    $this->assertSame('configured', $status['credentials']);
    $this->assertSame('environment', $status['client_secret_source']);
    $this->assertSame('ready', $status['platform']);
    $this->assertSame(self::PREFIX, $status['source_id_prefix']);
    $this->assertSame(2, $status['outbox_pending']);
    $this->assertSame(1, $status['sources_enabled']);
    $this->assertArrayNotHasKey('client_secret', $status);
    $this->assertStringNotContainsString('test-secret', serialize($status));

    $check = $this->commands->check(['origin' => 'https://www.example.com', 'format' => 'table']);
    $this->assertSame(0, $check->getExitCode());
    $rows = array_column(self::data($check), NULL, 'check');
    $this->assertSame('ok', $rows['bootstrap']['status']);
    $this->assertSame([['https://www.example.com']], ScriptedPlatformClient::callsOf('bootstrap'));
    ScriptedPlatformClient::$scopes = ['search:read'];
    $check = $this->commands->check(['origin' => NULL, 'format' => 'table']);
    $this->assertSame(1, $check->getExitCode());
    $rows = array_column(self::data($check), NULL, 'check');
    $this->assertStringContainsString('ingest:write', $rows['server_token']['details']);
    $this->assertSame([['https://www.example.com'], ['https://www.example.com']], ScriptedPlatformClient::callsOf('bootstrap'), 'The origin defaults to sync.public_base_url.');

    $sync = $this->commands->sync(['limit' => NULL, 'time' => NULL, 'format' => 'table']);
    $this->assertSame(0, $sync->getExitCode());
    $this->assertSame(2, self::data($sync)['upserted']);

    $node->setTitle('Changed')->save();
    ScriptedPlatformClient::failNext('ingest', new PlatformException('WebAssistant API error 503', 503, 'unavailable', TRUE));
    $sync = $this->commands->sync(['limit' => '10', 'time' => '30', 'format' => 'table']);
    $this->assertSame(1, $sync->getExitCode());
    $this->assertTrue(self::data($sync)['suspended']);

    $this->assertSame(1, $this->commands->retry(['status' => 'pending', 'id' => NULL]));
    $this->assertSame(0, $this->commands->retry(['status' => 'failed', 'id' => NULL]));
    $this->assertSame(OutboxItem::STATUS_PENDING, $this->outboxRows()[$this->key($node, 'en')]->status);
  }

  /**
   * Index records events; preview shows the canonical documents.
   */
  public function testIndexPreviewAndReconcile(): void {
    $node = $this->createArticle();
    $this->container->get('webassistant_connector.outbox')->clear();
    $this->assertSame(1, $this->commands->index('missing', ['bundle' => NULL, 'langcode' => NULL, 'process' => FALSE]));
    $this->assertSame(0, $this->commands->index('articles', ['bundle' => NULL, 'langcode' => 'es', 'process' => FALSE]));
    $this->assertSame([$this->key($node, 'es')], array_keys($this->outboxRows()));
    $this->assertSame(0, $this->commands->index(NULL, ['bundle' => 'article', 'langcode' => NULL, 'process' => TRUE]));
    $this->assertCount(2, $this->mapRows());

    $preview = $this->commands->preview('node', (string) $node->id(), 'es', ['source' => NULL, 'format' => 'json']);
    $this->assertSame(0, $preview->getExitCode());
    $entries = self::data($preview);
    $this->assertSame('articles', $entries[0]['source']);
    $this->assertNull($entries[0]['skip_reason']);
    $this->assertSame($this->remoteId($node, 'es'), $entries[0]['documents'][0]['source_id']);
    $this->assertSame(1, $this->commands->preview('node', '999', NULL, ['source' => NULL, 'format' => 'json'])->getExitCode());
    $this->assertSame(1, $this->commands->preview('nothing', '1', NULL, ['source' => NULL, 'format' => 'json'])->getExitCode());

    ScriptedPlatformClient::$inventory['help'][''] = ['items' => [], 'next_cursor' => NULL];
    $this->container->get('database')->update('webassistant_connector_document')->fields(['synced' => time() - 60])->execute();
    $reconcile = $this->commands->reconcile(NULL, ['dry-run' => TRUE, 'format' => 'table']);
    $this->assertSame(0, $reconcile->getExitCode());
    $row = self::data($reconcile)[0];
    $this->assertSame('help', $row['knowledge_base']);
    $this->assertSame(2, $row['missing']);
    $this->assertSame(0, $row['recorded']);
    $this->assertStringContainsString('missing: ' . $this->remoteId($node, 'en'), $row['ids']);

    ScriptedPlatformClient::$inventory['help'][''] = new PlatformException('WebAssistant API error 404 (not_found)', 404, 'not_found', FALSE, NULL, 'corr-404');
    $reconcile = $this->commands->reconcile('help', ['dry-run' => FALSE, 'format' => 'table']);
    $this->assertSame(1, $reconcile->getExitCode());
    $this->assertStringContainsString('corr-404', self::data($reconcile)[0]['errors']);
  }

  /**
   * Long loops empty the entity caches when memory runs low (A13).
   */
  public function testMemoryCheckAndRedaction(): void {
    $original = (string) ini_get('memory_limit');
    $cache = $this->container->get('entity.memory_cache');
    $cache->set('webassistant_test', 'cached');
    try {
      ini_set('memory_limit', '-1');
      $this->assertSame(0, WebAssistantCommands::memoryLimit());
      $this->assertFalse($this->commands->checkMemory());
      ini_set('memory_limit', '2048M');
      $this->assertSame(2048 * 1024 * 1024, WebAssistantCommands::memoryLimit());
      $this->assertFalse($this->commands->checkMemory());
      $this->assertNotFalse($cache->get('webassistant_test'));
      // A limit close to the current usage triggers the reset.
      ini_set('memory_limit', (string) (int) (memory_get_usage() * 1.3));
      $this->assertTrue($this->commands->checkMemory());
      $this->assertFalse($cache->get('webassistant_test'));
    }
    finally {
      ini_set('memory_limit', $original);
    }

    $document = [
      'source_id' => 'x',
      'reference' => [
        'download_url' => 'https://example.com/f',
        'access_token' => 'secret-token',
      ],
    ];
    $this->assertSame('[redacted]', WebAssistantCommands::redact($document)['reference']['access_token']);
    $this->assertSame('https://example.com/f', WebAssistantCommands::redact($document)['reference']['download_url']);
    $this->assertSame(['text' => 'a'], WebAssistantCommands::redact(['text' => 'a']));
  }

  /**
   * The legacy migration creates, disables, is idempotent and rolls back.
   */
  public function testMigrateLegacy(): void {
    // Nothing to migrate without the legacy module.
    $result = $this->commands->migrateLegacy(['rollback' => FALSE, 'dry-run' => FALSE, 'format' => 'yaml']);
    $this->assertSame(['settings' => [], 'created' => [], 'disabled' => [], 'skipped' => []], self::data($result));

    $this->enableModules(['block', 'path_alias']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->commands = WebAssistantCommands::create($this->container);
    // The legacy settings have no schema here: write them to the storage.
    $this->container->get('config.storage')->write(LegacyMigrator::LEGACY_CONFIG, [
      'result_target_selector' => '#webassistant-ai-result-panel',
      'desktop_always_open' => TRUE,
    ]);
    $this->container->get('config.factory')->reset(LegacyMigrator::LEGACY_CONFIG);
    $visibility = [
      'request_path' => [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => '/help',
      ],
    ];
    Block::create([
      'id' => 'stark_assistant',
      'theme' => 'stark',
      'region' => 'content',
      'weight' => 7,
      'plugin' => LegacyMigrator::LEGACY_PLUGIN,
      'settings' => ['label' => 'Assistant', 'label_display' => '0'],
      'visibility' => $visibility,
    ])->save();

    $dryRun = self::data($this->commands->migrateLegacy(['rollback' => FALSE, 'dry-run' => TRUE, 'format' => 'yaml']));
    $this->assertSame(['stark_assistant_connector'], $dryRun['created']);
    $this->assertNull(Block::load('stark_assistant_connector'), 'A dry run changes nothing.');

    $report = self::data($this->commands->migrateLegacy(['rollback' => FALSE, 'dry-run' => FALSE, 'format' => 'yaml']));
    $this->assertSame(['stark_assistant_connector'], $report['created']);
    $this->assertSame(['stark_assistant'], $report['disabled']);
    $this->assertSame([
      'widget.result_target' => '#webassistant-ai-result-panel',
      'widget.open' => TRUE,
      'widget.placement' => 'block',
    ], $report['settings']);
    $block = $this->container->get('entity_type.manager')->getStorage('block')->load('stark_assistant_connector');
    $this->assertInstanceOf(Block::class, $block);
    $this->assertSame('webassistant_connector_widget', $block->getPluginId());
    $this->assertSame('content', $block->getRegion());
    $this->assertSame(7, (int) $block->getWeight());
    $this->assertSame('/help', $block->getVisibility()['request_path']['pages']);
    $this->assertSame('Assistant', $block->get('settings')['label']);
    $this->assertFalse(Block::load('stark_assistant')->status());
    $settings = $this->config('webassistant_connector.settings');
    $this->assertSame('block', $settings->get('widget.placement'));
    $this->assertSame('#webassistant-ai-result-panel', $settings->get('widget.result_target'));
    $this->assertTrue($settings->get('widget.open'));

    // Idempotent.
    $again = self::data($this->commands->migrateLegacy(['rollback' => FALSE, 'dry-run' => FALSE, 'format' => 'yaml']));
    $this->assertSame([], $again['created']);
    $this->assertSame(['stark_assistant'], $again['skipped']);
    $this->assertCount(1, Block::loadMultiple(['stark_assistant_connector']));

    $rollback = self::data($this->commands->migrateLegacy(['rollback' => TRUE, 'dry-run' => FALSE, 'format' => 'yaml']));
    $this->assertSame(['stark_assistant_connector'], $rollback['removed']);
    $this->assertSame(['stark_assistant'], $rollback['enabled']);
    $this->assertNull(Block::load('stark_assistant_connector'));
    $this->assertTrue(Block::load('stark_assistant')->status());
    $settings = $this->config('webassistant_connector.settings');
    $this->assertSame('global', $settings->get('widget.placement'));
    $this->assertSame('', $settings->get('widget.result_target'));
    $this->assertFalse($settings->get('widget.open'));
  }

}
