<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\media\MediaAccessControlHandler;
use Drupal\node\NodeAccessControlHandler;
use Drupal\taxonomy\TermAccessControlHandler;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Event\AclAlterEvent;
use Drupal\webassistant_connector\Identity\IdentityMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Computes the ACL of a document (design section 8.3, step 4; DR-04.7).
 *
 * - acl_mode public: ['public'];
 * - acl_mode authenticated: ['authenticated'];
 * - acl_mode field: the principals of the fields with the "acl" role
 *   (public, authenticated, role:x, group:x or user:x strings; user_role
 *   references as role:<rid>, group references as group:<id> and user
 *   references as user:<opaque platform user ID>); empty means not
 *   indexable (amendment B5);
 * - acl_mode access: ['public'] if the anonymous user can view; else
 *   ['authenticated'] if an authenticated user without extra roles can;
 *   else role:<rid> for each role that can view.
 *
 * Access is checked with synthetic accounts (amendment B4): uid 0 for the
 * anonymous user and uid SYNTHETIC_UID (never 1, never a real user) with the
 * roles [authenticated] or [authenticated, rid]. The access handler cache is
 * reset before every check and every check is wrapped in try/catch (errors
 * deny and are logged). Only roles that can hold the base view permission of
 * the entity type are checked, and results are memoized per (entity type,
 * bundle, published) for core access handlers that only look at those
 * (node, taxonomy term, media) while no module implements node_grants,
 * entity_access or {entity_type}_access (besides core implementations that
 * are neutral for viewing). Subscribers of AclAlterEvent may then alter the
 * list; principals that do not match PRINCIPAL_PATTERN are dropped, never
 * widened.
 *
 * The same synthetic accounts are used to render documents as the least
 * privileged principal (amendment B1, renderAccount()) and to check field
 * and reference access for every principal (amendments B2/B3,
 * principalAccounts()).
 *
 * Service: webassistant_connector.acl_resolver.
 */
final class AclResolver {

  /**
   * Pattern of ACL principals (canonical-document.v1).
   */
  public const PRINCIPAL_PATTERN = '/^(public|authenticated|(role|user|group):[A-Za-z0-9_.@-]{1,128})$/';

  /**
   * User ID of the synthetic authenticated accounts.
   */
  public const SYNTHETIC_UID = PHP_INT_MAX;

  /**
   * Principal: everybody.
   */
  public const PUBLIC = 'public';

  /**
   * Principal: every authenticated user.
   */
  public const AUTHENTICATED = 'authenticated';

  /**
   * Permissions that grant viewing per entity type (any of them).
   *
   * Roles without any of them (and without the admin flag) are not checked,
   * unless the authenticated role has one (every role inherits it).
   */
  private const BASE_VIEW_PERMISSIONS = [
    'node' => ['access content', 'bypass node access'],
    'media' => ['view media', 'administer media'],
    'taxonomy_term' => ['access content', 'administer taxonomy'],
    'comment' => ['access comments', 'administer comments'],
  ];

  /**
   * Core hook implementations that are neutral for viewing published content.
   *
   * Workspaces only acts inside an active workspace, and documents are always
   * built outside of any workspace (DocumentBuilder, amendment A13).
   */
  private const NEUTRAL_IMPLEMENTATIONS = [
    'node_access' => ['node'],
    'entity_access' => ['media', 'content_moderation', 'workspaces'],
  ];

  /**
   * Access handlers whose view result only depends on simple data.
   *
   * For published entities they only look at the bundle, the publication
   * status and permissions (no owner nor field checks). Other entity types
   * are never memoized: their access handlers may look at any field.
   */
  private const MEMOIZABLE_HANDLERS = [
    NodeAccessControlHandler::class,
    TermAccessControlHandler::class,
    MediaAccessControlHandler::class,
  ];

  /**
   * Maximum number of memoized ACLs (the memo is cleared when reached).
   */
  private const MEMO_LIMIT = 1000;

  /**
   * Memoized ACLs keyed by "entity_type:bundle:published:permissions".
   *
   * @var array<string, list<string>>
   */
  private array $memo = [];

  /**
   * Whether ACLs of an entity type may be memoized, keyed by entity type.
   *
   * @var array<string, bool>
   */
  private array $memoizable = [];

