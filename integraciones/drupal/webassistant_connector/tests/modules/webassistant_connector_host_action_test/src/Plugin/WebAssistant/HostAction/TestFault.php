<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_host_action_test\Plugin\WebAssistant\HostAction;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;

/**
 * Test action with effects: counts runs, may fail or redirect anywhere.
 *
 * Params: mode = "throw" | "external" | "ok". Each run increments the state
 * value "webassistant_connector_host_action_test.runs.effects".
 */
#[HostAction(
  id: 'test.fault',
  label: new TranslatableMarkup('Test fault'),
  effects: TRUE,
)]
class TestFault extends HostActionPluginBase {

  /**
   * Constructs a TestFault plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $key = 'webassistant_connector_host_action_test.runs.' . ($this->hasEffects() ? 'effects' : 'safe');
    $this->state->set($key, (int) $this->state->get($key, 0) + 1);
    $mode = $this->enumParam($request->params, 'mode', ['throw', 'external', 'ok']);
    return match ($mode) {
      'throw' => throw new \RuntimeException('Internal failure with secret details.'),
      'external' => HostActionResult::succeeded([], 'Leaving.', 'https://evil.example/phish'),
      default => HostActionResult::succeeded([], 'OK <b>&amp;</b> done.', 'http://localhost/node/1?x=1'),
    };
  }

}
