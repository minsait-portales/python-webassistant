<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Drupal\webassistant_connector_commerce\Cart\CartService;
use Drupal\webassistant_connector_commerce\HostAction\CartActionBase;

/**
 * Changes the quantity of a product of the shopping cart.
 *
 * Params: product_id (required), variation_id (optional), quantity (0..99,
 * required; 0 removes the product). Only lines of the user's carts change.
 */
#[HostAction(
  id: 'cart.update',
  label: new TranslatableMarkup('Update cart quantity'),
  description: new TranslatableMarkup('Changes the quantity of a product in the shopping cart of the user.'),
  permission: 'use webassistant cart actions',
  effects: TRUE,
)]
final class CartUpdate extends CartActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $params = $request->params;
    $this->assertAllowedParams($params, ['product_id', 'variation_id', 'quantity']);
    [$productId, $variationId] = $this->productParams($params);
    $quantity = (int) $this->intParam($params, 'quantity', TRUE, 0, CartService::MAX_QUANTITY);

    $this->cart->update($productId, $variationId, $quantity, $account);
    $message = $quantity === 0
      ? $this->t('The product was removed from the cart.')
      : $this->formatPlural($quantity, 'Quantity updated to 1.', 'Quantity updated to @count.');
    return $this->cartResult($account, $message);
  }

}
