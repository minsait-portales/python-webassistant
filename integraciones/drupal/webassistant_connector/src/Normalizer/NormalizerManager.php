<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\webassistant_connector\Attribute\Normalizer;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Plugin manager of normalizers (design section 8.2).
 *
 * Service: plugin.manager.webassistant_connector.normalizer. Plugins live in
 * Plugin/WebAssistant/Normalizer and use the #[Normalizer] attribute.
 * Definitions can be altered with hook_webassistant_normalizer_info_alter().
 *
 * @method \Drupal\webassistant_connector\Normalizer\NormalizerInterface createInstance($plugin_id, array $configuration = [])
 */
final class NormalizerManager extends DefaultPluginManager {

  /**
   * Constructs a NormalizerManager.
   *
   * @param \Traversable<string, string> $namespaces
   *   The namespaces to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/WebAssistant/Normalizer', $namespaces, $module_handler, NormalizerInterface::class, Normalizer::class);
    $this->alterInfo('webassistant_normalizer_info');
    $this->setCacheBackend($cache_backend, 'webassistant_connector_normalizer_plugins');
  }

  /**
   * Returns the normalizer configured in a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   *
   * @return \Drupal\webassistant_connector\Normalizer\NormalizerInterface
   *   The normalizer.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   When the plugin does not exist.
   */
  public function getNormalizer(SourceInterface $source): NormalizerInterface {
    return $this->createInstance($source->getNormalizerId());
  }

  /**
   * Returns normalizer labels, optionally restricted to an entity type.
   *
   * @param string|null $entityTypeId
   *   The entity type ID, or NULL for every normalizer.
   *
   * @return array<string, string>
   *   Labels keyed by plugin ID, sorted by label.
   */
  public function getOptions(?string $entityTypeId = NULL): array {
    $options = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $entityTypes = $definition['entity_types'] ?? [];
      if ($entityTypeId !== NULL && $entityTypes !== [] && !in_array($entityTypeId, $entityTypes, TRUE)) {
        continue;
      }
      $options[$id] = (string) ($definition['label'] ?? $id);
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

}
