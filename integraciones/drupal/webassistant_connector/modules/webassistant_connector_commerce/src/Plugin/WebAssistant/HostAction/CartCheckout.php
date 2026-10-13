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
 * Takes the user to the checkout of the shopping cart.
 *
 * Params: none. Redirects to the checkout form of the cart
 * (commerce_checkout.form) when the user may check it out, otherwise to the
 * cart page. Fails when the cart is empty.
 */
#[HostAction(
  id: 'cart.checkout',
  label: new TranslatableMarkup('Go to checkout'),
  description: new TranslatableMarkup('Opens the checkout of the shopping cart, or the cart page.'),
  permission: 'use webassistant cart actions',
)]
final class CartCheckout extends CartActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $this->assertAllowedParams($request->params, []);
    $url = $this->cart->checkoutUrl($account);
    $message = $url === $this->cart->cartUrl()
      ? $this->t('Opening your cart to complete the purchase.')
      : $this->t('Opening the checkout.');
    return $this->cartResult($account, $message, [], $url);
  }

}
