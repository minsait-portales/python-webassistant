<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists the WebAssistant sources (design section 10).
 *
 * Columns: label, entity type and bundles, knowledge base, document type,
 * ACL mode, status; operations add "Preview" (preview-form link).
 */
final class SourceListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    throw new \LogicException('Not implemented yet.');
  }

}
