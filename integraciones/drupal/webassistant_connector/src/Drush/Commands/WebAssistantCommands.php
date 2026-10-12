<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\UnstructuredData;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Form\SettingsForm;
use Drupal\webassistant_connector\Migration\LegacyMigrator;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Sync\DocumentBuilder;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SourceIdGenerator;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use Drupal\webassistant_connector\Sync\SyncProcessor;
use Drupal\webassistant_connector\Sync\SyncReport;
use Drupal\webassistant_connector\Sync\SyncStatus;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

/**
 * Drush commands of the WebAssistant connector (design section 10).
 *
 * Commands that check or deliver something return a non-zero exit code when
 * it fails, so they can be used from system cron and deployment scripts.
 */
final class WebAssistantCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Server token scopes the synchronization needs.
   */
  public const REQUIRED_SCOPES = ['ingest:read', 'ingest:write', 'token:issue'];

  /**
   * Entities recorded per page by webassistant:index.
   */
  public const INDEX_PAGE = 100;

  /**
   * Constructs a WebAssistantCommands object.
   *
   * @param \Drupal\webassistant_connector\Sync\SyncStatus $syncStatus
   *   The synchronization status.
   * @param \Drupal\webassistant_connector\Sync\SyncProcessor $syncProcessor
   *   The sync processor.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\Indexer $indexer
   *   The indexer.
   * @param \Drupal\webassistant_connector\Sync\Reconciler $reconciler
   *   The reconciler.
   * @param \Drupal\webassistant_connector\Sync\DocumentBuilder $documentBuilder
   *   The document builder.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator.
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials.
   * @param \Drupal\webassistant_connector\Migration\LegacyMigrator $legacyMigrator
   *   The legacy widget migrator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly SyncStatus $syncStatus,
    private readonly SyncProcessor $syncProcessor,
    private readonly Outbox $outbox,
    private readonly Indexer $indexer,
    private readonly Reconciler $reconciler,
    private readonly DocumentBuilder $documentBuilder,
    private readonly SourceMatcher $sourceMatcher,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly PlatformClientInterface $platformClient,
    private readonly Credentials $credentials,
    private readonly LegacyMigrator $legacyMigrator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Shows the connection and outbox status.
   *
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\PropertyList
   *   The status.
   */
  #[CLI\Command(name: 'webassistant:status', aliases: ['wa-status'])]
  #[CLI\Usage(name: 'drush webassistant:status --format=json', description: 'Connection and outbox status as JSON.')]
  public function status(array $options = ['format' => 'table']): PropertyList {
    $config = $this->configFactory->get('webassistant_connector.settings');
    $summary = $this->syncStatus->summary();
    $platform = 'not configured';
    if ($this->credentials->isConfigured()) {
      try {
        $health = $this->platformClient->health();
        $platform = is_string($health['status'] ?? NULL) ? $health['status'] : 'ok';
      }
      catch (PlatformException $e) {
        $platform = 'error: ' . $e->getErrorCode() . ($e->getStatus() !== NULL ? ' (HTTP ' . $e->getStatus() . ')' : '');
      }
    }
    $data = [
      'enabled' => (bool) $config->get('enabled'),
      'sync_enabled' => (bool) $config->get('sync.enabled'),
      'api_base_url' => $this->credentials->apiBaseUrl(),
      'credentials' => $this->credentials->isConfigured() ? 'configured' : 'missing',
      'client_id_source' => $this->credentials->source('client_id'),
      'client_secret_source' => $this->credentials->source('client_secret'),
      'platform' => $platform,
      'source_id_prefix' => $this->sourceIdGenerator->getPrefix(),
      'sources_enabled' => count($this->sourceMatcher->getActiveSources()),
      'documents' => $summary['documents'],
    ];
    foreach ($summary['counts'] as $status => $count) {
      $data['outbox_' . $status] = $count;
    }
    $data += [
      'outbox_due' => $summary['due'],
      'backlog' => $summary['backlog'],
      'last_success' => $summary['last_success'] === NULL ? '' : date(DATE_ATOM, $summary['last_success']),
      'average_latency' => $summary['average_latency'],
    ];
    return new PropertyList($data);
  }

  /**
   * Runs every diagnostic check; exits with 1 when one fails.
   *
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   The checks and the exit code.
   */
  #[CLI\Command(name: 'webassistant:check', aliases: ['wa-check'])]
  #[CLI\Option(name: 'origin', description: 'Origin (scheme://host[:port]) checked with the bootstrap endpoint. Default: the origin of sync.public_base_url.')]
  #[CLI\FieldLabels(labels: ['check' => 'Check', 'status' => 'Status', 'details' => 'Details'])]
  #[CLI\Usage(name: 'drush webassistant:check --origin=https://www.example.com', description: 'Full diagnostics, including the site bootstrap for that origin.')]
  public function check(array $options = ['origin' => self::REQ, 'format' => 'table']): CommandResult {
    $rows = $this->diagnostics(is_string($options['origin'] ?? NULL) ? $options['origin'] : NULL);
    $failed = array_filter($rows, static fn (array $row): bool => $row['status'] === 'error');
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failed === [] ? self::EXIT_SUCCESS : self::EXIT_FAILURE);
  }

  /**
   * Records the entities of the sources in the outbox (initial indexing).
   *
   * @param string|null $source
   *   The source ID (default: every enabled source).
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'webassistant:index', aliases: ['wa-index'])]
  #[CLI\Argument(name: 'source', description: 'Source ID. Default: every enabled source.')]
  #[CLI\Option(name: 'bundle', description: 'Only this bundle.')]
  #[CLI\Option(name: 'langcode', description: 'Only this language.')]
  #[CLI\Option(name: 'process', description: 'Process the outbox afterwards (send the documents).')]
  #[CLI\Usage(name: 'drush webassistant:index articles --process', description: 'Index the source "articles" and send its documents.')]
  public function index(
    ?string $source = NULL,
    array $options = [
      'bundle' => self::REQ,
      'langcode' => self::REQ,
      'process' => FALSE,
    ],
  ): int {
    $sources = $this->resolveSources($source);
    if ($sources === NULL) {
      $this->logger()?->error(dt('The source @source does not exist or is disabled.', ['@source' => (string) $source]));
      return self::EXIT_FAILURE;
    }
    $bundle = is_string($options['bundle'] ?? NULL) && $options['bundle'] !== '' ? $options['bundle'] : NULL;
    $langcode = is_string($options['langcode'] ?? NULL) && $options['langcode'] !== '' ? $options['langcode'] : NULL;
    $total = 0;
    foreach ($sources as $item) {
      $recorded = $this->indexSource($item, $bundle, $langcode);
      $total += $recorded;
      $this->logger()?->success(dt('@source: @count events recorded.', [
        '@source' => (string) $item->id(),
        '@count' => $recorded,
      ]));
    }
    if (empty($options['process'])) {
      $this->logger()?->notice(dt('@count events recorded; cron or "drush webassistant:sync" sends them.', ['@count' => $total]));
      return self::EXIT_SUCCESS;
    }
    $report = $this->drain(0);
    $this->logReport($report);
    return $report->hasFailures() || $report->isSuspended() ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Processes the outbox.
   *
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   The report; exit code 1 when events failed or the platform is down.
   */
  #[CLI\Command(name: 'webassistant:sync', aliases: ['wa-sync'])]
  #[CLI\Option(name: 'limit', description: 'Maximum number of events. Default: sync.process_limit.')]
  #[CLI\Option(name: 'time', description: 'Maximum seconds. Default: no limit.')]
  #[CLI\Usage(name: 'drush webassistant:sync --limit=500 --time=120', description: 'Process up to 500 events for at most two minutes.')]
  public function sync(array $options = ['limit' => self::REQ, 'time' => self::REQ, 'format' => 'table']): CommandResult {
    if (!$this->syncProcessor->isEnabled()) {
      $this->logger()?->warning(dt('Synchronization is disabled.'));
      return CommandResult::dataWithExitCode(new PropertyList((new SyncReport())->toArray()), self::EXIT_SUCCESS);
    }
    $limit = is_numeric($options['limit'] ?? NULL) ? max(1, (int) $options['limit']) : max(1, (int) ($this->configFactory->get('webassistant_connector.settings')->get('sync.process_limit') ?: 200));
    $time = is_numeric($options['time'] ?? NULL) ? max(0, (int) $options['time']) : 0;
    $report = $this->syncProcessor->process($limit, $time);
    $this->logReport($report);
    $data = $report->toArray();
    $data['errors'] = implode("\n", array_map(static fn (array $error): string => $error['message'], $data['errors']));
    return CommandResult::dataWithExitCode(new PropertyList($data), $report->hasFailures() || $report->isSuspended() ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

  /**
   * Reconciles the remote inventory with the map.
   *
   * @param string|null $knowledgeBase
   *   The knowledge base (default: all).
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   One row per knowledge base; exit code 1 on errors.
   */
  #[CLI\Command(name: 'webassistant:reconcile', aliases: ['wa-reconcile'])]
  #[CLI\Argument(name: 'knowledgeBase', description: 'Knowledge base key. Default: every knowledge base of the map and of the enabled sources.')]
  #[CLI\Option(name: 'dry-run', description: 'Report only, record nothing.')]
  #[CLI\FieldLabels(labels: [
    'knowledge_base' => 'Knowledge base',
    'remote' => 'Remote',
    'local' => 'Mapped',
    'orphans' => 'Orphans',
    'outdated' => 'Outdated',
    'missing' => 'Missing',
    'recorded' => 'Recorded',
    'errors' => 'Errors',
    'ids' => 'Source IDs',
  ])]
  #[CLI\DefaultTableFields(fields: [
    'knowledge_base',
    'remote',
    'local',
    'orphans',
    'outdated',
    'missing',
    'recorded',
    'errors',
  ])]
  #[CLI\Usage(name: 'drush webassistant:reconcile --dry-run --fields=knowledge_base,ids', description: 'List what a reconciliation would change.')]
  public function reconcile(?string $knowledgeBase = NULL, array $options = ['dry-run' => FALSE, 'format' => 'table']): CommandResult {
    $knowledgeBases = $knowledgeBase !== NULL && $knowledgeBase !== '' ? [$knowledgeBase] : $this->reconciler->knowledgeBases();
    $dryRun = !empty($options['dry-run']);
    $rows = [];
    $failed = FALSE;
    foreach ($knowledgeBases as $key) {
      try {
        $report = $this->reconciler->reconcile($key, $dryRun);
      }
      catch (PlatformException $e) {
        $failed = TRUE;
        $rows[] = [
          'knowledge_base' => $key,
          'remote' => 0,
          'local' => 0,
          'orphans' => 0,
          'outdated' => 0,
          'missing' => 0,
          'recorded' => 0,
          'errors' => $e->getMessage() . ($e->getCorrelationId() !== NULL ? ' (correlation ID ' . $e->getCorrelationId() . ')' : ''),
          'ids' => '',
        ];
        continue;
      }
      $failed = $failed || $report['errors'] !== [];
      $ids = [];
      foreach (['orphans' => 'orphan', 'outdated' => 'outdated', 'missing' => 'missing'] as $category => $label) {
        foreach ($report[$category] as $id) {
          $ids[] = $label . ': ' . $id;
        }
      }
      $rows[] = [
        'knowledge_base' => $key,
        'remote' => $report['remote'],
        'local' => $report['local'],
        'orphans' => $report['orphan_count'],
        'outdated' => $report['outdated_count'],
        'missing' => $report['missing_count'],
        'recorded' => $report['recorded'],
        'errors' => implode('; ', $report['errors']),
        'ids' => implode("\n", $ids),
      ];
    }
    if ($knowledgeBases === []) {
      $this->logger()?->notice(dt('There is no knowledge base to reconcile.'));
    }
    elseif (!$dryRun) {
      $this->logger()?->notice(dt('Events were recorded in the outbox; cron or "drush webassistant:sync" processes them.'));
    }
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

  /**
   * Moves failed (and dead) events back to pending.
   *
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'webassistant:retry', aliases: ['wa-retry'])]
  #[CLI\Option(name: 'status', description: 'Comma-separated statuses to retry: failed, dead.')]
  #[CLI\Option(name: 'id', description: 'Only this outbox event ID.')]
  #[CLI\Usage(name: 'drush webassistant:retry --status=dead', description: 'Retry the dead letter.')]
  public function retry(array $options = ['status' => 'failed,dead', 'id' => self::REQ]): int {
    $statuses = array_values(array_filter(array_map('trim', explode(',', (string) ($options['status'] ?? 'failed,dead')))));
    $invalid = array_diff($statuses, OutboxItem::RETRYABLE_STATUSES);
    if ($statuses === [] || $invalid !== []) {
      $this->logger()?->error(dt('Unknown status: use failed and/or dead.'));
      return self::EXIT_FAILURE;
    }
    $id = is_numeric($options['id'] ?? NULL) ? (int) $options['id'] : NULL;
    $count = $this->outbox->retry($statuses, $id);
    $this->logger()?->success(dt('@count events moved back to pending.', ['@count' => $count]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Shows the canonical documents an entity would produce.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $id
   *   The entity ID.
   * @param string|null $langcode
   *   The language (default: the entity's default language).
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   One entry per source; exit code 1 when nothing applies.
   */
  #[CLI\Command(name: 'webassistant:preview', aliases: ['wa-preview'])]
  #[CLI\Argument(name: 'entityType', description: 'Entity type ID, e.g. node.')]
  #[CLI\Argument(name: 'id', description: 'Entity ID.')]
  #[CLI\Argument(name: 'langcode', description: 'Language code. Default: the default language of the entity.')]
  #[CLI\Option(name: 'source', description: 'Only this source (disabled sources too).')]
  #[CLI\Usage(name: 'drush webassistant:preview node 42 es', description: 'Canonical JSON of node 42 in Spanish.')]
  public function preview(
    string $entityType,
    string $id,
    ?string $langcode = NULL,
    array $options = ['source' => self::REQ, 'format' => 'json'],
  ): CommandResult {
    if (!$this->entityTypeManager->hasDefinition($entityType)) {
      $this->logger()?->error(dt('Unknown entity type @type.', ['@type' => $entityType]));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }
    $entity = $this->entityTypeManager->getStorage($entityType)->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      $this->logger()?->error(dt('@type @id does not exist.', ['@type' => $entityType, '@id' => $id]));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }
    $langcode = $langcode !== NULL && $langcode !== '' ? $langcode : $entity->language()->getId();
    $sources = [];
    foreach ($this->entityTypeManager->getStorage('webassistant_source')->loadMultiple() as $sourceId => $source) {
      if (!$source instanceof SourceInterface || !$source->appliesTo($entity)) {
        continue;
      }
      if (is_string($options['source'] ?? NULL) && $options['source'] !== '' && $options['source'] !== (string) $sourceId) {
        continue;
      }
      $sources[(string) $sourceId] = $source;
    }
    if ($sources === []) {
      $this->logger()?->error(dt('No source covers @type @id.', ['@type' => $entityType, '@id' => $id]));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }
    $entries = [];
    foreach ($sources as $sourceId => $source) {
      $result = $this->documentBuilder->build($source, $entityType, $id, $langcode, TRUE);
      $documents = [];
      $warnings = $result->getWarnings();
      foreach ($result->getDocuments() as $document) {
        try {
          $documents[] = $document->toArray();
        }
        catch (\LogicException $e) {
          $warnings[] = $e->getMessage();
        }
      }
      $entries[] = [
        'source' => $sourceId,
        'enabled' => $source->status(),
        'langcode' => $langcode,
        'skip_reason' => $result->getSkipReason(),
        'warnings' => $warnings,
        'documents' => $documents,
      ];
    }
    return CommandResult::dataWithExitCode(new UnstructuredData($entries), self::EXIT_SUCCESS);
  }

  /**
   * Migrates the legacy webassistant_ai widget (or rolls it back).
   *
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   What was done.
   */
  #[CLI\Command(name: 'webassistant:migrate-legacy', aliases: ['wa-migrate-legacy'])]
  #[CLI\Option(name: 'rollback', description: 'Re-enable the legacy blocks, remove the created ones and restore the settings.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would be migrated.')]
  #[CLI\Usage(name: 'drush webassistant:migrate-legacy', description: 'Create connector blocks in place of the legacy ones and disable the legacy blocks.')]
  public function migrateLegacy(array $options = ['rollback' => FALSE, 'dry-run' => FALSE, 'format' => 'yaml']): CommandResult {
    if (!empty($options['rollback'])) {
      $report = $this->legacyMigrator->rollback();
      $this->logger()?->success(dt('Rolled back: @removed blocks removed, @enabled legacy blocks enabled.', [
        '@removed' => count($report['removed']),
        '@enabled' => count($report['enabled']),
      ]));
      return CommandResult::dataWithExitCode(new UnstructuredData($report), self::EXIT_SUCCESS);
    }
    $inspect = $this->legacyMigrator->inspect();
    if (!$inspect['settings'] && $inspect['blocks'] === []) {
      $this->logger()?->notice(dt('Nothing to migrate: there are no webassistant_ai settings nor blocks.'));
    }
    $report = $this->legacyMigrator->migrate(!empty($options['dry-run']));
    $this->logger()?->success(dt('@created blocks created, @disabled legacy blocks disabled, @settings settings copied.', [
      '@created' => count($report['created']),
      '@disabled' => count($report['disabled']),
      '@settings' => count($report['settings']),
    ]));
    return CommandResult::dataWithExitCode(new UnstructuredData($report), self::EXIT_SUCCESS);
  }

  /**
   * Deletes every remote document of this site and clears the map.
   *
   * @return int
   *   The exit code (1 when some deletions failed).
   *
   * @throws \Drush\Exceptions\UserAbortException
   */
  #[CLI\Command(name: 'webassistant:purge', aliases: ['wa-purge'])]
  #[CLI\Usage(name: 'drush webassistant:purge', description: 'Before uninstalling the module or changing the source ID prefix.')]
  public function purge(): int {
    $count = $this->syncStatus->summary()['documents'];
    if (!$this->io()->confirm(dt('Delete @count documents of this site from the platform and clear the outbox?', ['@count' => $count]), FALSE)) {
      throw new UserAbortException();
    }
    $result = $this->syncProcessor->purgeRemote();
    foreach ($result['errors'] as $error) {
      $this->logger()?->warning($error);
    }
    $this->logger()?->success(dt('@deleted documents deleted, @failed failed.', [
      '@deleted' => $result['deleted'],
      '@failed' => $result['failed'],
    ]));
    return $result['failed'] > 0 ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Runs the diagnostic checks.
   *
   * @param string|null $origin
   *   The origin to check, or NULL for the one of sync.public_base_url.
   *
   * @return list<array{check: string, status: string, details: string}>
   *   The checks (status ok, warning or error).
   */
  public function diagnostics(?string $origin = NULL): array {
    $config = $this->configFactory->get('webassistant_connector.settings');
    $rows = [];
    $add = static function (string $check, string $status, string $details = '') use (&$rows): void {
      $rows[] = ['check' => $check, 'status' => $status, 'details' => $details];
    };

    $add('connector', $config->get('enabled') ? 'ok' : 'warning', $config->get('enabled') ? 'enabled' : 'disabled');
    $add('synchronization', $config->get('sync.enabled') ? 'ok' : 'warning', $config->get('sync.enabled') ? 'enabled' : 'disabled');
    foreach (['client_id', 'client_secret', 'api_base_url'] as $name) {
      $source = $this->credentials->source($name);
      $add($name, $source === Credentials::SOURCE_MISSING ? 'error' : 'ok', $source);
    }
    $apiUrl = $this->credentials->apiBaseUrl();
    if ($apiUrl !== '') {
      $add('api_https', SettingsForm::isSecureUrl($apiUrl) ? 'ok' : 'warning', $apiUrl);
    }
    $prefix = $this->sourceIdGenerator->getPrefix();
    $add('source_id_prefix', $prefix === SourceIdGenerator::DEFAULT_PREFIX ? 'warning' : 'ok', $prefix === SourceIdGenerator::DEFAULT_PREFIX ? 'the default prefix is shared by every installation' : $prefix);
    $sources = count($this->sourceMatcher->getActiveSources());
    $add('sources', $sources > 0 ? 'ok' : 'warning', (string) $sources . ' enabled');

    if ($this->credentials->isConfigured()) {
      try {
        $health = $this->platformClient->health();
        $add('platform_health', 'ok', is_string($health['status'] ?? NULL) ? $health['status'] : '');
      }
      catch (PlatformException $e) {
        $add('platform_health', 'error', self::describe($e));
      }
      try {
        $token = $this->platformClient->serverToken();
        $missing = array_diff(self::REQUIRED_SCOPES, $token->scopes);
        $add('server_token', $missing === [] ? 'ok' : 'error', $missing === [] ? implode(', ', $token->scopes) : 'missing scopes: ' . implode(', ', $missing));
      }
      catch (PlatformException $e) {
        $add('server_token', 'error', self::describe($e));
      }
      $origin ??= self::originOf((string) $config->get('sync.public_base_url'));
      if ($origin === NULL || $origin === '') {
        $add('bootstrap', 'warning', 'skipped: pass --origin or set sync.public_base_url');
      }
      else {
        try {
          $bootstrap = $this->platformClient->bootstrap($origin);
          $site = is_array($bootstrap['site'] ?? NULL) && is_scalar($bootstrap['site']['id'] ?? NULL) ? (string) $bootstrap['site']['id'] : '-';
          $add('bootstrap', 'ok', $origin . ' → site ' . $site);
        }
        catch (PlatformException $e) {
          $add('bootstrap', 'error', $origin . ': ' . self::describe($e));
        }
      }
    }

    $counts = $this->syncStatus->counts();
    $dead = $counts[OutboxItem::STATUS_DEAD] ?? 0;
    $add('outbox_dead', $dead > 0 ? 'warning' : 'ok', (string) $dead);
    $backlog = $this->syncStatus->backlog();
    $add('outbox_backlog', $backlog > 0 ? 'warning' : 'ok', (string) $backlog);
    return $rows;
  }

  /**
   * Records the events of a source page by page.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string|null $bundle
   *   Restrict to a bundle.
   * @param string|null $langcode
   *   Restrict to a language.
   *
   * @return int
   *   Events recorded.
   */
  private function indexSource(SourceInterface $source, ?string $bundle, ?string $langcode): int {
    $lastId = 0;
    $recorded = 0;
    do {
      $page = $this->indexer->enqueue($source, $bundle, $langcode, $lastId, self::INDEX_PAGE);
      $recorded += $page['count'];
      $lastId = $page['last_id'];
    } while ($page['entities'] === self::INDEX_PAGE);
    return $recorded;
  }

  /**
   * Processes the outbox until nothing is due.
   *
   * Stops early when the platform fails or the time is up.
   *
   * @param int $timeBudget
   *   Seconds (0: no limit).
   *
   * @return \Drupal\webassistant_connector\Sync\SyncReport
   *   The merged report.
   */
  private function drain(int $timeBudget): SyncReport {
    $total = new SyncReport();
    $start = time();
    do {
      $report = $this->syncProcessor->process(500, $timeBudget);
      $total->merge($report);
    } while ($report->getProcessed() > 0 && !$report->isSuspended() && $this->outbox->hasDue() && ($timeBudget === 0 || time() - $start < $timeBudget));
    return $total;
  }

  /**
   * Resolves the sources of a command.
   *
   * @param string|null $id
   *   A source ID, or NULL for every enabled source.
   *
   * @return array<string, \Drupal\webassistant_connector\Entity\SourceInterface>|null
   *   The sources, or NULL when the given source is missing or disabled.
   */
  private function resolveSources(?string $id): ?array {
    $active = $this->sourceMatcher->getActiveSources();
    if ($id === NULL || $id === '') {
      return $active;
    }
    return isset($active[$id]) ? [$id => $active[$id]] : NULL;
  }

  /**
   * Logs the counters of a processing run.
   *
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   */
  private function logReport(SyncReport $report): void {
    $this->logger()?->notice(dt('@processed events: @upserted documents sent, @unchanged unchanged, @deleted deleted, @failed failed, @dead dead.', [
      '@processed' => $report->getProcessed(),
      '@upserted' => $report->getUpserted(),
      '@unchanged' => $report->getUnchanged(),
      '@deleted' => $report->getDeleted(),
      '@failed' => $report->getFailed(),
      '@dead' => $report->getDead(),
    ]));
    foreach (array_slice($report->getErrors(), 0, 10) as $error) {
      $this->logger()?->warning($error['message']);
    }
    if ($report->isSuspended()) {
      $this->logger()?->error(dt('Processing stopped: the platform is unavailable or not configured.'));
    }
  }

  /**
   * Returns the origin (scheme://host[:port]) of a URL.
   *
   * @param string $url
   *   The URL.
   *
   * @return string|null
   *   The origin, or NULL.
   */
  private static function originOf(string $url): ?string {
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return NULL;
    }
    return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
   * Describes a platform error (no secrets).
   *
   * @param \Drupal\webassistant_connector\Platform\PlatformException $e
   *   The error.
   *
   * @return string
   *   The message with the correlation ID.
   */
  private static function describe(PlatformException $e): string {
    return $e->getMessage() . ($e->getCorrelationId() !== NULL ? ' (correlation ID ' . $e->getCorrelationId() . ')' : '');
  }

}
