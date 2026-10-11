<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;
use Drupal\webassistant_connector\Widget\WidgetAttachmentBuilder;

/**
 * Places a WebAssistant widget instance (design section 6).
 *
 * Renders a mount point <div class="webassistant-connector"
 * data-webassistant-instance="..."> and, optionally, the results container
 * <div id="..." class="webassistant-connector__results" tabindex="-1">.
 * Attachments and cacheability come from WidgetAttachmentBuilder::build().
 */
#[Block(
  id: 'webassistant_connector_widget',
  admin_label: new TranslatableMarkup('WebAssistant widget'),
  category: new TranslatableMarkup('Minsait'),
)]
final class WidgetBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

  /**
   * ID of the results container when no selector is configured.
   */
  public const DEFAULT_RESULT_CONTAINER_ID = 'webassistant-connector-results';

  /**
   * Pattern of an ID selector usable for the rendered results container.
   */
  public const ID_SELECTOR_PATTERN = '/^#([A-Za-z][A-Za-z0-9_-]{0,127})$/';

  /**
   * Pattern of an acceptable CSS selector (sanity check, not a full parser).
   */
  public const SELECTOR_PATTERN = '/^[A-Za-z0-9_\-#.:\[\]=\'" >+~*(),]{1,255}$/';

  /**
   * Constructs a WidgetBlock.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\webassistant_connector\Widget\WidgetAttachmentBuilder $attachmentBuilder
   *   The widget attachment builder.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected WidgetAttachmentBuilder $attachmentBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'assistant_id' => '',
      'result_target' => '',
      'render_result_container' => FALSE,
      'position' => '',
      'open' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['assistant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Assistant ID'),
      '#description' => $this->t('Public ID of the assistant of this instance. Leave empty to use the one of the connector settings (or the site default).'),
      '#default_value' => $this->configuration['assistant_id'],
      '#maxlength' => 64,
      '#size' => 32,
    ];
    $form['result_target'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Results container selector'),
      '#description' => $this->t('CSS selector of the element where the widget shows its results, for example %example. Leave empty to use the connector settings (or the widget panel).', ['%example' => '#main-results']),
      '#default_value' => $this->configuration['result_target'],
      '#maxlength' => 255,
    ];
    $form['render_result_container'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render the results container in this block'),
      '#description' => $this->t('Outputs an empty results container after the widget mount point. The selector above must then be an ID selector (for example %example); when empty, %default is used.', [
        '%example' => '#main-results',
        '%default' => '#' . self::DEFAULT_RESULT_CONTAINER_ID,
      ]),
      '#default_value' => $this->configuration['render_result_container'],
    ];
    $form['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Position'),
      '#options' => [
        '' => $this->t('Connector settings'),
        'left' => $this->t('Left'),
        'right' => $this->t('Right'),
      ],
      '#default_value' => $this->configuration['position'],
    ];
    $form['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open on load'),
      '#default_value' => $this->configuration['open'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    $assistantId = trim((string) $form_state->getValue('assistant_id'));
    if ($assistantId !== '' && !preg_match(WidgetAttachmentBuilder::ID_PATTERN, $assistantId)) {
      $form_state->setErrorByName('assistant_id', $this->t('The assistant ID must start with a lowercase letter and contain only lowercase letters, digits, hyphens and underscores (2 to 64 characters).'));
    }
    $selector = trim((string) $form_state->getValue('result_target'));
    if ($selector !== '' && !self::isSafeSelector($selector)) {
      $form_state->setErrorByName('result_target', $this->t('The results container selector is not a valid CSS selector.'));
    }
    elseif ($selector !== '' && $form_state->getValue('render_result_container') && !preg_match(self::ID_SELECTOR_PATTERN, $selector)) {
      $form_state->setErrorByName('result_target', $this->t('To render the results container, the selector must be an ID selector such as %example.', ['%example' => '#main-results']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['assistant_id'] = trim((string) $form_state->getValue('assistant_id'));
    $this->configuration['result_target'] = trim((string) $form_state->getValue('result_target'));
    $this->configuration['render_result_container'] = (bool) $form_state->getValue('render_result_container');
    $position = (string) $form_state->getValue('position');
    $this->configuration['position'] = in_array($position, ['left', 'right'], TRUE) ? $position : '';
    $this->configuration['open'] = (bool) $form_state->getValue('open');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $instance = $this->instance();
    $build = $this->attachmentBuilder->build($instance);
    if (!isset($build['#attached'])) {
      // The widget is disabled or not configured: render nothing, keep the
      // cacheability so the block appears when the settings change.
      return $build;
    }
    $build['mount'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'class' => ['webassistant-connector'],
        'data-webassistant-instance' => $this->attachmentBuilder->instanceKey($instance),
      ],
    ];
    $containerId = $this->resultContainerId();
    if ($containerId !== NULL) {
      $build['results'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '',
        '#attributes' => [
          'id' => $containerId,
          'class' => ['webassistant-connector__results'],
          'tabindex' => '-1',
        ],
      ];
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), WidgetAttachmentBuilder::CACHE_CONTEXTS);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['config:' . WidgetAttachmentBuilder::CONFIG_NAME]);
  }

  /**
   * Whether a CSS selector passes the sanity check.
   *
   * @param string $selector
   *   The selector.
   *
   * @return bool
   *   TRUE if it only has selector characters and starts with a simple
   *   selector (not with a combinator).
   */
  public static function isSafeSelector(string $selector): bool {
    return preg_match(self::SELECTOR_PATTERN, $selector) === 1
      && preg_match('/^[A-Za-z#.\[:*]/', $selector) === 1;
  }

  /**
   * Returns the instance overrides passed to the attachment builder.
   *
   * @return array<string, mixed>
   *   assistant_id, result_target, open and position.
   */
  private function instance(): array {
    $resultTarget = trim((string) $this->configuration['result_target']);
    if ($resultTarget === '' && $this->configuration['render_result_container']) {
      $resultTarget = '#' . self::DEFAULT_RESULT_CONTAINER_ID;
    }
    return [
      'assistant_id' => (string) $this->configuration['assistant_id'],
      'result_target' => $resultTarget,
      'open' => (bool) $this->configuration['open'],
      'position' => (string) $this->configuration['position'],
    ];
  }

  /**
   * Returns the ID of the results container to render, if any.
   *
   * @return string|null
   *   The element ID, or NULL when the block does not render the container.
   */
  private function resultContainerId(): ?string {
    if (!$this->configuration['render_result_container']) {
      return NULL;
    }
    $resultTarget = $this->instance()['result_target'];
    return preg_match(self::ID_SELECTOR_PATTERN, $resultTarget, $matches) ? $matches[1] : NULL;
  }

}
