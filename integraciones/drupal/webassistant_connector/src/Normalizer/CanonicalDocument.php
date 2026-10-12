<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

/**
 * Immutable canonical document (canonical-document.v1, design section 8.2).
 *
 * The method toArray() produces exactly the JSON object accepted by
 * POST /v1/ingest/documents: NULL members, an empty metadata map and the
 * local-only "attachment" member are omitted. violations() checks the
 * constraints of the JSON schema that Drupal can get wrong.
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
   *   The reference: download_url, media_type and the optional access_token
   *   and expires_at.
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
   * Pattern of source IDs (canonical-document.v1).
   */
  public const SOURCE_ID_PATTERN = '/^[A-Za-z0-9_.:\/@-]{1,256}$/';

  /**
   * Pattern of knowledge base keys (canonical-document.v1).
   */
  public const KNOWLEDGE_BASE_PATTERN = '/^[a-z][a-z0-9_-]{1,62}$/';

  /**
   * Pattern of document types (canonical-document.v1).
   */
  public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{1,40}$/';

  /**
   * Pattern of locales (canonical-document.v1).
   */
  public const LOCALE_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';

  /**
   * Pattern of ACL principals (canonical-document.v1).
   */
  public const PRINCIPAL_PATTERN = '/^(public|authenticated|(role|user|group):[A-Za-z0-9_.@-]+)$/';

  /**
   * Maximum text length accepted by the platform.
   */
  public const MAX_TEXT_LENGTH = 2000000;

  /**
   * Maximum number of metadata keys accepted by the platform.
   */
  public const MAX_METADATA_KEYS = 60;

  /**
   * Returns the constraints of canonical-document.v1 the document violates.
   *
   * Checked before sending so that problems show up in the preview (the
   * platform would reject the document anyway).
   *
   * @return list<string>
   *   English messages naming the member, without its value (empty when
   *   the document is valid).
   */
  public function violations(): array {
    $violations = [];
    if (!preg_match(self::SOURCE_ID_PATTERN, $this->sourceId)) {
      $violations[] = 'source_id does not match ^[A-Za-z0-9_.:/@-]{1,256}$.';
    }
    if (!preg_match(self::KNOWLEDGE_BASE_PATTERN, $this->knowledgeBase)) {
      $violations[] = 'knowledge_base does not match ^[a-z][a-z0-9_-]{1,62}$.';
    }
    if (!preg_match(self::TYPE_PATTERN, $this->type)) {
      $violations[] = 'type does not match ^[a-z][a-z0-9_]{1,40}$.';
    }
    if (trim($this->title) === '') {
      $violations[] = 'title is empty.';
    }
    $versionLength = strlen($this->version);
    if ($versionLength < 1 || $versionLength > 64) {
      $violations[] = 'version must have 1 to 64 characters.';
    }
    if ($this->locale !== NULL && !preg_match(self::LOCALE_PATTERN, $this->locale)) {
      $violations[] = 'locale does not match ^[a-z]{2}(-[A-Z]{2})?$.';
    }
    if (($this->text === NULL) === ($this->reference === NULL)) {
      $violations[] = 'exactly one of text or reference is required.';
    }
    if ($this->text !== NULL && mb_strlen($this->text) > self::MAX_TEXT_LENGTH) {
      $violations[] = sprintf('text exceeds %d characters.', self::MAX_TEXT_LENGTH);
    }
    if ($this->reference !== NULL) {
      if (!is_string($this->reference['download_url'] ?? NULL) || !preg_match('#^https?://#', $this->reference['download_url'])) {
        $violations[] = 'reference.download_url must be an absolute http(s) URL.';
      }
      if (!is_string($this->reference['media_type'] ?? NULL) || $this->reference['media_type'] === '') {
        $violations[] = 'reference.media_type is required.';
      }
    }
    if ($this->canonicalUrl !== NULL && !preg_match('#^https?://#', $this->canonicalUrl)) {
      $violations[] = 'canonical_url must be an absolute http(s) URL.';
    }
    if ($this->acl === []) {
      $violations[] = 'acl is empty.';
    }
    foreach ($this->acl as $principal) {
      if (!preg_match(self::PRINCIPAL_PATTERN, $principal)) {
        $violations[] = 'acl contains a principal that does not match the ACL pattern.';
        break;
      }
    }
    if ($this->contentHash !== NULL && !preg_match('/^(sha256:)?[a-f0-9]{64}$/', $this->contentHash)) {
      $violations[] = 'content_hash is not a SHA-256 hex digest.';
    }
    if (count($this->metadata) > self::MAX_METADATA_KEYS) {
      $violations[] = sprintf('metadata has more than %d keys.', self::MAX_METADATA_KEYS);
    }
    return $violations;
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
      // Always a JSON array, even if the ACL was built with other keys.
      'acl' => array_values(array_unique($this->acl)),
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
