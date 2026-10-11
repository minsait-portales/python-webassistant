<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\HostAction;

use Drupal\Component\Transliteration\TransliterationInterface;

/**
 * Resolves a bundle requested by name against machine names and labels.
 *
 * Used by the content host actions: the assistant may send the machine name
 * ("article"), the label ("Article", "Artículo") or a loose variant
 * ("basic page", "ARTICULO").
 */
final class BundleMatcher {

  /**
   * Constructs a BundleMatcher.
   *
   * @param \Drupal\Component\Transliteration\TransliterationInterface|null $transliteration
   *   The transliteration service, used for accent-insensitive matches.
   */
  public function __construct(
    private readonly ?TransliterationInterface $transliteration = NULL,
  ) {}

  /**
   * Returns the bundles matching the requested name.
   *
   * Levels, from strict to loose; the first level with matches wins:
   * machine name (case-insensitive), label (case-insensitive), then both
   * without accents and treating "_" and "-" as spaces.
   *
   * @param string $requested
   *   The requested bundle (machine name or label).
   * @param array<string, string> $bundles
   *   Candidate labels keyed by machine name.
   *
   * @return list<string>
   *   The matching machine names (several when labels are ambiguous).
   */
  public function match(string $requested, array $bundles): array {
    $needle = $this->normalize($requested);
    if ($needle === '') {
      return [];
    }
    foreach (array_keys($bundles) as $id) {
      if ($this->normalize((string) $id) === $needle) {
        return [(string) $id];
      }
    }
    $matches = [];
    foreach ($bundles as $id => $label) {
      if ($this->normalize($label) === $needle) {
        $matches[] = (string) $id;
      }
    }
    if ($matches !== []) {
      return $matches;
    }
    $looseNeedle = $this->loose($requested);
    if ($looseNeedle === '') {
      return [];
    }
    foreach ($bundles as $id => $label) {
      if ($this->loose((string) $id) === $looseNeedle || $this->loose($label) === $looseNeedle) {
        $matches[] = (string) $id;
      }
    }
    return $matches;
  }

  /**
   * Lowercases and collapses whitespace.
   */
  private function normalize(string $value): string {
    return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
  }

  /**
   * Normalizes without accents, treating "_" and "-" as spaces.
   */
  private function loose(string $value): string {
    $value = $this->normalize(str_replace(['_', '-'], ' ', $value));
    if ($this->transliteration !== NULL) {
      $value = $this->transliteration->transliterate($value, 'en', '');
    }
    return $this->normalize($value);
  }

}
