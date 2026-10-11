<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Locale;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Maps Drupal language codes to platform locales (design section 8.3).
 *
 * Platform locales match ^[a-z]{2}(-[A-Z]{2})?$: es -> es, pt-br -> pt-BR,
 * zh-hans -> zh, und/zxx (and unmappable codes) -> sync.default_locale.
 *
 * Service: webassistant_connector.locale_mapper.
 */
final class LocaleMapper {

  /**
   * Pattern of the locales accepted by the platform.
   */
  public const LOCALE_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';

  /**
   * Locale used when sync.default_locale is missing or invalid.
   */
  public const FALLBACK_LOCALE = 'es';

  /**
   * Constructs a LocaleMapper.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Maps a Drupal language code to a platform locale.
   *
   * Two-letter codes are kept (es), a two-letter region is upper-cased
   * (pt-br -> pt-BR), scripts and other subtags are dropped (zh-hans -> zh,
   * es-419 -> es). Language-neutral codes (und, zxx) and codes without a
   * two-letter language (ast, fil) map to sync.default_locale.
   *
   * @param string $langcode
   *   The Drupal language code.
   *
   * @return string
   *   The platform locale.
   */
  public function map(string $langcode): string {
    $langcode = strtolower(trim(str_replace('_', '-', $langcode)));
    if (preg_match('/^([a-z]{2})(?:-([a-z0-9]+))?(?:-.*)?$/', $langcode, $matches)) {
      $language = $matches[1];
      $subtag = $matches[2] ?? '';
      return strlen($subtag) === 2 && ctype_alpha($subtag) ? $language . '-' . strtoupper($subtag) : $language;
    }
    return $this->defaultLocale();
  }

  /**
   * Returns the current interface language mapped to a platform locale.
   */
  public function currentLocale(): string {
    return $this->map($this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId());
  }

  /**
   * Returns sync.default_locale (or FALLBACK_LOCALE if invalid).
   */
  public function defaultLocale(): string {
    $locale = (string) $this->configFactory->get('webassistant_connector.settings')->get('sync.default_locale');
    return preg_match(self::LOCALE_PATTERN, $locale) ? $locale : self::FALLBACK_LOCALE;
  }

}
