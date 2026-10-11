<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

/**
 * Immutable canonical document (canonical-document.v1, design section 8.2).
 *
 * toArray() produces exactly the JSON object accepted by
 * POST /v1/ingest/documents: NULL members, an empty metadata map and the
 * local-only "attachment" member are omitted.
 */
final class CanonicalDocument {

  /**
   * Plain text content.
   */
  public const FORMAT_TEXT = 'text';

  /**
   * Markdown content.
   */
  public const FORMAT_MARKDOWN = 'markdown';

  /**
   * HTML content.
   */
  public const FORMAT_HTML = 'html';

  /**
   * Maximum title length accepted by the platform.
   */
  public const MAX_TITLE_LENGTH = 500;

  /**
   * Constructs a CanonicalDocument.
   *
   * @param string $sourceId
   *   Remote source_id, e.g. drupal:node:42:es (^[A-Za-z0-9_.:/@-]+$, max 256).
   * @param string $knowledgeBase
   *   Knowledge base key (^[a-z][a-z0-9_-]{1,62}$).
   * @param string $type
   *   Document type (^[a-z][a-z0-9_]{1,40}$), e.g. page, guide, product,
   *   document.
   * @param string $title
   *   Title (1..500 characters; longer titles are cut by toArray()).
   * @param string $version
   *   Version (1..64 characters).
   * @param string|null $locale
   *   Platform locale (^[a-z]{2}(-[A-Z]{2})?$).
   * @param string|null $text
   *   Text content (exclusive with $reference).
   * @param string|null $contentFormat
   *   One of the FORMAT_* constants.
   * @param array<string, mixed>|null $reference
   *   Original reference {download_url, media_type, access_token?,
   *   expires_at?} (exclusive with $text).
   * @param string|null $canonicalUrl
   *   Absolute user-facing URL used in citations.
   * @param list<string> $acl
   *   Principals: public, authenticated, role:<id>, user:<id>, group:<id>.
   *   Never empty when sent (an empty ACL would default to public).
   * @param string|null $siteId
   *   Public site ID when the document is limited to one site.
   * @param array<string, mixed> $metadata
   *   Metadata map (at most 60 keys).
   * @param string|null $contentHash
   *   SHA-256 hex digest, optionally prefixed with "sha256:".
   * @param string|null $createdAt
   *   ISO 8601 creation date.
   * @param string|null $updatedAt
   *   ISO 8601 update date.
   * @param string|null $attachment
   *   Local only: UUID of the file the document represents (never sent).
   */
  public function __construct(
    public readonly string $sourceId,
    public readonly string $knowledgeBase,
    public readonly string $type,
    public readonly string $title,
    public readonly string $version,
    public readonly ?string $locale = NULL,
    public readonly ?string $text = NULL,
    public readonly ?string $contentFormat = NULL,
    public readonly ?array $reference = NULL,
    public readonly ?string $canonicalUrl = NULL,
    public readonly array $acl = [],
    public readonly ?string $siteId = NULL,
    public readonly array $metadata = [],
    public readonly ?string $contentHash = NULL,
    public readonly ?string $createdAt = NULL,
    public readonly ?string $updatedAt = NULL,
    public readonly ?string $attachment = NULL,
  ) {}

  /**
   * Formats a timestamp as ISO 8601 in UTC (e.g. 2026-10-10T18:15:00+00:00).
   *
   * @param int $timestamp
   *   The Unix timestamp.
   *
   * @return string
   *   The date.
   */
  public static function formatDate(int $timestamp): string {
    return gmdate('Y-m-d\TH:i:s', $timestamp) . '+00:00';
  }

  /**
   * Returns a copy with another source ID.
   */
  public function withSourceId(string $sourceId): self {
    return $this->with(['sourceId' => $sourceId]);
  }

  /**
   * Returns a copy with another knowledge base.
   */
  public function withKnowledgeBase(string $knowledgeBase): self {
    return $this->with(['knowledgeBase' => $knowledgeBase]);
  }

  /**
   * Returns a copy with another type.
   */
  public function withType(string $type): self {
    return $this->with(['type' => $type]);
  }

  /**
   * Returns a copy with another title.
   */
  public function withTitle(string $title): self {
    return $this->with(['title' => $title]);
  }

  /**
   * Returns a copy with another version.
   */
  public function withVersion(string $version): self {
    return $this->with(['version' => $version]);
  }

  /**
   * Returns a copy with another locale.
   */
  public function withLocale(?string $locale): self {
    return $this->with(['locale' => $locale]);
  }

  /**
   * Returns a copy with text content (and no reference).
   *
   * @param string $text
   *   The text.
   * @param string $contentFormat
   *   One of the FORMAT_* constants.
   *
   * @return self
   *   The new document.
   */
  public function withText(string $text, string $contentFormat = self::FORMAT_HTML): self {
    return $this->with(['text' => $text, 'contentFormat' => $contentFormat, 'reference' => NULL]);
  }