  /**
   * Constructs an AclResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\webassistant_connector\Identity\IdentityMapper $identityMapper
   *   The identity mapper (user references become user:<opaque id>).
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger of the connector.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly IdentityMapper $identityMapper,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves the ACL of an entity translation.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source (ACL mode).
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, in the translation being indexed.
   *
   * @return list<string>
   *   Principals (public, authenticated, role:<rid>, ...), sorted. Empty
   *   means nobody can view it (DocumentBuildResult::SKIP_NO_ACCESS).
   */
  public function resolve(SourceInterface $source, EntityInterface $entity): array {
    $acl = match ($source->getAclMode()) {
      SourceInterface::ACL_PUBLIC => [self::PUBLIC],
      SourceInterface::ACL_AUTHENTICATED => [self::AUTHENTICATED],
      SourceInterface::ACL_FIELD => $this->fromFields($source, $entity),
      default => $this->fromAccess($entity),
    };
    $event = new AclAlterEvent($source, $entity, $acl);
    $this->eventDispatcher->dispatch($event, AclAlterEvent::NAME);
    return $this->sanitize($event->getAcl(), $entity);
  }

  /**
   * Clears the memoized ACLs (call it when roles or permissions change).
   */
  public function resetCache(): void {
    $this->memo = [];
    $this->memoizable = [];
  }

  /**
   * Returns the principal of a role, or NULL if its ID cannot be represented.
   *
   * @param string $roleId
   *   The role ID.
   *
   * @return string|null
   *   role:<rid>, or NULL when the ID does not match [A-Za-z0-9_.@-]{1,128}
   *   (such roles are skipped, never rewritten) or is a locked role.
   */
  public static function rolePrincipal(string $roleId): ?string {
    if (in_array($roleId, [AccountInterface::ANONYMOUS_ROLE, AccountInterface::AUTHENTICATED_ROLE], TRUE)) {
      return NULL;
    }
    return preg_match(IdentityMapper::ID_PATTERN, $roleId) ? 'role:' . $roleId : NULL;
  }

  /**
   * Returns the synthetic anonymous account (uid 0).
   */
  public function anonymousAccount(): AccountInterface {
    return new AnonymousUserSession();
  }

  /**
   * Returns a synthetic authenticated account (uid SYNTHETIC_UID).
   *
   * @param list<string> $extraRoles
   *   Roles added to "authenticated".
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account; it does not exist in the database.
   */
  public function authenticatedAccount(array $extraRoles = []): AccountInterface {
    return new UserSession([
      'uid' => self::SYNTHETIC_UID,
      'name' => 'webassistant_connector_principal',
      'roles' => array_values(array_unique(array_merge([AccountInterface::AUTHENTICATED_ROLE], $extraRoles))),
      'preferred_langcode' => '',
      'timezone' => 'UTC',
    ]);
  }

  /**
   * Returns the least privileged account of an ACL (amendment B1).
   *
   * @param list<string> $acl
   *   The principals.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   Anonymous for public; authenticated without roles for authenticated;
   *   authenticated plus the role for the first role:x; authenticated
   *   without roles for group/user principals.
   */
  public function renderAccount(array $acl): AccountInterface {
    if ($acl === [] || in_array(self::PUBLIC, $acl, TRUE)) {
      return $this->anonymousAccount();
    }
    if (in_array(self::AUTHENTICATED, $acl, TRUE)) {
      return $this->authenticatedAccount();
    }
    foreach ($acl as $principal) {
      if (str_starts_with($principal, 'role:')) {
        return $this->authenticatedAccount([substr($principal, 5)]);
      }
    }
    return $this->authenticatedAccount();
  }

  /**
   * Returns one synthetic account per ACL principal (amendments B2/B3).
   *
   * @param list<string> $acl
   *   The principals.
   *
   * @return list<\Drupal\Core\Session\AccountInterface>
   *   The accounts (group and user principals are approximated by an
   *   authenticated account without extra roles). Never empty: an empty
   *   ACL yields the anonymous account.
   */
  public function principalAccounts(array $acl): array {
    $accounts = [];
    foreach ($acl as $principal) {
      if ($principal === self::PUBLIC) {
        $accounts['public'] = $this->anonymousAccount();
      }
      elseif (str_starts_with($principal, 'role:')) {
        $accounts[$principal] = $this->authenticatedAccount([substr($principal, 5)]);
      }
      else {
        $accounts['authenticated'] = $this->authenticatedAccount();
      }
    }
    return $accounts === [] ? [$this->anonymousAccount()] : array_values($accounts);
  }

