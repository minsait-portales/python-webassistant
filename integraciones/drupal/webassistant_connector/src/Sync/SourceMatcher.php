<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds the enabled sources that cover an entity (design section 9.2).
 *
 * The enabled sources are kept in the memory cache bin with the list cache
 * tag of the source entity type (config:webassistant_source_list), so
 * saving or deleting a source invalidates them within the same request or
 * Drush process. Matches per entity type and bundle are memoized on top.
 *
 * Service: webassistant_connector.source_matcher.
 */
final class SourceMatcher {

  /**
   * Cache ID of the enabled sources in the memory bin.
   */
  private const CID = 'webassistant_connector:enabled_sources';

  /**
   * Sources per "entity_type:bundle", valid while the cached list is.
   *
   * @var array<string, array<string, \Drupal\webassistant_connector\Entity\SourceInterface>>
   */
  private array $byBundle = [];

  /**
   * Constructs a SourceMatcher.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $memoryCache
   *   The memory cache bin (invalidated by cache tags).
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'cache.memory')]
    private readonly CacheBackendInterface $memoryCache,
  ) {}

  /**
   * Returns the enabled sources whose appliesTo() accepts the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity (only content entities can match).
   *
   * @return array<string, \Drupal\webassistant_connector\Entity\SourceInterface>
   *   Sources keyed by ID.
   */
  public function forEntity(EntityInterface $entity): array {
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    $sources = $this->getActiveSources();
    $key = $entity->getEntityTypeId() . ':' . $entity->bundle();
    if (!isset($this->byBundle[$key])) {
      $this->byBundle[$key] = array_filter($sources, static fn (SourceInterface $source): bool => $source->appliesTo($entity));
    }
    return $this->byBundle[$key];
  }

  /**
   * Returns every enabled source.
   *
   * @return array<string, \Drupal\webassistant_connector\Entity\SourceInterface>
   *   Sources keyed by ID, sorted by ID.
   */
  public function getActiveSources(): array {
    $cached = $this->memoryCache->get(self::CID);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }
    $this->byBundle = [];
    $storage = $this->entityTypeManager->getStorage('webassistant_source');
    $sources = [];
    foreach ($storage->loadMultiple() as $id => $source) {
      if ($source instanceof SourceInterface && $source->status()) {
        $sources[(string) $id] = $source;
      }
    }
    ksort($sources);
    $this->memoryCache->set(self::CID, $sources, CacheBackendInterface::CACHE_PERMANENT, $storage->getEntityType()->getListCacheTags());
    return $sources;
  }

  /**
   * Returns the enabled sources that send documents to a knowledge base.
   *
   * @param string $knowledgeBase
   *   The knowledge base key.
   *
   * @return array<string, \Drupal\webassistant_connector\Entity\SourceInterface>
   *   Sources keyed by ID.
   */
  public function forKnowledgeBase(string $knowledgeBase): array {
    return array_filter($this->getActiveSources(), static fn (SourceInterface $source): bool => $source->getKnowledgeBase() === $knowledgeBase);
  }

  /**
   * Returns the distinct knowledge bases of the enabled sources.
   *
   * Includes knowledge bases set in third-party settings of submodules
   * (a "knowledge_base" key of a module whose "enabled" key is not FALSE,
   * e.g. a separate knowledge base for attachments).
   *
   * @return list<string>
   *   Sorted knowledge base keys.
   */
  public function getKnowledgeBases(): array {
    $keys = [];
    foreach ($this->getActiveSources() as $source) {
      $keys[$source->getKnowledgeBase()] = TRUE;
      foreach ($source->getThirdPartyProviders() as $module) {
        $settings = $source->getThirdPartySettings($module);
        if (array_key_exists('enabled', $settings) && !$settings['enabled']) {
          continue;
        }
        if (isset($settings['knowledge_base']) && is_string($settings['knowledge_base'])) {
          $keys[$settings['knowledge_base']] = TRUE;
        }
      }
    }
    $keys = array_filter(array_map('strval', array_keys($keys)), static fn (string $key): bool => (bool) preg_match('/^[a-z][a-z0-9_-]{1,62}$/', $key));
    sort($keys);
    return array_values($keys);
  }

  /**
   * Whether any enabled source targets an entity type.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if at least one source targets it.
   */
  public function hasSourcesFor(string $entityTypeId): bool {
    foreach ($this->getActiveSources() as $source) {
      if ($source->getTargetEntityTypeId() === $entityTypeId) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Forgets the cached sources (they are reloaded on the next call).
   */
  public function reset(): void {
    $this->memoryCache->delete(self::CID);
    $this->byBundle = [];
  }

}
