<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SourceMatcher;
use Drupal\webassistant_connector\Sync\SyncProcessor;

/**
 * Buttons of the status page (design section 10).
 *
 * Process now, Retry failed, Reconcile and Index source (Batch API through
 * Indexer::createBatch()).
 */
final class StatusActionsForm extends FormBase {

  use AutowireTrait;

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
   */
  public function __construct(
    private readonly SyncProcessor $syncProcessor,
    private readonly Reconciler $reconciler,
    private readonly Indexer $indexer,
    private readonly Outbox $outbox,
    private readonly SourceMatcher $sourceMatcher,
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
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    throw new \LogicException('Not implemented yet.');
  }

}
