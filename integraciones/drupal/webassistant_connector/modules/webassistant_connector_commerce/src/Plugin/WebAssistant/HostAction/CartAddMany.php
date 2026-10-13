<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionException;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Drupal\webassistant_connector_commerce\Cart\CartService;
use Drupal\webassistant_connector_commerce\HostAction\CartActionBase;

/**
 * Adds several products to the shopping cart, with a partial report.
 *
 * Params: items (1..100 objects {product_id, variation_id?, quantity?}).
 * Each item is validated and added on its own: the result succeeds when at
 * least one item was added and lists the failed ones (data.failed with
 * user-safe messages); it fails when none could be added.
 */
#[HostAction(
  id: 'cart.add_many',
  label: new TranslatableMarkup('Add several products to cart'),
  description: new TranslatableMarkup('Adds a selection of products to the shopping cart of the user.'),
  permission: 'use webassistant cart actions',
  effects: TRUE,
)]
final class CartAddMany extends CartActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $params = $request->params;
    $this->assertAllowedParams($params, ['items']);
    $list = (array) $this->listParam($params, 'items', TRUE, CartService::MAX_ITEMS);

    $items = [];
    $invalid = [];
    foreach ($list as $position => $item) {
      try {
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
          throw $this->failure($this->t('The item is not valid.'));
        }
        $this->assertAllowedParams($item, ['product_id', 'variation_id', 'quantity']);
        [$productId, $variationId] = $this->productParams($item);
        $items[] = [
          'product_id' => $productId,
          'variation_id' => $variationId,
          'quantity' => (int) ($this->intParam($item, 'quantity', FALSE, 1, CartService::MAX_QUANTITY) ?? 1),
        ];
      }
      catch (HostActionException $exception) {
        $failure = ['position' => $position];
        $productId = is_array($item) ? ($item['product_id'] ?? NULL) : NULL;
        if (is_string($productId) || is_int($productId)) {
          $failure['product_id'] = mb_substr((string) $productId, 0, CartService::MAX_ID_LENGTH);
        }
        $failure['message'] = $exception->getMessage();
        $invalid[] = $failure;
      }
    }

    $report = $items === [] ? ['added' => [], 'failed' => []] : $this->cart->addMany($items, $account);
    $failed = array_merge($invalid, $report['failed']);
    $added = count($report['added']);
    $data = ['added' => $report['added'], 'failed' => $failed];
    if ($added === 0) {
      throw $this->failure($this->t('No product could be added to the cart.'), $data);
    }
    $message = (string) $this->formatPlural($added, '1 product added to the cart.', '@count products added to the cart.');
    if ($failed !== []) {
      $message .= ' ' . $this->formatPlural(count($failed), '1 product could not be added.', '@count products could not be added.');
    }
    return $this->cartResult($account, $message, $data);
  }

}
