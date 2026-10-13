<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Detects changes that may alter computed ACLs (amendment A12).
 *
 * The epoch is a hash of the permissions of every role, the modules that
 * implement node_grants, entity_access or {entity_type}_access for any
 * content entity type, and node.node_access_needs_rebuild (never the
 * sources themselves). When it
 * changes, every source with acl_mode "access" is reindexed in the
 * background; the payload hash (amendment A8) makes sure only documents
 * whose ACL really changed are sent again.
 *
 * Service: webassistant_connector.access_epoch.
 */
final class AccessEpoch {

  /**
   * State key of the current epoch.
   */
  public const STATE_KEY = 'webassistant_connector.access_epoch';

  /**
   * Constructs an AccessEpoch.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\webassistant_connector\Sync\SourceMatcher $sourceMatcher
   *   The source matcher.
   * @param \Drupal\webassistant_connector\Sync\Indexer $indexer
   *   The indexer.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly StateInterface $state,
    private readonly SourceMatcher $sourceMatcher,
    private readonly Indexer $indexer,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Computes the current epoch.
   *
   * @return string
   *   A SHA-256 hash.
   */
  public function compute(): string {
    $roles = [];
    if ($this->entityTypeManager->hasDefinition('user_role')) {
      foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $id => $role) {
        $permissions = $role->getPermissions();
        sort($permissions);
        $roles[(string) $id] = ['admin' => $role->isAdmin(), 'permissions' => $permissions];
      }
      ksort($roles);
    }
    // Every content entity type, not only those of the sources: creating or
    // disabling a source must not look like an access change.
    $hooks = ['node_grants', 'node_grants_alter', 'node_access_records', 'entity_access', 'entity_access_alter'];
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
      if ($definition->entityClassImplements(ContentEntityInterface::class)) {
        $hooks[] = $entityTypeId . '_access';
      }
    }
    $implementations = [];
    foreach (array_unique($hooks) as $hook) {
      $modules = [];
      $this->moduleHandler->invokeAllWith($hook, static function (callable $callback, string $module) use (&$modules): void {
        $modules[] = $module;
      });
      if ($modules !== []) {
        sort($modules);
        $implementations[$hook] = array_values(array_unique($modules));
      }
    }
    ksort($implementations);
    $data = [
      'roles' => $roles,
      'implementations' => $implementations,
      'node_access_needs_rebuild' => (bool) $this->state->get('node.node_access_needs_rebuild', FALSE),
    ];
    return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
  }

  /**
   * Recomputes the epoch and reindexes when it changed.
   *
   * The first computation only stores the epoch.
   *
   * @return bool
   *   TRUE if the epoch changed and reindexing was scheduled.
   */
  public function check(): bool {
    $current = $this->compute();
    $previous = $this->state->get(self::STATE_KEY);
    if ($previous === $current) {
      return FALSE;
    }
    $this->state->set(self::STATE_KEY, $current);
    if (!is_string($previous)) {
      return FALSE;
    }
    $scheduled = [];
    foreach ($this->sourceMatcher->getActiveSources() as $id => $source) {
      if ($source->getAclMode() === SourceInterface::ACL_ACCESS) {
        $this->indexer->scheduleReindex((string) $id, TRUE, FALSE, 'access epoch');
        $scheduled[] = (string) $id;
      }
    }
    if ($scheduled !== []) {
      $this->logger->notice('Permissions or access modules changed: reindexing the sources @sources.', ['@sources' => implode(', ', $scheduled)]);
    }
    return $scheduled !== [];
  }

}
