<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Normalizer;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;

/**
 * Converts a content entity translation into canonical documents (8.2).
 */
interface NormalizerInterface extends PluginInspectionInterface {

  /**
   * Normalizes a translation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The default revision, in the requested translation.
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source configuration.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   Values computed by the DocumentBuilder (ACL, locale, IDs, version...).
   *
   * @return list<\Drupal\webassistant_connector\Normalizer\CanonicalDocument>
   *   The documents, the main document first. An empty list means "not
   *   indexable" (e.g. an exclusion field is TRUE).
   */
  public function normalize(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context): array;

  /**
   * Whether the normalizer supports an entity type.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if supported.
   */
  public function applies(string $entityTypeId): bool;

}
