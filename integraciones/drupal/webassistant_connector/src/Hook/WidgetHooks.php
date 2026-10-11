<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\webassistant_connector\Widget\WidgetAttachmentBuilder;

/**
 * Widget hooks (design section 6).
 */
final class WidgetHooks {

  /**
   * Constructs a WidgetHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Widget\WidgetAttachmentBuilder $attachmentBuilder
   *   The widget attachment builder.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly WidgetAttachmentBuilder $attachmentBuilder,
  ) {}

  /**
   * Implements hook_page_attachments().
   *
   * Attaches the default instance when enabled, widget.placement is global
   * and WidgetAttachmentBuilder::isVisibleOnCurrentRoute(); merges the
   * cacheability of the decision in every case (amendment C1).
   *
   * @param array $attachments
   *   The page attachments (#attached and #cache).
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $metadata = BubbleableMetadata::createFromRenderArray($attachments)
      ->merge(BubbleableMetadata::createFromObject($this->attachmentBuilder->getCacheableMetadata()));

    $config = $this->configFactory->get(WidgetAttachmentBuilder::CONFIG_NAME);
    if ($config->get('enabled') && $config->get('widget.placement') === 'global' && $this->attachmentBuilder->isVisibleOnCurrentRoute()) {
      $metadata = $metadata->merge(BubbleableMetadata::createFromRenderArray($this->attachmentBuilder->build()));
    }

    $metadata->applyTo($attachments);
  }

}
