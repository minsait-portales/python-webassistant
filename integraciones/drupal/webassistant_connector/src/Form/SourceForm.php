<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\webassistant_connector\Normalizer\NormalizerManager;

/**
 * Add/edit form of WebAssistant sources (design section 8.1).
 *
 * Form IDs: webassistant_source_add_form / webassistant_source_edit_form;
 * base form ID: webassistant_source_form (submodules alter it with
 * hook_form_webassistant_source_form_alter(), which also runs for the delete
 * and preview forms: check $form_state->getFormObject()->getOperation()).
 *
 * Discovers fields with EntityFieldManager (DR-04.2), lists compatible types
 * only, warns about sensitive fields (email, telephone, password, IP, user
 * references) and validates keys and patterns.
 */
final class SourceForm extends EntityForm {

  use AutowireTrait;

  /**
   * Constructs a SourceForm.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizerManager $normalizerManager
   *   The normalizer plugin manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly NormalizerManager $normalizerManager,
    private readonly LanguageManagerInterface $languageManager,
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    throw new \LogicException('Not implemented yet.');
  }

}
