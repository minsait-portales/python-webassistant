<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_commerce\Plugin\WebAssistant\HostAction;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;

/**
 * Opens the order administration for a sales report request.
 *
 * Params (sales_analytics skill): range (month, quarter, year, all, custom),
 * focus (revenue, categories, products, stores, orders, overview), and the
 * optional from/to dates (YYYY-MM-DD) of a custom range. Requires the
 * "use webassistant analytics actions" and "access commerce administration
 * pages" permissions; the result redirects to the order list (route
 * entity.commerce_order.collection) when the user can access it. No sales
 * figures are computed nor returned.
 */
#[HostAction(
  id: 'analytics.sales_report',
  label: new TranslatableMarkup('Sales report'),
  description: new TranslatableMarkup('Opens the order administration of Drupal Commerce.'),
  permission: 'use webassistant analytics actions',
)]
final class SalesReport extends HostActionPluginBase {

  /**
   * Permission of the Commerce administration pages.
   */
  public const ADMIN_PERMISSION = 'access commerce administration pages';

  /**
   * The order administration route.
   */
  public const ORDERS_ROUTE = 'entity.commerce_order.collection';

  /**
   * Accepted ranges.
   */
  public const RANGES = ['month', 'quarter', 'year', 'all', 'custom'];

  /**
   * Accepted focuses.
   */
  public const FOCUSES = ['revenue', 'categories', 'products', 'stores', 'orders', 'overview'];

  /**
   * Constructs a SalesReport plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Access\AccessManagerInterface $accessManager
   *   The access manager.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AccessManagerInterface $accessManager,
    protected UrlGeneratorInterface $urlGenerator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Also requires access to the Commerce administration pages.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return parent::access($account)->andIf(AccessResult::allowedIfHasPermission($account, self::ADMIN_PERMISSION));
  }

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $params = $request->params;
    $this->assertAllowedParams($params, ['range', 'focus', 'from', 'to']);
    $range = (string) $this->enumParam($params, 'range', self::RANGES);
    $focus = (string) $this->enumParam($params, 'focus', self::FOCUSES);
    $from = $this->dateParam($params, 'from');
    $to = $this->dateParam($params, 'to');
    if ($from !== NULL && $to !== NULL && $from > $to) {
      throw $this->failure($this->t('The start date must be before the end date.'));
    }
    if (!$this->accessManager->checkNamedRoute(self::ORDERS_ROUTE, [], $account)) {
      throw $this->failure($this->t('You are not allowed to view the order administration.'));
    }
    $url = $this->urlGenerator->generateFromRoute(self::ORDERS_ROUTE, [], [], TRUE)->getGeneratedUrl();
    $data = array_filter(['range' => $range, 'focus' => $focus, 'from' => $from, 'to' => $to], static fn (?string $value): bool => $value !== NULL);
    return HostActionResult::succeeded($data, $this->t('Opening the order administration.'), $url);
  }

  /**
   * Reads an optional calendar date (YYYY-MM-DD).
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   *
   * @return string|null
   *   The date, or NULL when absent.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When it is not a valid date.
   */
  private function dateParam(array $params, string $name): ?string {
    $value = $this->stringParam($params, $name, FALSE, 10);
    if ($value === NULL) {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $date === FALSE || $date->format('Y-m-d') !== $value) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    return $value;
  }

}
