<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SourceMatcher;

/**
 * Change capture into the outbox, and cron (design section 9.2).
 *
 * For each content entity with enabled sources (SourceMatcher::forEntity()):
 * insert/update of the default revision records an upsert per affected
 * translation (all translations on update); non-default revisions are
 * ignored; delete records a delete per translation; translation_delete
 * records a delete of that language. Never performs HTTP.
 */
final class SyncHooks {

  /**
   * Constructs a SyncHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\Reconciler $reconciler
   *   The reconciler (cron scheduling).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SourceMatcher $sourceMatcher,
    private readonly Outbox $outbox,
    private readonly Reconciler $reconciler,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    // @todo Implement (sync engineer).
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    // @todo Implement (sync engineer).
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    // @todo Implement (sync engineer).
  }

  /**
   * Implements hook_entity_translation_insert().
   */
  #[Hook('entity_translation_insert')]
  public function entityTranslationInsert(EntityInterface $translation): void {
    // @todo Implement (sync engineer).
  }

  /**
   * Implements hook_entity_translation_delete().
   */
  #[Hook('entity_translation_delete')]
  public function entityTranslationDelete(EntityInterface $translation): void {
    // @todo Implement (sync engineer).
  }

  /**
   * Implements hook_cron().
   *
   * Releases stale processing events, purges done events older than
   * sync.retention_days, enqueues due reconciliations and a dispatch ticket.
   */
  #[Hook('cron')]
  public function cron(): void {
    // @todo Implement (sync engineer).
  }

}
