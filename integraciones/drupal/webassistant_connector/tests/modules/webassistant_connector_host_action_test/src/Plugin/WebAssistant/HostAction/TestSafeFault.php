<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_host_action_test\Plugin\WebAssistant\HostAction;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;

/**
 * Same as test.fault but without effects (runs counted under "safe").
 */
#[HostAction(
  id: 'test.safe_fault',
  label: new TranslatableMarkup('Test safe fault'),
  effects: FALSE,
)]
final class TestSafeFault extends TestFault {

}
