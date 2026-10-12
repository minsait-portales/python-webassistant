<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Functional\SyncPipeline;

use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Controller\StatusController;
use Drupal\webassistant_connector\Form\StatusActionsForm;
use Drupal\webassistant_connector\Form\SyncSettingsForm;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector_test\FakePlatformClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the status page, its actions and the synchronization settings.
 *
 * @group webassistant_connector
 */
#[CoversClass(StatusController::class)]
#[CoversClass(StatusActionsForm::class)]
#[CoversClass(SyncSettingsForm::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class StatusPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'webassistant_connector', 'webassistant_connector_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Status page path.
   */
  private const STATUS = 'admin/config/services/webassistant-connector/status';

  /**
   * Synchronization settings path.
   */
  private const SETTINGS = 'admin/config/services/webassistant-connector/sync';

  /**
   * Returns the outbox row of a key, read from the database.
   *
   * @param string $entityId
   *   The entity ID (source articles, node, English).
   *
   * @return \Drupal\webassistant_connector\Sync\OutboxItem
   *   The row.
   */
  private function row(string $entityId): OutboxItem {
    $item = $this->container->get('webassistant_connector.outbox')->loadByKey('articles', 'node', $entityId, 'en');
    $this->assertNotNull($item);
    return $item;
  }

  /**
   * Only administrators reach the pages.
   */
  public function testAccess(): void {
    $this->drupalGet(self::STATUS);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(self::SETTINGS);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet(self::STATUS);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The page shows diagnostics and counters; the buttons act on the outbox.
   */
  public function testStatusPageAndActions(): void {
    $settings['settings']['webassistant_connector'] = (object) [
      'value' => ['client_id' => 'wa_portal', 'client_secret' => 'very-secret-value'],
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    $outbox = $this->container->get('webassistant_connector.outbox');
    foreach (['1', '2', '3'] as $id) {
      $outbox->recordKey(OutboxItem::OPERATION_UPSERT, 'articles', 'node', $id, 'en');
    }
    $database = $this->container->get('database');
    $database->update(Outbox::TABLE)
      ->fields([
        'status' => OutboxItem::STATUS_FAILED,
        'attempts' => 2,
        'next_attempt' => time() + 600,
        'last_error' => 'WebAssistant API error 503 (unavailable)',
        'correlation_id' => 'corr-123',
      ])
      ->condition('entity_id', '2')
      ->execute();
    $database->update(Outbox::TABLE)
      ->fields(['status' => OutboxItem::STATUS_DEAD, 'attempts' => 8, 'last_error' => 'Rejected by the platform: x'])
      ->condition('entity_id', '3')
      ->execute();

    $this->drupalLogin($this->drupalCreateUser(['administer webassistant connector']));
    $this->drupalGet(self::STATUS);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Outbox events per status');
    $expected = [
      OutboxItem::STATUS_PENDING => '1',
      OutboxItem::STATUS_FAILED => '1',
      OutboxItem::STATUS_DEAD => '1',
      OutboxItem::STATUS_DONE => '0',
    ];
    foreach ($expected as $status => $count) {
      $assert->elementTextEquals('xpath', '//table[contains(@class, "webassistant-connector-status-counts")]//tr[@data-outbox-status="' . $status . '"]/td[2]', $count);
    }
    $assert->pageTextContains('Configured (settings.php)');
    $assert->pageTextContains('Platform health');
    $assert->pageTextContains('Server token scopes');
    $assert->pageTextContains('Site bootstrap for');
    $assert->pageTextContains('corr-123');
    $assert->pageTextContains('Rejected by the platform: x');
    $assert->pageTextNotContains('very-secret-value');

    // Retry one event.
    $failed = $this->row('2');
    $this->getSession()->getPage()->pressButton('retry_item_' . $failed->id);
    $assert->pageTextContains('The event will be retried.');
    $this->assertSame(OutboxItem::STATUS_PENDING, $this->row('2')->status);
    $this->assertSame(0, $this->row('2')->attempts);
    $this->assertSame(OutboxItem::STATUS_DEAD, $this->row('3')->status);

    // Retry failed and dead events.
    $this->submitForm([], 'Retry failed');
    $assert->pageTextContains('1 event will be retried.');
    $this->assertSame(OutboxItem::STATUS_PENDING, $this->row('3')->status);

    // Process now: the source does not exist, so the events are done.
    $this->submitForm([], 'Process now');
    $assert->pageTextContains('3 events processed');
    foreach (['1', '2', '3'] as $id) {
      $this->assertSame(OutboxItem::STATUS_DONE, $this->row($id)->status);
    }
    $assert->elementTextEquals('xpath', '//table[contains(@class, "webassistant-connector-status-counts")]//tr[@data-outbox-status="done"]/td[2]', '3');
  }

  /**
   * The settings form saves and validates; the prefix locks once mapped.
   */
  public function testSyncSettingsForm(): void {
    $config = fn () => $this->container->get('config.factory')->reset('webassistant_connector.settings')->get('webassistant_connector.settings');
    $prefix = (string) $config()->get('sync.source_id_prefix');
    $this->assertMatchesRegularExpression('/^drupal-[0-9a-f]{8}$/', $prefix, 'hook_install() gives the site its own prefix.');

    $this->drupalLogin($this->drupalCreateUser(['administer webassistant connector']));
    $this->drupalGet(self::SETTINGS);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $this->submitForm([
      'enabled' => FALSE,
      'source_id_prefix' => 'portal-es',
      'public_base_url' => 'https://www.example.com/',
      'default_locale' => 'pt-BR',
      'batch_size' => 20,
      'process_limit' => 300,
      'max_attempts' => 5,
      'reconcile_interval' => 0,
      'retention_days' => 3,
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $saved = $config();
    $this->assertFalse($saved->get('sync.enabled'));
    $this->assertSame('portal-es', $saved->get('sync.source_id_prefix'));
    $this->assertSame('https://www.example.com', $saved->get('sync.public_base_url'));
    $this->assertSame('pt-BR', $saved->get('sync.default_locale'));
    $this->assertSame(20, $saved->get('sync.batch_size'));
    $this->assertSame(5, $saved->get('sync.max_attempts'));
    $this->assertSame(0, $saved->get('sync.reconcile_interval'));

    $this->submitForm(['public_base_url' => 'ftp://files.example.com', 'batch_size' => 500], 'Save configuration');
    $assert->pageTextContains('The public base URL must be an absolute http or https URL');
    $this->submitForm(['source_id_prefix' => 'Bad Prefix'], 'Save configuration');
    $assert->pageTextContains('The prefix must start with a lowercase letter');
    $this->assertSame(20, $config()->get('sync.batch_size'));

    // Once documents are mapped the prefix is read-only.
    $this->container->get('webassistant_connector.document_map')->save([
      'knowledge_base' => 'help',
      'remote_id' => 'portal-es:node:1:en',
      'source' => 'articles',
      'entity_type' => 'node',
      'entity_id' => '1',
      'langcode' => 'en',
      'remote_status' => 'ready',
    ]);
    $this->drupalGet(self::SETTINGS);
    $assert->fieldDisabled('source_id_prefix');
    $assert->pageTextContains('drush webassistant:purge');
    $this->submitForm(['batch_size' => 30], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame('portal-es', $config()->get('sync.source_id_prefix'));
    $this->assertSame(30, $config()->get('sync.batch_size'));
    $this->assertSame(1, $this->container->get('webassistant_connector.document_map')->count());
  }

  /**
   * Tests that "Index source" runs a batch that records and sends documents.
   */
  public function testIndexSourceBatchAndReconcile(): void {
    $settings['settings']['webassistant_connector'] = (object) [
      'value' => ['client_id' => 'wa_portal', 'client_secret' => 'very-secret-value'],
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    user_role_grant_permissions('anonymous', ['access content']);
    $first = $this->drupalCreateNode(['type' => 'article', 'title' => 'First article']);
    $this->drupalCreateNode(['type' => 'article', 'title' => 'Second article']);
    $this->drupalCreateNode(['type' => 'page', 'title' => 'A page']);
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

    $this->drupalLogin($this->drupalCreateUser(['administer webassistant connector']));
    $this->drupalGet(self::STATUS);
    $this->submitForm(['source' => 'articles', 'bundle' => 'page', 'process' => TRUE], 'Index source');
    $this->assertSession()->pageTextContains('The bundle is not covered by the source.');
    $this->submitForm(['source' => 'articles', 'bundle' => '', 'process' => TRUE], 'Index source');
    $this->assertSession()->pageTextContains('2 events recorded in the outbox.');
    $this->assertSession()->pageTextContains('2 documents sent and 0 deleted.');
    $map = $this->container->get('webassistant_connector.document_map');
    $this->assertSame(2, $map->count('help'));
    $this->assertCount(1, $map->find('articles', 'node', (string) $first->id(), 'en'));
    $ingested = FakePlatformClient::calls($this->container->get('state'), 'ingest');
    $titles = [];
    foreach ($ingested as $call) {
      foreach ($call[1][0] as $document) {
        $titles[] = $document['title'];
      }
    }
    sort($titles);
    $this->assertSame(['First article', 'Second article'], $titles);

    $this->drupalGet(self::STATUS);
    $this->submitForm([], 'Reconcile');
    $this->assertSession()->pageTextContains('help:');
    $this->assertNotEmpty(FakePlatformClient::calls($this->container->get('state'), 'inventory'));
  }

}