  /**
   * Returns a copy with another content format.
   */
  public function withContentFormat(?string $contentFormat): self {
    return $this->with(['contentFormat' => $contentFormat]);
  }

  /**
   * Returns a copy with a reference to the original (and no text).
   *
   * @param array<string, mixed> $reference
   *   {download_url, media_type, access_token?, expires_at?}.
   *
   * @return self
   *   The new document.
   */
  public function withReference(array $reference): self {
    return $this->with(['reference' => $reference, 'text' => NULL]);
  }

  /**
   * Returns a copy with another canonical URL.
   */
  public function withCanonicalUrl(?string $canonicalUrl): self {
    return $this->with(['canonicalUrl' => $canonicalUrl]);
  }

  /**
   * Returns a copy with another ACL.
   *
   * @param list<string> $acl
   *   The principals.
   *
   * @return self
   *   The new document.
   */
  public function withAcl(array $acl): self {
    return $this->with(['acl' => array_values(array_unique($acl))]);
  }

  /**
   * Returns a copy with another site ID.
   */
  public function withSiteId(?string $siteId): self {
    return $this->with(['siteId' => $siteId]);
  }

  /**
   * Returns a copy with another metadata map.
   *
   * @param array<string, mixed> $metadata
   *   The metadata.
   *
   * @return self
   *   The new document.
   */
  public function withMetadata(array $metadata): self {
    return $this->with(['metadata' => $metadata]);
  }

  /**
   * Returns a copy with one metadata key set (NULL removes it).
   *
   * @param string $key
   *   The metadata key.
   * @param mixed $value
   *   The value.
   *
   * @return self
   *   The new document.
   */
  public function withMetadataValue(string $key, mixed $value): self {
    $metadata = $this->metadata;
    if ($value === NULL) {
      unset($metadata[$key]);
    }
    else {
      $metadata[$key] = $value;
    }
    return $this->with(['metadata' => $metadata]);
  }

  /**
   * Returns a copy with another content hash.
   */
  public function withContentHash(?string $contentHash): self {
    return $this->with(['contentHash' => $contentHash]);
  }

  /**
   * Returns a copy with another creation date (ISO 8601).
   */
  public function withCreatedAt(?string $createdAt): self {
    return $this->with(['createdAt' => $createdAt]);
  }

  /**
   * Returns a copy with another update date (ISO 8601).
   */
  public function withUpdatedAt(?string $updatedAt): self {
    return $this->with(['updatedAt' => $updatedAt]);
  }

  /**
   * Returns a copy with another attachment UUID (local only).
   */
  public function withAttachment(?string $attachment): self {
    return $this->with(['attachment' => $attachment]);
  }

  /**
   * Whether the document carries a reference to an original.
   */
  public function isReference(): bool {
    return $this->reference !== NULL;
  }

  /**
   * Returns the canonical-document.v1 JSON object.
   *
   * @return array<string, mixed>
   *   The document, without NULL members, empty metadata nor attachment.
   *
   * @throws \LogicException
   *   When the ACL is empty or text/reference are both set or both missing:
   *   such documents must never be sent.
   */
  public function toArray(): array {
    if ($this->acl === []) {
      throw new \LogicException(sprintf('Document %s has an empty ACL.', $this->sourceId));
    }
    if (($this->text === NULL) === ($this->reference === NULL)) {
      throw new \LogicException(sprintf('Document %s must have exactly one of text or reference.', $this->sourceId));
    }
    $reference = NULL;
    if ($this->reference !== NULL) {
      $reference = array_filter($this->reference, static fn (mixed $value): bool => $value !== NULL);
    }
    $data = [
      'source_id' => $this->sourceId,
      'knowledge_base' => $this->knowledgeBase,
      'site_id' => $this->siteId,
      'type' => $this->type,
      'locale' => $this->locale,
      'title' => mb_substr($this->title, 0, self::MAX_TITLE_LENGTH),
      'text' => $this->text,
      'content_format' => $this->contentFormat,
      'reference' => $reference,
      'canonical_url' => $this->canonicalUrl,
      'acl' => array_values($this->acl),
      'version' => $this->version,
      'content_hash' => $this->contentHash,
      'metadata' => $this->metadata === [] ? NULL : $this->metadata,
      'created_at' => $this->createdAt,
      'updated_at' => $this->updatedAt,
    ];
    return array_filter($data, static fn (mixed $value): bool => $value !== NULL);
  }

  /**
   * Builds a copy with some properties changed.
   *
   * @param array<string, mixed> $changes
   *   New values keyed by constructor parameter name.
   *
   * @return self
   *   The new document.
   */
  private function with(array $changes): self {
    return new self(...array_replace(get_object_vars($this), $changes));
  }

}