  /**
   * Checks an operation on an entity for one account, failing closed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The (synthetic) account.
   * @param string $operation
   *   The operation, e.g. view or download.
   *
   * @return bool
   *   TRUE if allowed; FALSE if denied or if the check failed.
   */
  public function canAccess(EntityInterface $entity, AccountInterface $account, string $operation = 'view'): bool {
    try {
      // Access results are cached by account ID, which every synthetic
      // authenticated account shares.
      $this->entityTypeManager->getAccessControlHandler($entity->getEntityTypeId())->resetCache();
      return $entity->access($operation, $account);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Access check "@operation" on @type @id failed and was denied: @class: @message', [
        '@operation' => $operation,
        '@type' => $entity->getEntityTypeId(),
        '@id' => (string) $entity->id(),
        '@class' => get_class($exception),
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Whether every account may perform an operation on an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The accounts (principalAccounts()).
   * @param string $operation
   *   The operation.
   *
   * @return bool
   *   TRUE if all of them are allowed.
   */
  public function canAccessAll(EntityInterface $entity, array $accounts, string $operation = 'view'): bool {
    foreach ($accounts as $account) {
      if (!$this->canAccess($entity, $account, $operation)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether every account may view a field (amendment B2), failing closed.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The accounts (principalAccounts()).
   *
   * @return bool
   *   TRUE if all of them may view it.
   */
  public function fieldViewableByAll(FieldItemListInterface $items, array $accounts): bool {
    foreach ($accounts as $account) {
      try {
        if (!$items->access('view', $account)) {
          return FALSE;
        }
      }
      catch (\Throwable $exception) {
        $this->logger->warning('Field access check on @field failed and was denied: @class: @message', [
          '@field' => $items->getName(),
          '@class' => get_class($exception),
          '@message' => $exception->getMessage(),
        ]);
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Computes the ACL from Drupal access (acl_mode access).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity translation.
   *
   * @return list<string>
   *   The principals.
   */
  private function fromAccess(EntityInterface $entity): array {
    $key = NULL;
    if ($this->isMemoizable($entity->getEntityTypeId())) {
      $published = !$entity instanceof EntityPublishedInterface || $entity->isPublished();
      // Permissions are part of the key, so role changes made earlier in
      // the same process (e.g. a long Drush run) are never masked.
      $key = $entity->getEntityTypeId() . ':' . $entity->bundle() . ':' . (int) $published . ':' . $this->permissionsFingerprint();
      if (isset($this->memo[$key])) {
        return $this->memo[$key];
      }
    }

    if ($this->canAccess($entity, $this->anonymousAccount())) {
      $acl = [self::PUBLIC];
    }
    elseif ($this->canAccess($entity, $this->authenticatedAccount())) {
      $acl = [self::AUTHENTICATED];
    }
    else {
      $acl = [];
      foreach ($this->candidateRoles($entity->getEntityTypeId()) as $roleId) {
        $principal = self::rolePrincipal($roleId);
        if ($principal === NULL) {
          $this->logger->warning('The role @role is not added to document ACLs: its ID does not match [A-Za-z0-9_.@-].', ['@role' => $roleId]);
          continue;
        }
        if ($this->canAccess($entity, $this->authenticatedAccount([$roleId]))) {
          $acl[] = $principal;
        }
      }
    }

    if ($key !== NULL) {
      if (count($this->memo) >= self::MEMO_LIMIT) {
        $this->memo = [];
      }
      $this->memo[$key] = $acl;
    }
    return $acl;
  }

  /**
   * Reads the principals of the "acl" fields (acl_mode field).
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity translation.
   *
   * @return list<string>
   *   The principals (unsanitized).
   */
  private function fromFields(SourceInterface $source, EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $acl = [];
    foreach ($source->getFieldsByRole(SourceInterface::ROLE_ACL) as $mapping) {
      if (!$entity->hasField($mapping['field'])) {
        continue;
      }
      $items = $entity->get($mapping['field']);
      $targetType = (string) $items->getFieldDefinition()->getSetting('target_type');
      foreach ($items as $item) {
        if ($item instanceof EntityReferenceItem) {
          $targetId = (string) $item->get('target_id')->getValue();
          if ($targetId === '') {
            continue;
          }
          if ($targetType === 'user_role') {
            $principal = self::rolePrincipal($targetId);
            if ($principal !== NULL) {
              $acl[] = $principal;
            }
          }
          elseif ($targetType === 'user' && ctype_digit($targetId) && (int) $targetId > 0) {
            // Same opaque ID as the widget token of that user (section 5).
            $acl[] = 'user:' . $this->identityMapper->userId(new UserSession(['uid' => (int) $targetId]));
          }
          elseif ($targetType === 'group') {
            $acl[] = 'group:' . $targetId;
          }
          continue;
        }
        $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
        $value = $property !== NULL ? $item->get($property)->getValue() : NULL;
        if (is_scalar($value) && trim((string) $value) !== '') {
          $acl[] = trim((string) $value);
        }
      }
    }
    return $acl;
  }

  /**
   * Drops invalid principals and sorts the list.
   *
   * @param list<string> $acl
   *   The principals.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity (for the log).
   *
   * @return list<string>
   *   The valid principals, unique and sorted.
   */
  private function sanitize(array $acl, EntityInterface $entity): array {
    $valid = [];
    foreach ($acl as $principal) {
      $principal = (string) $principal;
      if (!preg_match(self::PRINCIPAL_PATTERN, $principal)) {
        $this->logger->warning('The principal "@principal" of @type @id was dropped: it does not match the ACL pattern.', [
          '@principal' => mb_substr($principal, 0, 140),
          '@type' => $entity->getEntityTypeId(),
          '@id' => (string) $entity->id(),
        ]);
        continue;
      }
      $valid[$principal] = TRUE;
    }
    $valid = array_map('strval', array_keys($valid));
    sort($valid);
    return $valid;
  }

  /**
   * Returns a hash of the permissions and admin flag of every role.
   *
   * @return string
   *   A short hash; it changes whenever a role or its permissions change.
   */
  private function permissionsFingerprint(): string {
    $data = [];
    /** @var array<string, \Drupal\user\RoleInterface> $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $roleId => $role) {
      $permissions = $role->getPermissions();
      sort($permissions);
      $data[(string) $roleId] = [$role->isAdmin(), $permissions];
    }
    ksort($data);
    return hash('xxh128', (string) json_encode($data));
  }

  /**
   * Returns the IDs of the roles worth checking for an entity type.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return list<string>
   *   Role IDs (without anonymous and authenticated), sorted.
   */
  private function candidateRoles(string $entityTypeId): array {
    /** @var array<string, \Drupal\user\RoleInterface> $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $permissions = self::BASE_VIEW_PERMISSIONS[$entityTypeId] ?? [];
    $filter = $permissions !== [];
    if ($filter && isset($roles[AccountInterface::AUTHENTICATED_ROLE])) {
      foreach ($permissions as $permission) {
        if ($roles[AccountInterface::AUTHENTICATED_ROLE]->hasPermission($permission)) {
          $filter = FALSE;
          break;
        }
      }
    }
    $candidates = [];
    foreach ($roles as $roleId => $role) {
      $roleId = (string) $roleId;
      if (in_array($roleId, [AccountInterface::ANONYMOUS_ROLE, AccountInterface::AUTHENTICATED_ROLE], TRUE)) {
        continue;
      }
      if ($filter && !$role->isAdmin()) {
        $granted = FALSE;
        foreach ($permissions as $permission) {
          if ($role->hasPermission($permission)) {
            $granted = TRUE;
            break;
          }
        }
        if (!$granted) {
          continue;
        }
      }
      $candidates[] = $roleId;
    }
    sort($candidates);
    return $candidates;
  }

  /**
   * Whether the ACL of an entity type only depends on bundle and status.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return bool
   *   TRUE for the core access handlers of MEMOIZABLE_HANDLERS when no
   *   module (besides neutral core implementations) implements entity_access,
   *   {entity_type}_access or, for nodes, node_grants.
   */
  private function isMemoizable(string $entityTypeId): bool {
    if (!isset($this->memoizable[$entityTypeId])) {
      $handler = $this->entityTypeManager->getDefinition($entityTypeId, FALSE)?->getAccessControlClass();
      $memoizable = is_string($handler) && in_array(ltrim($handler, '\\'), self::MEMOIZABLE_HANDLERS, TRUE);
      $hooks = ['entity_access', $entityTypeId . '_access'];
      if ($entityTypeId === 'node') {
        $hooks[] = 'node_grants';
      }
      foreach ($memoizable ? $hooks : [] as $hook) {
        $modules = [];
        $this->moduleHandler->invokeAllWith($hook, static function (callable $implementation, string $module) use (&$modules): void {
          $modules[] = $module;
        });
        $modules = array_diff($modules, self::NEUTRAL_IMPLEMENTATIONS[$hook] ?? []);
        if ($modules !== []) {
          $memoizable = FALSE;
          break;
        }
      }
      $this->memoizable[$entityTypeId] = $memoizable;
    }
    return $this->memoizable[$entityTypeId];
  }

}
