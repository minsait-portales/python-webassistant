<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\webassistant_connector\Normalizer\CanonicalDocument;

/**
 * Result of DocumentBuilder::build() (design section 8.3).
 *
 * Either a non-empty list of documents (main document first) or a skip
 * reason; a skipped upsert becomes a delete of the mapped documents.
 */
final class DocumentBuildResult {

  /**
   * The default revision does not exist.
   */
  public const SKIP_DELETED = 'deleted';

  /**
   * The requested translation does not exist.
   */
  public const SKIP_TRANSLATION_MISSING = 'translation_missing';

  /**
   * The language is not listed in the source langcodes.
   */
  public const SKIP_LANGUAGE_EXCLUDED = 'language_excluded';

  /**
   * The entity is not published.
   */
  public const SKIP_UNPUBLISHED = 'unpublished';

  /**
   * An exclusion field is TRUE.
   */
  public const SKIP_EXCLUDED = 'excluded';

  /**
   * Nobody may view the entity (empty ACL).
   */
  public const SKIP_NO_ACCESS = 'no_access';

  /**
   * The source does not cover the entity (type, bundle or disabled source).
   */
  public const SKIP_NOT_APPLICABLE = 'not_applicable';

  /**
   * The normalizer produced no documents.
   */
  public const SKIP_EMPTY = 'empty';

  /**
   * Constructs a DocumentBuildResult. Use the named constructors.
   *
   * @param list<\Drupal\webassistant_connector\Normalizer\CanonicalDocument> $documents
   *   The documents (empty when skipped).
   * @param string|null $skipReason
   *   One of the SKIP_* constants, or NULL.
   * @param list<string> $warnings
   *   Warnings for administrators (amendment E3), e.g. "field_x dropped: no
   *   view access for anonymous". Shown in the preview, never sent.
   */
  private function __construct(
    public readonly array $documents,
    public readonly ?string $skipReason,
    public readonly array $warnings = [],
  ) {}

  /**
   * Builds a result with documents.
   *
   * @param list<\Drupal\webassistant_connector\Normalizer\CanonicalDocument> $documents
   *   The documents, main document first (at least one).
   * @param list<string> $warnings
   *   (optional) Warnings for administrators.
   *
   * @return self
   *   The result.
   *
   * @throws \InvalidArgumentException
   *   When the list is empty or contains other values.
   */
  public static function fromDocuments(array $documents, array $warnings = []): self {
    if ($documents === []) {
      throw new \InvalidArgumentException('A build result needs at least one document; use skip() instead.');
    }
    foreach ($documents as $document) {
      if (!$document instanceof CanonicalDocument) {
        throw new \InvalidArgumentException('Build results only contain CanonicalDocument objects.');
      }
    }
    return new self(array_values($documents), NULL, self::warningList($warnings));
  }

  /**
   * Builds a skipped result.
   *
   * @param string $reason
   *   One of the SKIP_* constants.
   * @param list<string> $warnings
   *   (optional) Warnings for administrators.
   *
   * @return self
   *   The result.
   */
  public static function skip(string $reason, array $warnings = []): self {
    return new self([], $reason, self::warningList($warnings));
  }

  /**
   * Whether the translation must not be indexed.
   */
  public function isSkipped(): bool {
    return $this->skipReason !== NULL;
  }

  /**
   * Returns the documents.
   *
   * @return list<\Drupal\webassistant_connector\Normalizer\CanonicalDocument>
   *   The documents.
   */
  public function getDocuments(): array {
    return $this->documents;
  }

  /**
   * Returns the skip reason, or NULL.
   */
  public function getSkipReason(): ?string {
    return $this->skipReason;
  }

  /**
   * Returns the main document, or NULL when skipped.
   */
  public function getMainDocument(): ?CanonicalDocument {
    return $this->documents[0] ?? NULL;
  }

  /**
   * Returns the warnings for administrators (amendment E3).
   *
   * @return list<string>
   *   The warnings (shown in the preview, never sent to the platform).
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

  /**
   * Returns a copy with more warnings.
   *
   * @param list<string> $warnings
   *   The warnings to add.
   *
   * @return self
   *   The new result.
   */
  public function withWarnings(array $warnings): self {
    return new self($this->documents, $this->skipReason, self::warningList(array_merge($this->warnings, $warnings)));
  }

  /**
   * Normalizes a list of warnings (strings, unique, in order).
   *
   * @param array<mixed> $warnings
   *   The warnings.
   *
   * @return list<string>
   *   The list.
   */
  private static function warningList(array $warnings): array {
    return array_values(array_unique(array_map('strval', array_filter($warnings, 'is_scalar'))));
  }

}
