<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;

/**
 * Base class for normalizer plugins (design section 8.2).
 *
 * Subclasses declare #[Normalizer(...)], implement normalize() and may add
 * services to the constructor after the three plugin arguments: create() is
 * autowired.
 */
abstract class NormalizerPluginBase extends PluginBase implements NormalizerInterface, ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

  /**
   * {@inheritdoc}
   *
   * Uses the entity_types of the definition (empty: any entity type).
   */
  public function applies(string $entityTypeId): bool {
    $definition = $this->getPluginDefinition();
    $entityTypes = is_array($definition) ? ($definition['entity_types'] ?? []) : [];
    return $entityTypes === [] || in_array($entityTypeId, $entityTypes, TRUE);
  }

  /**
   * Returns the human-readable label.
   */
  public function label(): string {
    $definition = $this->getPluginDefinition();
    return (string) (is_array($definition) ? ($definition['label'] ?? $this->getPluginId()) : $this->getPluginId());
  }

}
