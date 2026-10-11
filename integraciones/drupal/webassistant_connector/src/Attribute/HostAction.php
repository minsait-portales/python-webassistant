<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a WebAssistant host action plugin (design section 7).
 *
 * Plugins live in the Plugin/WebAssistant/HostAction namespace of any module
 * and implement \Drupal\webassistant_connector\HostAction\HostActionInterface
 * (usually by extending HostActionPluginBase). The plugin ID is the host
 * action name sent by the widget, e.g. "cart.add".
 *
 * @see \Drupal\webassistant_connector\HostAction\HostActionManager
 * @see hook_webassistant_host_action_info_alter()
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class HostAction extends Plugin {

  /**
   * Constructs a HostAction attribute.
   *
   * @param string $id
   *   The host action name, e.g. "content.create". It must match
   *   [a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A description for administrators.
   * @param string|null $permission
   *   (optional) The Drupal permission required, checked by
   *   HostActionPluginBase::access() in addition to the plugin conditions.
   * @param bool $effects
   *   (optional) Whether the action changes data (cart, content...).
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $permission = NULL,
    public readonly bool $effects = FALSE,
    public readonly ?string $deriver = NULL,
  ) {}

}
