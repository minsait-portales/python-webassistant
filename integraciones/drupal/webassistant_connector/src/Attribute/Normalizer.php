<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a WebAssistant normalizer plugin (design section 8.2).
 *
 * Plugins live in the Plugin/WebAssistant/Normalizer namespace of any module
 * and implement \Drupal\webassistant_connector\Normalizer\NormalizerInterface
 * (usually by extending NormalizerPluginBase).
 *
 * @see \Drupal\webassistant_connector\Normalizer\NormalizerManager
 * @see hook_webassistant_normalizer_info_alter()
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Normalizer extends Plugin {

  /**
   * Constructs a Normalizer attribute.
   *
   * @param string $id
   *   The plugin ID, e.g. "content_entity".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A description for administrators.
   * @param list<string> $entity_types
   *   (optional) Entity type IDs the normalizer supports; empty means any
   *   content entity type.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly array $entity_types = [],
    public readonly ?string $deriver = NULL,
  ) {}

}
