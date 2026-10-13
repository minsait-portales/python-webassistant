<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\HostAction;

use Drupal\Core\Session\AccountInterface;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Drupal\webassistant_connector_commerce\Cart\CartService;

/**
 * Base class of the cart host actions (design section 12).
 *
 * Every cart action answers with the real cart recalculated by Drupal
 * Commerce: {items: product_item[], total, items_count} (cart@1 data).
 * Parameters follow the action schemas of the platform skills
 * (additionalProperties: false); product_id and variation_id are at most
 * 128 characters, quantities are integers.
 */
abstract class CartActionBase extends HostActionPluginBase {

  /**
   * Constructs a cart host action.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\webassistant_connector_commerce\Cart\CartService $cart
   *   The cart service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected CartService $cart,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Builds a successful result carrying the current cart.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param string|\Stringable|null $message
   *   A user-facing message.
   * @param array<string, mixed> $extra
   *   Extra data merged after the cart view.
   * @param string|null $redirect
   *   A root-relative URL to navigate to.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result.
   */
  protected function cartResult(AccountInterface $account, string|\Stringable|null $message = NULL, array $extra = [], ?string $redirect = NULL): HostActionResult {
    return HostActionResult::succeeded($this->cart->view($account) + $extra, $message, $redirect);
  }

  /**
   * Reads the product_id and variation_id parameters.
   *
   * @param array<string, mixed> $params
   *   The parameters.
   *
   * @return array{0: string, 1: string|null}
   *   The product_id and the variation_id (NULL when absent or null).
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function productParams(array $params): array {
    $productId = (string) $this->idParam($params, 'product_id', TRUE, CartService::MAX_ID_LENGTH);
    $variationId = $this->idParam($params, 'variation_id', FALSE, CartService::MAX_ID_LENGTH);
    return [$productId, $variationId];
  }

}
