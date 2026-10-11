<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webassistant_connector\Sync\DocumentBuilder;

/**
 * Preview tab of a source (design section 8.1, DR-04.8).
 *
 * Lets the administrator pick an entity and language and shows the exact
 * canonical JSON (DocumentBuilder::build(..., preview: TRUE)) or the skip
 * reason. Never saves the source. Form ID: webassistant_source_preview_form.
 */
final class SourcePreviewForm extends EntityForm {

  use AutowireTrait;

  /**
   * Constructs a SourcePreviewForm.
   *
   * @param \Drupal\webassistant_connector\Sync\DocumentBuilder $documentBuilder
   *   The document builder.
   */
  public function __construct(
    private readonly DocumentBuilder $documentBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    throw new \LogicException('Not implemented yet.');
  }

}
