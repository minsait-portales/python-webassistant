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
 * Adds a product to the shopping cart.
 *
 * Params: product_id (required), variation_id (optional, null allowed),
 * quantity (1..99, default 1). The variation must be published and viewable
 * by the user; the store is chosen as Commerce's add to cart form does.
 */
#[HostAction(
  id: 'cart.add',
  label: new TranslatableMarkup('Add to cart'),
  description: new TranslatableMarkup('Adds a product to the shopping cart of the user.'),
  permission: 'use webassistant cart actions',
  effects: TRUE,
)]
final class CartAdd extends CartActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $params = $request->params;
    $this->assertAllowedParams($params, ['product_id', 'variation_id', 'quantity']);
    [$productId, $variationId] = $this->productParams($params);
    $quantity = (int) ($this->intParam($params, 'quantity', FALSE, 1, CartService::MAX_QUANTITY) ?? 1);

    $variation = $this->cart->resolveVariation($productId, $variationId, $account);
    $orderItem = $this->cart->add($variation, $quantity, $account);
    $title = trim((string) $orderItem->getTitle()) ?: (string) $variation->label();
    return $this->cartResult(
      $account,
      $this->formatPlural($quantity, 'Added "@title" to the cart.', 'Added @count units of "@title" to the cart.', ['@title' => $title]),
      [
        'added' => [
          'product_id' => (string) $variation->getProductId(),
          'variation_id' => (string) $variation->id(),
          'quantity' => $quantity,
        ],
      ],
    );
  }

}
