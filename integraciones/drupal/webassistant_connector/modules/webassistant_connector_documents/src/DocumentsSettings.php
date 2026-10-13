<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents;

use Drupal\Component\Utility\UrlHelper;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;

/**
 * Attachment settings of a source (third-party settings, design section 11).
 *
 * Stored as webassistant_connector.source.*.third_party.MODULE. Values read
 * from configuration are sanitized: unknown media types and invalid field
 * names are dropped, numbers are clamped and invalid URLs or knowledge base
 * keys are ignored, so a hand-edited configuration never widens what is
 * sent.
 */
final class DocumentsSettings {

  /**
   * Provider of the third-party settings.
   */
  public const MODULE = 'webassistant_connector_documents';

  /**
   * Default and maximum file size: the download limit of the platform.
   */
  public const MAX_BYTES = 50000000;

  /**
   * Default lifetime of signed download URLs (7 days).
   */
  public const DEFAULT_URL_TTL = 604800;

  /**
   * Minimum lifetime: the platform retries a download for some minutes.
   */
  public const MIN_URL_TTL = 1800;

  /**
   * Maximum lifetime of signed download URLs (30 days).
   */
  public const MAX_URL_TTL = 2592000;

  /**
   * Pattern of field machine names.
   */
  private const FIELD_PATTERN = '/^[a-z0-9_]{1,128}$/';

  /**
   * Constructs a DocumentsSettings object.
   *
   * @param bool $enabled
   *   Whether attached documents are indexed.
   * @param list<string> $fields
   *   File, image and media reference fields to read.
   * @param string $knowledgeBase
   *   Knowledge base of the attachments ('' = the one of the source).
   * @param list<string> $mimeTypes
   *   Allowed platform media types (MediaTypes::ALL values).
   * @param int $maxBytes
   *   Larger files are not indexed.
   * @param int $urlTtl
   *   Lifetime of the signed download URLs in seconds.
   * @param string $downloadBaseUrl
   *   Base URL with which the platform reaches Drupal ('' = the public base
   *   URL of the synchronization settings).
   */
  public function __construct(
    public readonly bool $enabled = FALSE,
    public readonly array $fields = [],
    public readonly string $knowledgeBase = '',
    public readonly array $mimeTypes = MediaTypes::DEFAULTS,
    public readonly int $maxBytes = self::MAX_BYTES,
    public readonly int $urlTtl = self::DEFAULT_URL_TTL,
    public readonly string $downloadBaseUrl = '',
  ) {}

  /**
   * Reads the settings of a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   *
   * @return self
   *   The sanitized settings (defaults when the source has none).
   */
  public static function fromSource(SourceInterface $source): self {
    return self::fromArray($source->getAttachmentSettings(self::MODULE));
  }

  /**
   * Builds sanitized settings from raw values (configuration or form).
   *
   * @param array<string, mixed> $values
   *   The raw values; missing keys take their default.
   *
   * @return self
   *   The settings.
   */
  public static function fromArray(array $values): self {
    $fields = [];
    foreach (self::stringList($values['fields'] ?? []) as $field) {
      if (preg_match(self::FIELD_PATTERN, $field)) {
        $fields[$field] = $field;
      }
    }
    $mimeTypes = array_key_exists('mime_types', $values)
      ? array_values(array_intersect(MediaTypes::ALL, self::stringList($values['mime_types'])))
      : MediaTypes::DEFAULTS;
    $knowledgeBase = trim(is_scalar($values['knowledge_base'] ?? NULL) ? (string) $values['knowledge_base'] : '');
    if (!preg_match(CanonicalDocument::KNOWLEDGE_BASE_PATTERN, $knowledgeBase)) {
      $knowledgeBase = '';
    }
    $maxBytes = is_numeric($values['max_bytes'] ?? NULL) ? (int) $values['max_bytes'] : self::MAX_BYTES;
    $urlTtl = is_numeric($values['url_ttl'] ?? NULL) ? (int) $values['url_ttl'] : self::DEFAULT_URL_TTL;
    $baseUrl = trim(is_scalar($values['download_base_url'] ?? NULL) ? (string) $values['download_base_url'] : '');
    return new self(
      enabled: !empty($values['enabled']),
      fields: array_values($fields),
      knowledgeBase: $knowledgeBase,
      mimeTypes: $mimeTypes,
      maxBytes: max(1, min(self::MAX_BYTES, $maxBytes)),
      urlTtl: max(self::MIN_URL_TTL, min(self::MAX_URL_TTL, $urlTtl)),
      downloadBaseUrl: self::isValidBaseUrl($baseUrl) ? rtrim($baseUrl, '/') : '',
    );
  }

  /**
   * Whether attachments must be contributed (enabled, fields and types).
   */
  public function isActive(): bool {
    return $this->enabled && $this->fields !== [] && $this->mimeTypes !== [];
  }

  /**
   * Returns the knowledge base of the attachments of a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   *
   * @return string
   *   The configured knowledge base, or the one of the source.
   */
  public function knowledgeBaseFor(SourceInterface $source): string {
    return $this->knowledgeBase !== '' ? $this->knowledgeBase : $source->getKnowledgeBase();
  }

  /**
   * Returns the settings as stored in configuration (schema order).
   *
   * @return array{enabled: bool, fields: list<string>, knowledge_base: string, mime_types: list<string>, max_bytes: int, url_ttl: int, download_base_url: string}
   *   The values.
   */
  public function toArray(): array {
    return [
      'enabled' => $this->enabled,
      'fields' => $this->fields,
      'knowledge_base' => $this->knowledgeBase,
      'mime_types' => $this->mimeTypes,
      'max_bytes' => $this->maxBytes,
      'url_ttl' => $this->urlTtl,
      'download_base_url' => $this->downloadBaseUrl,
    ];
  }

  /**
   * Whether a download base URL is an absolute http(s) URL without query.
   *
   * @param string $url
   *   The URL.
   *
   * @return bool
   *   TRUE if usable as the base of signed download URLs.
   */
  public static function isValidBaseUrl(string $url): bool {
    if ($url === '' || !preg_match('#^https?://#i', $url) || !UrlHelper::isValid($url, TRUE)) {
      return FALSE;
    }
    $parts = parse_url($url);
    return is_array($parts)
      && ($parts['host'] ?? '') !== ''
      && !isset($parts['query'])
      && !isset($parts['fragment'])
      && !isset($parts['user'])
      && !isset($parts['pass']);
  }

  /**
   * Casts a list of values (or checkboxes values) to non-empty strings.
   *
   * @param mixed $values
   *   The raw value.
   *
   * @return list<string>
   *   The strings, without duplicates.
   */
  private static function stringList(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $strings = [];
    foreach ($values as $value) {
      if ((is_string($value) || is_int($value)) && (string) $value !== '' && (string) $value !== '0') {
        $strings[(string) $value] = (string) $value;
      }
    }
    return array_values($strings);
  }

}
