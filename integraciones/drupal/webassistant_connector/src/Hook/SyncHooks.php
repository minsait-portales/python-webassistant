<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SourceMatcher;

/**
 * Change capture into the outbox (design 9.2, amendments A2 and A7).
 *
 * For each content entity covered by enabled sources: an insert records an
 * upsert for every translation; an update of the default revision records
 * an upsert for every current translation and a delete for every
 * translation that disappeared; non-default revisions (drafts) are ignored;
 * a delete records a delete for every translation. The worker decides from
 * the current state, so translation hooks are not needed. Every capture is
 * a single merge inside the entity transaction; this class never performs
 * HTTP requests and keeps its dependencies light (cron lives in
 * SyncCronHooks).
 */
final class SyncHooks {

  /**
   * Constructs a SyncHooks object.
   *
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   */
  public function __construct(
    private readonly SourceMatcher $sourceMatcher,
    private readonly Outbox $outbox,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $sources = $this->sourcesFor($entity);
    if ($sources === [] || !$entity instanceof ContentEntityInterface || !self::isDefaultRevision($entity)) {
      return;
    }
    $this->recordAll($sources, $entity, array_keys($entity->getTranslationLanguages()), OutboxItem::OPERATION_UPSERT);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $sources = $this->sourcesFor($entity);
    if ($sources === [] || !$entity instanceof ContentEntityInterface || !self::isDefaultRevision($entity)) {
      return;
    }
    $current = array_map('strval', array_keys($entity->getTranslationLanguages()));
    $this->recordAll($sources, $entity, $current, OutboxItem::OPERATION_UPSERT);
    $original = $entity->getOriginal();
    if ($original instanceof ContentEntityInterface) {
      $removed = array_diff(array_map('strval', array_keys($original->getTranslationLanguages())), $current);
      $this->recordAll($sources, $entity, $removed, OutboxItem::OPERATION_DELETE);
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $sources = $this->sourcesFor($entity);
    if ($sources === [] || !$entity instanceof ContentEntityInterface) {
      return;
    }
    $this->recordAll($sources, $entity, array_keys($entity->getTranslationLanguages()), OutboxItem::OPERATION_DELETE);
  }

  /**
   * Returns the enabled sources covering an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array<string, \Drupal\webassistant_connector\Entity\SourceInterface>
   *   The sources keyed by ID.
   */
  private function sourcesFor(EntityInterface $entity): array {
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    return $this->sourceMatcher->forEntity($entity);
  }

  /**
   * Records an operation for translations covered by each source.
   *
   * @param array<string, \Drupal\webassistant_connector\Entity\SourceInterface> $sources
   *   The sources.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param array<int|string> $langcodes
   *   The language codes.
   * @param string $operation
   *   The operation hint.
   */
  private function recordAll(array $sources, ContentEntityInterface $entity, array $langcodes, string $operation): void {
    foreach ($sources as $source) {
      foreach ($langcodes as $langcode) {
        $langcode = (string) $langcode;
        if ($source->appliesToLangcode($langcode)) {
          $this->outbox->record($operation, $source, $entity, $langcode);
        }
      }
    }
  }

  /**
   * Whether the entity is its default revision (drafts are ignored).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   FALSE for a non-default (forward) revision.
   */
  private static function isDefaultRevision(ContentEntityInterface $entity): bool {
    return $entity->isDefaultRevision();
  }

}
