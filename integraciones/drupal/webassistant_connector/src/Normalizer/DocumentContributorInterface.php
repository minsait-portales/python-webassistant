<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Adds extra documents for a translation (design section 8.2).
 *
 * Implementations are services tagged
 * webassistant_connector.document_contributor (e.g. the attachments of
 * webassistant_connector_documents). They run after the normalizer, only for
 * the main document of indexable translations, while the current user is
 * switched to the least privileged principal of the ACL (amendment B1).
 * Every returned document must carry a non-empty ACL (usually the context
 * ACL intersected with the access to the contributed item): documents with
 * an empty ACL or without text nor reference are dropped by the
 * DocumentBuilder with a warning, never sent. With $context->preview set,
 * contributors must not issue credentials or have side effects.
 */
interface DocumentContributorInterface {

  /**
   * Whether the contributor is enabled for a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   *
   * @return bool
   *   TRUE if contribute() must be called for this source.
   */
  public function applies(SourceInterface $source): bool;

  /**
   * Returns extra documents derived from the translation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The default revision, in the requested translation.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The normalization context.
   * @param \Drupal\webassistant_connector\Normalizer\CanonicalDocument $parent
   *   The main document produced by the normalizer.
   *
   * @return list<\Drupal\webassistant_connector\Normalizer\CanonicalDocument>
   *   Extra documents (may be empty).
   */
  public function contribute(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context, CanonicalDocument $parent): array;

}
