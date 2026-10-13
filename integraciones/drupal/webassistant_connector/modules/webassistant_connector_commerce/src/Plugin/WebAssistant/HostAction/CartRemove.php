<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Drupal\webassistant_connector_commerce\HostAction\CartActionBase;

/**
 * Removes a product from the shopping cart.
 *
 * Params: product_id (required), variation_id (optional: without it every
 * option of the product is removed). Only lines of the user's carts change.
 */
#[HostAction(
  id: 'cart.remove',
  label: new TranslatableMarkup('Remove from cart'),
  description: new TranslatableMarkup('Removes a product from the shopping cart of the user.'),
  permission: 'use webassistant cart actions',
  effects: TRUE,
)]
final class CartRemove extends CartActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $params = $request->params;
    $this->assertAllowedParams($params, ['product_id', 'variation_id']);
    [$productId, $variationId] = $this->productParams($params);

    $this->cart->remove($productId, $variationId, $account);
    return $this->cartResult($account, $this->t('The product was removed from the cart.'));
  }

}
