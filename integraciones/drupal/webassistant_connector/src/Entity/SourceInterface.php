<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * A synchronization source: what to index and how (design section 8.1).
 */
interface SourceInterface extends ConfigEntityInterface {

  /**
   * Field role: document title (fallback: the entity label).
   */
  public const ROLE_TITLE = 'title';

  /**
   * Field role: main content of the document text.
   */
  public const ROLE_MAIN_CONTENT = 'main_content';

  /**
   * Field role: secondary content appended to the text.
   */
  public const ROLE_CONTEXT = 'context';

  /**
   * Field role: metadata value (key = the field "key" or the field name).
   */
  public const ROLE_METADATA = 'metadata';

  /**
   * Field role: filterable metadata value.
   */
  public const ROLE_FILTER = 'filter';

  /**
   * Field role: URL override.
   */
  public const ROLE_URL = 'url';

  /**
   * Field role: image (metadata.image_url, absolute).
   */
  public const ROLE_IMAGE = 'image';

  /**
   * Field role: date (updated_at).
   */
  public const ROLE_DATE = 'date';

  /**
   * Field role: ACL principals (used when the ACL mode is "field").
   *
   * Values: public, authenticated, role:<id>, group:<id> strings, or user_role
   * references (role:<id>).
   */
  public const ROLE_ACL = 'acl';

  /**
   * Field role: exclusion flag (TRUE: the translation is not indexable).
   */
  public const ROLE_EXCLUSION = 'exclusion';

  /**
   * All field roles.
   */
  public const ROLES = [
    self::ROLE_TITLE,
    self::ROLE_MAIN_CONTENT,
    self::ROLE_CONTEXT,
    self::ROLE_METADATA,
    self::ROLE_FILTER,
    self::ROLE_URL,
    self::ROLE_IMAGE,
    self::ROLE_DATE,
    self::ROLE_ACL,
    self::ROLE_EXCLUSION,
  ];

  /**
   * ACL mode: computed from Drupal access (public, authenticated or roles).
   */
  public const ACL_ACCESS = 'access';

  /**
   * ACL mode: always ['public'].
   */
  public const ACL_PUBLIC = 'public';

  /**
   * ACL mode: always ['authenticated'].
   */
  public const ACL_AUTHENTICATED = 'authenticated';

  /**
   * ACL mode: principals taken from the field with the "acl" role.
   */
  public const ACL_FIELD = 'field';

  /**
   * All ACL modes.
   */
  public const ACL_MODES = [self::ACL_ACCESS, self::ACL_PUBLIC, self::ACL_AUTHENTICATED, self::ACL_FIELD];

  /**
   * Returns the entity type ID to index (e.g. node).
   *
   * Named "target" because getEntityTypeId() returns webassistant_source.
   */
  public function getTargetEntityTypeId(): string;

  /**
   * Returns the bundles to index (empty: all bundles).
   *
   * @return list<string>
   *   Bundle IDs.
   */
  public function getBundles(): array;

  /**
   * Returns the language codes to index (empty: all languages).
   *
   * @return list<string>
   *   Language codes.
   */
  public function getLangcodes(): array;

  /**
   * Returns the remote knowledge base key.
   */
  public function getKnowledgeBase(): string;

  /**
   * Returns the document type (e.g. guide, page, product).
   */
  public function getDocumentType(): string;

  /**
   * Returns the normalizer plugin ID (default content_entity).
   */
  public function getNormalizerId(): string;

  /**
   * Returns the ACL mode (one of the ACL_* constants).
   */
  public function getAclMode(): string;

  /**
   * Whether documents are limited to the configured site_id.
   */
  public function isSiteScoped(): bool;

  /**
   * Returns the mapping version (part of the document version).
   */
  public function getMappingVersion(): int;

  /**
   * Returns the field roles in their configured order.
   *
   * @return list<array{field: string, role: string, key?: string, depth?: int, target_bundles?: list<string>}>
   *   The field role definitions.
   */
  public function getFields(): array;

  /**
   * Returns the field role definitions with a given role.
   *
   * @param string $role
   *   One of the ROLE_* constants.
   *
   * @return list<array{field: string, role: string, key?: string, depth?: int, target_bundles?: list<string>}>
   *   The matching definitions, in their configured order.
   */
  public function getFieldsByRole(string $role): array;

  /**
   * Whether the source covers an entity (entity type and bundle).
   *
   * The source status and the language are not checked: see status() and
   * appliesToLangcode().
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE if the entity type matches and the bundle is listed (or no
   *   bundles are listed).
   */
  public function appliesTo(EntityInterface $entity): bool;

  /**
   * Whether the source covers a language (no langcodes means all).
   *
   * @param string $langcode
   *   The language code.
   *
   * @return bool
   *   TRUE if covered.
   */
  public function appliesToLangcode(string $langcode): bool;

  /**
   * Returns a SHA-256 hash of the configuration that shapes the documents.
   *
   * Covers entity type, bundles, langcodes, knowledge base, document type,
   * normalizer, ACL mode, site scope, mapping version, fields and third-party
   * settings. metadata.drupal.mapping holds its first 12 characters.
   */
  public function getMappingHash(): string;

  /**
   * Returns the third-party settings of a module (shortcut).
   *
   * @param string $module
   *   The module name, e.g. webassistant_connector_documents.
   *
   * @return array<string, mixed>
   *   The settings (empty if none).
   */
  public function getAttachmentSettings(string $module): array;

}
