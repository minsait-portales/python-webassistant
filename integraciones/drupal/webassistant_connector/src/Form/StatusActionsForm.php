<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Migration\LegacyMigrator;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use Drupal\webassistant_connector\Sync\SyncProcessor;
use Drupal\webassistant_connector\Sync\SyncReport;
use Drupal\webassistant_connector\Sync\SyncStatus;

/**
 * Buttons of the status page (design section 10).
 *
 * Process now, Retry failed, Reconcile, Index source (Batch API through
 * Indexer::createBatch()), retry of a single event from the recent errors
 * table and the legacy widget migration (amendment D2). Every button is a
 * form submission, protected by the form token.
 */
final class StatusActionsForm extends FormBase {

  use AutowireTrait;

  /**
   * Seconds the "Process now" button may spend.
   */
  public const PROCESS_BUDGET = 30;

  /**
   * Seconds the "Reconcile" button may spend in total.
   */
  public const RECONCILE_BUDGET = 25;

  /**
   * Constructs a StatusActionsForm.
   *
   * @param \Drupal\webassistant_connector\Sync\SyncProcessor $syncProcessor
   *   The sync processor.
   * @param \Drupal\webassistant_connector\Sync\Reconciler $reconciler
   *   The reconciler.
   * @param \Drupal\webassistant_connector\Sync\Indexer $indexer
   *   The indexer.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\SyncStatus $syncStatus
   *   The synchronization status.
   * @param \Drupal\webassistant_connector\Migration\LegacyMigrator $legacyMigrator
   *   The legacy widget migrator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    protected SyncProcessor $syncProcessor,
    protected Reconciler $reconciler,
    protected Indexer $indexer,
    protected Outbox $outbox,
    protected SourceMatcher $sourceMatcher,
    protected SyncStatus $syncStatus,
    protected LegacyMigrator $legacyMigrator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'webassistant_connector_status_actions';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache'] = ['max-age' => 0];

    $form['outbox'] = [
      '#type' => 'details',
      '#title' => $this->t('Outbox'),
      '#open' => TRUE,
    ];
    $form['outbox']['buttons'] = ['#type' => 'actions'];
    $form['outbox']['buttons']['process'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process now'),
      '#submit' => ['::processNow'],
      '#name' => 'process',
      '#disabled' => !$this->syncProcessor->isEnabled(),
    ];
    $form['outbox']['buttons']['retry'] = [
      '#type' => 'submit',
      '#value' => $this->t('Retry failed'),
      '#submit' => ['::retryFailed'],
      '#name' => 'retry_failed',
    ];
    $form['outbox']['buttons']['reconcile'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reconcile'),
      '#submit' => ['::reconcile'],
      '#name' => 'reconcile',
      '#disabled' => !$this->syncProcessor->isEnabled(),
    ];

    $errors = $this->syncStatus->recentErrors(20);
    $form['outbox']['errors'] = [
      '#type' => 'table',
      '#caption' => $this->t('Recent errors'),
      '#header' => [
        $this->t('Event'),
        $this->t('Status'),
        $this->t('Attempts'),
        $this->t('Error'),
        $this->t('Correlation ID'),
        $this->t('Changed'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No failed events.'),
      '#attributes' => ['class' => ['webassistant-connector-status-errors']],
    ];
    foreach ($errors as $entry) {
      $id = (int) $entry['id'];
      $form['outbox']['errors'][$id] = [
        'event' => ['#plain_text' => sprintf('%s: %s %s (%s)', $entry['source'], $entry['entity_type'], $entry['entity_id'], $entry['langcode'])],
        'status' => ['#plain_text' => $entry['status'] === OutboxItem::STATUS_DEAD ? (string) $this->t('Dead letter') : (string) $this->t('Failed, next attempt @time', ['@time' => $this->dateFormatter->format($entry['next_attempt'], 'short')])],
        'attempts' => ['#plain_text' => (string) $entry['attempts']],
        'error' => ['#plain_text' => Unicode::truncate((string) $entry['last_error'], 300, TRUE, TRUE)],
        'correlation' => ['#plain_text' => (string) $entry['correlation_id']],
        'changed' => ['#plain_text' => $this->dateFormatter->format($entry['changed'], 'short')],
        'retry' => [
          '#type' => 'submit',
          '#value' => $this->t('Retry'),
          '#name' => 'retry_item_' . $id,
          '#item_id' => $id,
          '#submit' => ['::retryOne'],
          '#limit_validation_errors' => [],
        ],
      ];
    }

    $sources = [];
    foreach ($this->sourceMatcher->getActiveSources() as $id => $source) {
      $sources[$id] = (string) $source->label();
    }
    $form['index'] = [
      '#type' => 'details',
      '#title' => $this->t('Index a source'),
      '#description' => $this->t('Records an event for every entity covered by the source. Documents that did not change are not sent again.'),
      '#open' => FALSE,
    ];
    if ($sources === []) {
      $form['index']['empty'] = ['#markup' => $this->t('There are no enabled sources.')];
    }
    else {
      $form['index']['source'] = [
        '#type' => 'select',
        '#title' => $this->t('Source'),
        '#options' => $sources,
      ];
      $form['index']['bundle'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Bundle'),
        '#description' => $this->t('Optional: only this bundle (machine name).'),
        '#size' => 32,
        '#maxlength' => 64,
      ];
      $form['index']['process'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Send the documents while indexing'),
        '#description' => $this->t('Otherwise the outbox is processed by cron.'),
        '#default_value' => TRUE,
        '#disabled' => !$this->syncProcessor->isEnabled(),
      ];
      $form['index']['actions'] = ['#type' => 'actions'];
      $form['index']['actions']['index'] = [
        '#type' => 'submit',
        '#value' => $this->t('Index source'),
        '#submit' => ['::indexSource'],
        '#validate' => ['::validateIndex'],
        '#name' => 'index',
      ];
    }

    $legacy = $this->legacyMigrator->inspect();
    if ($legacy['blocks'] !== [] || $legacy['settings'] || $legacy['migrated'] !== []) {
      $form['legacy'] = [
        '#type' => 'details',
        '#title' => $this->t('Legacy widget (webassistant_ai)'),
        '#description' => $this->t('Creates a WebAssistant widget block in the region of every legacy block (same theme, weight and visibility), copies the results selector and disables the legacy blocks. The rollback re-enables them and removes the new blocks.'),
        '#open' => $legacy['blocks'] !== [] && $legacy['migrated'] === [],
        'actions' => ['#type' => 'actions'],
      ];
      $form['legacy']['actions']['migrate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Migrate legacy widget'),
        '#submit' => ['::migrateLegacy'],
        '#name' => 'migrate_legacy',
      ];
      if ($legacy['migrated'] !== []) {
        $form['legacy']['actions']['rollback'] = [
          '#type' => 'submit',
          '#value' => $this->t('Roll back the migration'),
          '#submit' => ['::rollbackLegacy'],
          '#name' => 'rollback_legacy',
        ];
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Every button has its own submit handler.
  }

  /**
   * Submit handler: processes the outbox now.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function processNow(array &$form, FormStateInterface $form_state): void {
    $limit = max(1, (int) ($this->config('webassistant_connector.settings')->get('sync.process_limit') ?: 200));
    $this->reportMessages($this->syncProcessor->process($limit, self::PROCESS_BUDGET));
  }

  /**
   * Submit handler: moves failed and dead events back to pending.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function retryFailed(array &$form, FormStateInterface $form_state): void {
    $count = $this->outbox->retry([OutboxItem::STATUS_FAILED, OutboxItem::STATUS_DEAD]);
    $this->messenger()->addStatus($this->formatPlural($count, '1 event will be retried.', '@count events will be retried.'));
  }

  /**
   * Submit handler: retries one event of the recent errors table.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function retryOne(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $id = (int) ($trigger['#item_id'] ?? 0);
    $count = $id > 0 ? $this->outbox->retry([OutboxItem::STATUS_FAILED, OutboxItem::STATUS_DEAD], $id) : 0;
    if ($count > 0) {
      $this->messenger()->addStatus($this->t('The event will be retried.'));
    }
    else {
      $this->messenger()->addWarning($this->t('The event is no longer in error.'));
    }
  }

  /**
   * Submit handler: reconciles the knowledge bases.
   *
   * Runs within a time budget; unfinished reconciliations continue in the
   * background queue.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function reconcile(array &$form, FormStateInterface $form_state): void {
    $start = microtime(TRUE);
    $knowledgeBases = $this->reconciler->knowledgeBases();
    if ($knowledgeBases === []) {
      $this->messenger()->addWarning($this->t('There is no knowledge base to reconcile.'));
      return;
    }
    foreach ($knowledgeBases as $knowledgeBase) {
      $left = (int) ceil(self::RECONCILE_BUDGET - (microtime(TRUE) - $start));
      if ($left <= 0) {
        $this->reconciler->enqueue($knowledgeBase);
        $this->messenger()->addStatus($this->t('The reconciliation of %kb was scheduled.', ['%kb' => $knowledgeBase]));
        continue;
      }
      try {
        $result = $this->reconciler->step($knowledgeBase, $left);
      }
      catch (PlatformException $e) {
        $this->messenger()->addError($this->t('The reconciliation of %kb failed: @error', [
          '%kb' => $knowledgeBase,
          '@error' => $e->getMessage() . ($e->getCorrelationId() !== NULL ? ' (correlation ID ' . $e->getCorrelationId() . ')' : ''),
        ]));
        continue;
      }
      $report = $result['report'];
      if (!$result['finished']) {
        $this->reconciler->enqueue($knowledgeBase);
        $this->messenger()->addStatus($this->t('The reconciliation of %kb continues in the background.', ['%kb' => $knowledgeBase]));
        continue;
      }
      $this->messenger()->addStatus($this->t('%kb: @remote remote documents, @orphans orphans, @outdated outdated, @missing missing; @recorded events recorded.', [
        '%kb' => $knowledgeBase,
        '@remote' => $report['remote'],
        '@orphans' => $report['orphan_count'],
        '@outdated' => $report['outdated_count'],
        '@missing' => $report['missing_count'],
        '@recorded' => $report['recorded'],
      ]));
      foreach ($report['errors'] as $error) {
        $this->messenger()->addWarning((string) $error);
      }
    }
  }

  /**
   * Validation handler of "Index source".
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateIndex(array &$form, FormStateInterface $form_state): void {
    $source = $this->loadSource((string) $form_state->getValue('source'));
    if ($source === NULL) {
      $form_state->setErrorByName('source', $this->t('Choose an enabled source.'));
      return;
    }
    $bundle = trim((string) $form_state->getValue('bundle'));
    if ($bundle !== '' && (!preg_match('/^[a-z0-9_]+$/', $bundle) || ($source->getBundles() !== [] && !in_array($bundle, $source->getBundles(), TRUE)))) {
      $form_state->setErrorByName('bundle', $this->t('The bundle is not covered by the source.'));
    }
  }

  /**
   * Submit handler: indexes a source with the Batch API.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function indexSource(array &$form, FormStateInterface $form_state): void {
    $source = $this->loadSource((string) $form_state->getValue('source'));
    if ($source === NULL) {
      return;
    }
    $bundle = trim((string) $form_state->getValue('bundle'));
    $process = (bool) $form_state->getValue('process') && $this->syncProcessor->isEnabled();
    batch_set($this->indexer->createBatch($source, $bundle === '' ? NULL : $bundle, NULL, $process));
  }

  /**
   * Submit handler: migrates the legacy widget.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function migrateLegacy(array &$form, FormStateInterface $form_state): void {
    $report = $this->legacyMigrator->migrate();
    $this->messenger()->addStatus($this->t('Legacy widget migrated: @created blocks created, @disabled legacy blocks disabled, @settings settings copied.', [
      '@created' => count($report['created']),
      '@disabled' => count($report['disabled']),
      '@settings' => count($report['settings']),
    ]));
  }

  /**
   * Submit handler: rolls the legacy widget migration back.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function rollbackLegacy(array &$form, FormStateInterface $form_state): void {
    $report = $this->legacyMigrator->rollback();
    $this->messenger()->addStatus($this->t('Migration rolled back: @removed blocks removed, @enabled legacy blocks enabled again.', [
      '@removed' => count($report['removed']),
      '@enabled' => count($report['enabled']),
    ]));
  }

  /**
   * Shows the counters of a processing run.
   *
   * @param \Drupal\webassistant_connector\Sync\SyncReport $report
   *   The report.
   */
  private function reportMessages(SyncReport $report): void {
    if (!$this->syncProcessor->isEnabled()) {
      $this->messenger()->addWarning($this->t('Synchronization is disabled.'));
      return;
    }
    $this->messenger()->addStatus($this->t('@processed events processed: @upserted documents sent, @unchanged unchanged, @deleted deleted, @failed failed, @dead dead.', [
      '@processed' => $report->getProcessed(),
      '@upserted' => $report->getUpserted(),
      '@unchanged' => $report->getUnchanged(),
      '@deleted' => $report->getDeleted(),
      '@failed' => $report->getFailed(),
      '@dead' => $report->getDead(),
    ]));
    if ($report->isSuspended()) {
      $this->messenger()->addWarning($this->t('Processing stopped: the platform is not available or not configured. See the recent errors.'));
    }
  }

  /**
   * Loads an enabled source.
   *
   * @param string $id
   *   The source ID.
   *
   * @return \Drupal\webassistant_connector\Entity\SourceInterface|null
   *   The source, or NULL if missing or disabled.
   */
  private function loadSource(string $id): ?SourceInterface {
    if ($id === '') {
      return NULL;
    }
    $source = $this->entityTypeManager->getStorage('webassistant_source')->load($id);
    return $source instanceof SourceInterface && $source->status() ? $source : NULL;
  }

}
