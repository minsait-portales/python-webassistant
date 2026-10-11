<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\Locale;

use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Locale\LocaleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the mapping of Drupal language codes to platform locales.
 *
 * @group webassistant_connector
 */
#[CoversClass(LocaleMapper::class)]
#[Group('webassistant_connector')]
final class LocaleMapperTest extends UnitTestCase {

  /**
   * Builds a mapper.
   *
   * @param string $defaultLocale
   *   The sync.default_locale value.
   * @param string $current
   *   The current interface language code.
   *
   * @return \Drupal\webassistant_connector\Locale\LocaleMapper
   *   The mapper.
   */
  private function mapper(string $defaultLocale = 'en', string $current = 'es'): LocaleMapper {
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_INTERFACE)
      ->willReturn(new Language(['id' => $current]));
    return new LocaleMapper(
      $this->getConfigFactoryStub(['webassistant_connector.settings' => ['sync' => ['default_locale' => $defaultLocale]]]),
      $languageManager,
    );
  }

  /**
   * Language codes and their platform locales.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Drupal code and expected locale.
   */
  public static function providerMap(): array {
    return [
      'two letters' => ['es', 'es'],
      'upper case' => ['EN', 'en'],
      'region' => ['pt-br', 'pt-BR'],
      'underscore region' => ['pt_BR', 'pt-BR'],
      'script' => ['zh-hans', 'zh'],
      'numeric region' => ['es-419', 'es'],
      'script and region' => ['sr-latn-rs', 'sr'],
      'undefined' => ['und', 'en'],
      'not applicable' => ['zxx', 'en'],
      'three letters' => ['ast', 'en'],
      'empty' => ['', 'en'],
    ];
  }

  /**
   * Maps Drupal language codes to locales matching ^[a-z]{2}(-[A-Z]{2})?$.
   */
  #[DataProvider('providerMap')]
  public function testMap(string $langcode, string $expected): void {
    $locale = $this->mapper()->map($langcode);
    $this->assertSame($expected, $locale);
    $this->assertMatchesRegularExpression(LocaleMapper::LOCALE_PATTERN, $locale);
  }

  /**
   * An invalid default locale falls back to "es".
   */
  public function testInvalidDefaultLocale(): void {
    $this->assertSame('es', $this->mapper('spanish')->map('und'));
  }

  /**
   * The current interface language is mapped.
   */
  public function testCurrentLocale(): void {
    $this->assertSame('pt-BR', $this->mapper('en', 'pt-br')->currentLocale());
  }

}
