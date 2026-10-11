<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists the host actions available to an account (design section 7).
 *
 * Service: webassistant_connector.host_action_registry. Used to fill
 * drupalSettings.webassistantConnector.hostActions.
 */
final class HostActionRegistry {

  /**
   * Permission required by the host action endpoint.
   */
  public const PERMISSION = 'use webassistant connector';

  /**
   * Constructs a HostActionRegistry.
   *
   * @param \Drupal\webassistant_connector\HostAction\HostActionManager $hostActionManager
   *   The host action plugin manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly HostActionManager $hostActionManager,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the host action names the account may execute.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return list<string>
   *   Sorted plugin IDs whose access() is allowed. Empty if the account lacks
   *   "use webassistant connector".
   */
  public function availableFor(AccountInterface $account): array {
    return $this->availableForWithCacheability($account)['names'];
  }

  /**
   * Returns the available names plus the cacheability of the access checks.
   *
   * The widget attachments apply the cacheability so that pages vary by the
   * same contexts as the access results (design amendment C1).
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array{names: list<string>, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   Sorted plugin IDs and the merged cacheability of their access results.
   */
  public function availableForWithCacheability(AccountInterface $account): array {
    $endpointAccess = AccessResult::allowedIfHasPermission($account, self::PERMISSION);
    $cacheability = CacheableMetadata::createFromObject($endpointAccess);
    // Plugin discovery depends on the installed modules and their plugins.
    $cacheability->addCacheTags(['webassistant_connector_host_action_plugins']);
    if (!$endpointAccess->isAllowed()) {
      return ['names' => [], 'cacheability' => $cacheability];
    }

    $names = [];
    foreach (array_keys($this->hostActionManager->getDefinitions()) as $id) {
      $id = (string) $id;
      if (!preg_match(HostActionRequest::NAME_PATTERN, $id)) {
        continue;
      }
      try {
        $access = $this->hostActionManager->createInstance($id)->access($account);
      }
      catch (PluginException $e) {
        $this->logger->error('Host action @name could not be instantiated: @message', [
          '@name' => $id,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $names[] = $id;
      }
    }
    sort($names);
    return ['names' => $names, 'cacheability' => $cacheability];
  }

}
