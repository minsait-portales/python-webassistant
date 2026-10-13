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
 * Returns the shopping cart (automatic action of the cart@1 presentation).
 *
 * Params: none. Data: {items: product_item[], total, items_count}.
 */
#[HostAction(
  id: 'cart.get',
  label: new TranslatableMarkup('Get the cart'),
  description: new TranslatableMarkup('Returns the products, quantities and total of the shopping cart.'),
  permission: 'use webassistant cart actions',
)]
final class CartGet extends CartActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $this->assertAllowedParams($request->params, []);
    return $this->cartResult($account);
  }

}
