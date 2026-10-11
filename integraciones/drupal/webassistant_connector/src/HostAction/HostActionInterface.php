<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * A host action executed by Drupal on behalf of the widget (section 7).
 *
 * The payload is untrusted: implementations re-check permissions and entity
 * access, validate every parameter and must be idempotent (the dispatcher
 * also replays results by execution_id).
 */
interface HostActionInterface extends PluginInspectionInterface {

  /**
   * Checks whether the account may run the action.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The definition permission plus the plugin conditions (e.g. a module
   *   being installed). Cacheable metadata must be added where relevant.
   */
  public function access(AccountInterface $account): AccessResultInterface;

  /**
   * Executes the action.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionRequest $request
   *   The validated host action request.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result returned to the widget.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   On invalid parameters or business errors; the message is safe to show
   *   to the user and the dispatcher turns it into a failed result.
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult;

  /**
   * Returns the human-readable label.
   */
  public function label(): string;

  /**
   * Whether the action changes data.
   */
  public function hasEffects(): bool;

}
