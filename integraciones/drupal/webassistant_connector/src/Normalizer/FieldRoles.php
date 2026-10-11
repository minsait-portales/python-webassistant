<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Field types accepted by each field role of the content_entity normalizer.
 *
 * Shared by the source form (only compatible fields and roles are offered,
 * DR-04.2) and the normalizer, so both agree on what a role can read.
 */
final class FieldRoles {

  /**
   * Formatted text field types (their "processed" value is used).
   */
  public const FORMATTED_TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];

  /**
   * Plain string field types (escaped).
   */
  public const STRING_TYPES = ['string', 'string_long', 'email', 'telephone', 'uri'];

  /**
   * List field types (allowed values).
   */
  public const LIST_TYPES = ['list_string', 'list_integer', 'list_float'];

  /**
   * Numeric field types.
   */
  public const NUMBER_TYPES = ['integer', 'decimal', 'float'];

  /**
   * Date field types.
   */
  public const DATE_TYPES = ['datetime', 'daterange', 'timestamp', 'created', 'changed'];

  /**
   * Entity reference field types (image and file are handled apart).
   */
  public const REFERENCE_TYPES = ['entity_reference', 'entity_reference_revisions'];

  /**
   * Field types whose values are usually personal data (DR-04.2 warning).
   */
  public const SENSITIVE_TYPES = ['email', 'telephone', 'password', 'address'];

  /**
   * Field name fragments that usually denote personal data.
   */
  private const SENSITIVE_NAME_PATTERN = '/(^|_)(e?mail|phone|mobile|telephone|password|pass|ip|ip_address|address|dni|nif|nie|ssn|iban|birth(date|day)?)(_|$)/';

  /**
   * Returns the roles a field can take.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return list<string>
   *   SourceInterface::ROLE_* values, in SourceInterface::ROLES order.
   */
  public static function rolesFor(FieldDefinitionInterface $definition): array {
    $type = $definition->getType();
    $targetType = (string) $definition->getSetting('target_type');
    $text = array_merge(self::FORMATTED_TEXT_TYPES, ['string', 'string_long']);
    $scalar = array_merge(self::STRING_TYPES, self::LIST_TYPES, self::NUMBER_TYPES, self::DATE_TYPES, ['boolean', 'link', 'language']);
    $roles = [];
    if (in_array($type, $text, TRUE)) {
      $roles[] = SourceInterface::ROLE_TITLE;
    }
    if (in_array($type, array_merge($text, $scalar, self::REFERENCE_TYPES), TRUE)) {
      $roles[] = SourceInterface::ROLE_MAIN_CONTENT;
      $roles[] = SourceInterface::ROLE_CONTEXT;
      $roles[] = SourceInterface::ROLE_METADATA;
      $roles[] = SourceInterface::ROLE_FILTER;
    }
    if (in_array($type, ['link', 'string', 'uri'], TRUE)) {
      $roles[] = SourceInterface::ROLE_URL;
    }
    if ($type === 'image' || (in_array($type, self::REFERENCE_TYPES, TRUE) && $targetType === 'media')) {
      $roles[] = SourceInterface::ROLE_IMAGE;
    }
    if (in_array($type, self::DATE_TYPES, TRUE)) {
      $roles[] = SourceInterface::ROLE_DATE;
    }
    if (in_array($type, ['string', 'list_string'], TRUE) || ($type === 'entity_reference' && in_array($targetType, ['user_role', 'group', 'user'], TRUE))) {
      $roles[] = SourceInterface::ROLE_ACL;
    }
    if (in_array($type, ['boolean', 'integer', 'list_integer', 'list_string'], TRUE)) {
      $roles[] = SourceInterface::ROLE_EXCLUSION;
    }
    return array_values(array_intersect(SourceInterface::ROLES, $roles));
  }

  /**
   * Whether a field may be mapped with a role.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param string $role
   *   The role.
   *
   * @return bool
   *   TRUE if compatible.
   */
  public static function isCompatible(FieldDefinitionInterface $definition, string $role): bool {
    return in_array($role, self::rolesFor($definition), TRUE);
  }

  /**
   * Whether a field references other entities whose depth can be set.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return bool
   *   TRUE for entity_reference and entity_reference_revisions.
   */
  public static function isReference(FieldDefinitionInterface $definition): bool {
    return in_array($definition->getType(), self::REFERENCE_TYPES, TRUE);
  }

  /**
   * Whether a field usually holds personal or sensitive data (DR-04.2).
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return bool
   *   TRUE for e-mail, telephone, password and address fields, references
   *   to users and fields whose name suggests personal data.
   */
  public static function isSensitive(FieldDefinitionInterface $definition): bool {
    if (in_array($definition->getType(), self::SENSITIVE_TYPES, TRUE)) {
      return TRUE;
    }
    if (in_array($definition->getType(), self::REFERENCE_TYPES, TRUE) && $definition->getSetting('target_type') === 'user') {
      return TRUE;
    }
    return (bool) preg_match(self::SENSITIVE_NAME_PATTERN, $definition->getName());
  }

}
