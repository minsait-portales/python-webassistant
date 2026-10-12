<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\Indexer;
use Drupal\webassistant_connector\Sync\SourceMatcher;

/**
 * Reacts to changes of webassistant_source entities (amendments A9, E4).
 *
 * Enabling a source, or changing what it covers or how it maps documents
 * (mapping hash, bundles, langcodes, knowledge base, ACL mode, normalizer,
 * status), schedules a background reindex through the index queue: events
 * for every covered entity (if the source is enabled) and for every mapped
 * document of the source (the worker deletes what is out of scope).
 * Disabling or deleting a source records events for its mapped documents,
 * which the worker deletes because the source is disabled or missing.
 */
final class SourceLifecycleHooks {

  /**
   * Constructs a SourceLifecycleHooks object.
   *
   * @param \Drupal\webassistant_connector\Sync\Indexer $indexer
   *   The indexer.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   */
  public function __construct(
    private readonly Indexer $indexer,
    private readonly SourceMatcher $sourceMatcher,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for webassistant_source.
   */
  #[Hook('webassistant_source_insert')]
  public function sourceInsert(SourceInterface $source): void {
    $this->sourceMatcher->reset();
    if ($source->status()) {
      $this->indexer->scheduleReindex((string) $source->id(), TRUE, FALSE, 'source created');
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for webassistant_source.
   */
  #[Hook('webassistant_source_update')]
  public function sourceUpdate(SourceInterface $source): void {
    $this->sourceMatcher->reset();
    $original = $source->getOriginal();
    if ($original instanceof SourceInterface && !self::scopeChanged($original, $source)) {
      return;
    }
    $this->indexer->scheduleReindex((string) $source->id(), $source->status(), TRUE, 'source changed');
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for webassistant_source.
   */
  #[Hook('webassistant_source_delete')]
  public function sourceDelete(SourceInterface $source): void {
    $this->sourceMatcher->reset();
    $this->indexer->scheduleReindex((string) $source->id(), FALSE, TRUE, 'source deleted');
  }

  /**
   * Whether a change affects the documents of a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $original
   *   The source before the change.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source after the change.
   *
   * @return bool
   *   TRUE if the status, the scope or the mapping changed.
   */
  public static function scopeChanged(SourceInterface $original, SourceInterface $source): bool {
    return $original->status() !== $source->status()
      || $original->getMappingHash() !== $source->getMappingHash();
  }

}
