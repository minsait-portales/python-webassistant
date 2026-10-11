<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\webassistant_connector\DependencyInjection\AutowirePluginTrait;

/**
 * Base class for host action plugins (design section 7).
 *
 * Subclasses declare #[HostAction(...)], implement execute() and may add
 * services to the constructor after the three plugin arguments: create() is
 * autowired. Parameter helpers throw HostActionException with user-safe
 * messages; NULL values are treated as absent.
 */
abstract class HostActionPluginBase extends PluginBase implements HostActionInterface, ContainerFactoryPluginInterface {

  use AutowirePluginTrait;

  /**
   * {@inheritdoc}
   *
   * Allowed when the account has the definition permission (or when the
   * definition has none). Subclasses AND their own conditions with this.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $permission = $this->getPermission();
    if ($permission === NULL) {
      return AccessResult::allowed();
    }
    return AccessResult::allowedIfHasPermission($account, $permission);
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $definition = $this->getPluginDefinition();
    return (string) (is_array($definition) ? ($definition['label'] ?? $this->getPluginId()) : $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   */
  public function hasEffects(): bool {
    $definition = $this->getPluginDefinition();
    return is_array($definition) && !empty($definition['effects']);
  }

  /**
   * Returns the permission declared in the plugin definition, if any.
   */
  public function getPermission(): ?string {
    $definition = $this->getPluginDefinition();
    $permission = is_array($definition) ? ($definition['permission'] ?? NULL) : NULL;
    return is_string($permission) && $permission !== '' ? $permission : NULL;
  }

  /**
   * Builds a business or validation error with a user-facing message.
   *
   * Usage: throw $this->failure($this->t('...'), ['candidates' => ...]).
   * HostActionException messages are shown to the user by design (the
   * dispatcher returns them as a failed result), so they are translated.
   *
   * @param string|\Stringable $message
   *   A user-safe, translated message.
   * @param array<string, mixed> $data
   *   Extra data for the failed result.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionException
   *   The exception to throw.
   */
  protected function failure(string|\Stringable $message, array $data = []): HostActionException {
    return new HostActionException($message, $data);
  }

  /**
   * Rejects parameters that the action does not declare.
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param list<string> $allowed
   *   The accepted parameter names.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function assertAllowedParams(array $params, array $allowed): void {
    $unknown = array_diff(array_map('strval', array_keys($params)), $allowed);
    if ($unknown !== []) {
      throw $this->failure($this->t('Unexpected parameter @name.', ['@name' => reset($unknown)]));
    }
  }

  /**
   * Reads a string parameter (trimmed).
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   * @param bool $required
   *   Whether the parameter is required (empty strings count as missing).
   * @param int $maxLength
   *   Maximum length in characters.
   *
   * @return string|null
   *   The value, or NULL if optional and missing.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function stringParam(array $params, string $name, bool $required = TRUE, int $maxLength = 255): ?string {
    $value = $params[$name] ?? NULL;
    if (is_int($value)) {
      $value = (string) $value;
    }
    if ($value !== NULL && !is_string($value)) {
      throw $this->failure($this->t('The parameter @name must be a text.', ['@name' => $name]));
    }
    $value = $value === NULL ? '' : trim($value);
    if ($value === '') {
      if ($required) {
        throw $this->failure($this->t('The parameter @name is required.', ['@name' => $name]));
      }
      return NULL;
    }
    if (mb_strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    return $value;
  }

  /**
   * Reads an integer parameter (int or digit string).
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   * @param bool $required
   *   Whether the parameter is required.
   * @param int|null $min
   *   Minimum value (inclusive).
   * @param int|null $max
   *   Maximum value (inclusive).
   *
   * @return int|null
   *   The value, or NULL if optional and missing.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function intParam(array $params, string $name, bool $required = TRUE, ?int $min = NULL, ?int $max = NULL): ?int {
    $value = $params[$name] ?? NULL;
    if ($value === NULL || $value === '') {
      if ($required) {
        throw $this->failure($this->t('The parameter @name is required.', ['@name' => $name]));
      }
      return NULL;
    }
    if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) {
      $value = (int) $value;
    }
    if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
      $value = (int) $value;
    }
    if (!is_int($value) || ($min !== NULL && $value < $min) || ($max !== NULL && $value > $max)) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    return $value;
  }

  /**
   * Reads an identifier parameter (string or integer) as a string.
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   * @param bool $required
   *   Whether the parameter is required.
   * @param int $maxLength
   *   Maximum length in characters.
   *
   * @return string|null
   *   The value, or NULL if optional and missing.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function idParam(array $params, string $name, bool $required = TRUE, int $maxLength = 128): ?string {
    $value = $params[$name] ?? NULL;
    if (is_int($value)) {
      $value = (string) $value;
    }
    if ($value !== NULL && (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value))) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    $value = $value === NULL ? '' : trim($value);
    if ($value === '') {
      if ($required) {
        throw $this->failure($this->t('The parameter @name is required.', ['@name' => $name]));
      }
      return NULL;
    }
    if (mb_strlen($value) > $maxLength) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    return $value;
  }

  /**
   * Reads a boolean parameter.
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   * @param bool $default
   *   The value when missing.
   *
   * @return bool
   *   The value.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function boolParam(array $params, string $name, bool $default = FALSE): bool {
    $value = $params[$name] ?? NULL;
    if ($value === NULL) {
      return $default;
    }
    if (!is_bool($value)) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    return $value;
  }

  /**
   * Reads a parameter restricted to a set of values.
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   * @param list<string> $allowed
   *   The accepted values.
   * @param bool $required
   *   Whether the parameter is required.
   *
   * @return string|null
   *   The value, or NULL if optional and missing.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function enumParam(array $params, string $name, array $allowed, bool $required = TRUE): ?string {
    $value = $this->stringParam($params, $name, $required, 128);
    if ($value !== NULL && !in_array($value, $allowed, TRUE)) {
      throw $this->failure($this->t('The parameter @name is not valid.', ['@name' => $name]));
    }
    return $value;
  }

  /**
   * Reads a list parameter (JSON array).
   *
   * @param array<string, mixed> $params
   *   The parameters.
   * @param string $name
   *   The parameter name.
   * @param bool $required
   *   Whether the parameter is required (an empty list counts as missing).
   * @param int $maxItems
   *   Maximum number of items.
   *
   * @return list<mixed>|null
   *   The list, or NULL if optional and missing.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   */
  protected function listParam(array $params, string $name, bool $required = TRUE, int $maxItems = 100): ?array {
    $value = $params[$name] ?? NULL;
    if ($value !== NULL && (!is_array($value) || !array_is_list($value))) {
      throw $this->failure($this->t('The parameter @name must be a list.', ['@name' => $name]));
    }
    if ($value === NULL || $value === []) {
      if ($required) {
        throw $this->failure($this->t('The parameter @name is required.', ['@name' => $name]));
      }
      return NULL;
    }
    if (count($value) > $maxItems) {
      throw $this->failure($this->t('The parameter @name may contain at most @max items.', [
        '@name' => $name,
        '@max' => $maxItems,
      ]));
    }
    return $value;
  }

}
