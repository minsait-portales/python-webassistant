<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

/**
 * Values computed by DocumentBuilder for one translation (section 8.2/8.3).
 *
 * Everything is immutable except the registry of visited entities, used by
 * normalizers to detect reference cycles (visit() on enter, leave() on exit),
 * and the warnings collected for the preview (addWarning()).
 *
 * Normalizers and contributors run while the current user is switched to the
 * least privileged principal of the ACL (amendment B1). Mapped fields and
 * referenced entities must be checked for every account of
 * $principalAccounts and dropped (with a warning) when any of them cannot
 * view them (amendments B2/B3): see AclResolver::fieldViewableByAll() and
 * AclResolver::canAccessAll().
 */
final class NormalizationContext {

  /**
   * Warnings for administrators (e.g. a field dropped for lack of access).
   *
   * @var list<string>
   */
  private array $warnings = [];

  /**
   * Entities currently being visited, keyed by "entity_type:id".
   *
   * @var array<string, true>
   */
  private array $visited = [];

  /**
   * Constructs a NormalizationContext.
   *
   * @param list<string> $acl
   *   ACL of the entity (never empty: empty means "no_access" skip).
   * @param string $locale
   *   Platform locale (LocaleMapper::map()).
   * @param string $langcode
   *   Drupal language code of the translation.
   * @param string $baseSourceId
   *   Source ID of the main document, e.g. drupal:node:42:es. Contributors
   *   derive theirs from it (e.g. {base}:file:{uuid}).
   * @param string $version
   *   Version prefix "m{mapping_version}" (amendments A8/E2). DocumentBuilder
   *   sets the final version of the main document after normalization:
   *   m{mapping_version}.{first 16 hex characters of content_hash}.
   * @param string|null $canonicalUrl
   *   Absolute canonical URL of the entity, if it has one.
   * @param bool $preview
   *   TRUE when building for the preview form or Drush preview (no side
   *   effects such as issuing signed URLs that must be stored).
   * @param string|null $siteId
   *   Public site ID when the source is site scoped, else NULL.
   * @param array<string, mixed> $drupalMetadata
   *   The metadata.drupal map: entity_type, bundle, id, uuid, langcode,
   *   source and mapping (first 12 characters of the mapping hash).
   * @param list<\Drupal\Core\Session\AccountInterface> $principalAccounts
   *   One synthetic account per ACL principal (AclResolver::principalAccounts()).
   *   Empty only for contexts built outside DocumentBuilder.
   */
  public function __construct(
    public readonly array $acl,
    public readonly string $locale,
    public readonly string $langcode,
    public readonly string $baseSourceId,
    public readonly string $version,
    public readonly ?string $canonicalUrl = NULL,
    public readonly bool $preview = FALSE,
    public readonly ?string $siteId = NULL,
    public readonly array $drupalMetadata = [],
    public readonly array $principalAccounts = [],
  ) {}

  /**
   * Records a warning shown in the preview (never sent to the platform).
   *
   * @param string $warning
   *   The warning, in English, without secrets nor content.
   */
  public function addWarning(string $warning): void {
    if (!in_array($warning, $this->warnings, TRUE)) {
      $this->warnings[] = $warning;
    }
  }

  /**
   * Returns the warnings recorded so far.
   *
   * @return list<string>
   *   The warnings.
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  /**
   * Marks an entity as being visited.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param int|string $entityId
   *   The entity ID.
   *
   * @return bool
   *   TRUE if the entity was not being visited (safe to descend), FALSE if it
   *   is already on the stack (cycle).
   */
  public function visit(string $entityTypeId, int|string $entityId): bool {
    $key = $entityTypeId . ':' . $entityId;
    if (isset($this->visited[$key])) {
      return FALSE;
    }
    $this->visited[$key] = TRUE;
    return TRUE;
  }

  /**
   * Removes an entity from the visited stack.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param int|string $entityId
   *   The entity ID.
   */
  public function leave(string $entityTypeId, int|string $entityId): void {
    unset($this->visited[$entityTypeId . ':' . $entityId]);
  }

  /**
   * Whether an entity is being visited.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param int|string $entityId
   *   The entity ID.
   *
   * @return bool
   *   TRUE if visited.
   */
  public function isVisited(string $entityTypeId, int|string $entityId): bool {
    return isset($this->visited[$entityTypeId . ':' . $entityId]);
  }

  /**
   * Returns the number of entities on the visited stack (reference depth).
   */
  public function depth(): int {
    return count($this->visited);
  }

}
