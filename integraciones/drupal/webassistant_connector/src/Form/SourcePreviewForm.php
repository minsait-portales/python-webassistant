<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Sync\DocumentBuilder;
use Drupal\webassistant_connector\Sync\DocumentBuildResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Preview tab of a source (design section 8.1, DR-04.8).
 *
 * Lets the administrator pick an entity and language and shows the exact
 * canonical JSON (DocumentBuilder::build(..., preview: TRUE)) or the skip
 * reason, plus the warnings of the build (amendment E3). Never saves the
 * source, never sends nor stores anything: the preview flag tells
 * contributors not to issue signed URLs or credentials, and access tokens
 * are redacted anyway. The entity must be viewable by the administrator.
 * Form ID: webassistant_source_preview_form.
 */
final class SourcePreviewForm extends EntityForm {

  use AutowireTrait;

  /**
   * Form state key of the last preview.
   */
  private const RESULT_KEY = 'webassistant_connector_preview';

  /**
   * Pattern of entity IDs accepted by the form.
   */
  private const ENTITY_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/';

  /**
   * Constructs a SourcePreviewForm.
   *
   * @param \Drupal\webassistant_connector\Sync\DocumentBuilder $documentBuilder
   *   The document builder.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Psr\Log\LoggerInterface $connectorLogger
   *   The logger of the connector.
   */
  public function __construct(
    protected DocumentBuilder $documentBuilder,
    protected LanguageManagerInterface $languageManager,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    protected LoggerInterface $connectorLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $source = $this->getSource();
    $entityType = $this->entityTypeManager->getDefinition($source->getTargetEntityTypeId(), FALSE);

    $form['#title'] = $this->t('Preview of %label', ['%label' => $source->label()]);
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Builds the documents of an item exactly as the synchronization would send them. Nothing is sent to the platform or stored.'),
    ];
    if (!$source->status()) {
      $form['disabled'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('This source is disabled: its documents are only built for this preview.'),
      ];
    }
    if ($entityType === NULL) {
      $form['missing'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The entity type of this source does not exist.'),
      ];
      return $form;
    }

    $form['entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@type ID', ['@type' => $entityType->getLabel()]),
      '#required' => TRUE,
      '#size' => 20,
      '#maxlength' => 128,
      '#default_value' => (string) ($form_state->getValue('entity_id') ?? ''),
    ];
    $languages = ['' => $this->t('- Original language -')];
    foreach ($this->languageManager->getLanguages(LanguageInterface::STATE_ALL) as $langcode => $language) {
      $languages[(string) $langcode] = $language->getName();
    }
    $form['preview_langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $languages,
      '#default_value' => (string) ($form_state->getValue('preview_langcode') ?? ''),
    ];

    $result = $form_state->get(self::RESULT_KEY);
    if (is_array($result)) {
      $form['result'] = $this->buildResult($result);
    }
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    if (!isset($form['entity_id'])) {
      return [];
    }
    return [
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Preview'),
        '#submit' => ['::submitForm'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // A previous result never stays next to the errors of a new request.
    $form_state->set(self::RESULT_KEY, NULL);
    unset($form['result']);
    $entityId = trim((string) $form_state->getValue('entity_id'));
    if (!preg_match(self::ENTITY_ID_PATTERN, $entityId)) {
      $form_state->setErrorByName('entity_id', $this->t('Enter a valid ID.'));
      return;
    }
    $langcode = (string) $form_state->getValue('preview_langcode');
    if ($langcode !== '' && $this->languageManager->getLanguage($langcode) === NULL) {
      $form_state->setErrorByName('preview_langcode', $this->t('Choose a valid language.'));
    }
    $entity = $this->loadEntity($entityId);
    if ($entity === NULL) {
      $form_state->setErrorByName('entity_id', $this->t('There is no item with this ID.'));
      return;
    }
    // The preview shows content: only to administrators who may view it.
    if (!$entity->access('view')) {
      $form_state->setErrorByName('entity_id', $this->t('You are not allowed to view this item.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Builds the documents and rebuilds the form to show them. The source is
   * never saved.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source = $this->getSource();
    $entityId = trim((string) $form_state->getValue('entity_id'));
    $entity = $this->loadEntity($entityId);
    $form_state->setRebuild();
    if ($entity === NULL) {
      return;
    }
    $langcode = (string) $form_state->getValue('preview_langcode');
    if ($langcode === '') {
      $langcode = $entity->getUntranslated()->language()->getId();
    }
    $result = [
      'langcode' => $langcode,
      'translations' => array_keys($entity->getTranslationLanguages()),
    ];
    try {
      $build = $this->documentBuilder->build($source, $source->getTargetEntityTypeId(), (string) $entity->id(), $langcode, TRUE);
      $result['warnings'] = $build->getWarnings();
      if ($build->isSkipped()) {
        $result['skip'] = (string) $build->getSkipReason();
      }
      else {
        $documents = array_map(static fn ($document): array => self::redact($document->toArray()), $build->getDocuments());
        $result['count'] = count($documents);
        $result['json'] = json_encode($documents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      }
    }
    catch (\Throwable $exception) {
      $this->connectorLogger->error('Preview of @type @id (@langcode) for the source @source failed: @class: @message', [
        '@type' => $source->getTargetEntityTypeId(),
        '@id' => (string) $entity->id(),
        '@langcode' => $langcode,
        '@source' => (string) $source->id(),
        '@class' => get_class($exception),
        '@message' => $exception->getMessage(),
      ]);
      $result = ['error' => TRUE];
    }
    $form_state->set(self::RESULT_KEY, $result);
  }

  /**
   * {@inheritdoc}
   *
   * The preview form never changes the source.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
  }

  /**
   * Returns the translatable explanation of a skip reason.
   *
   * @param string $reason
   *   A DocumentBuildResult::SKIP_* value.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The explanation.
   */
  public static function skipReasonLabel(string $reason): TranslatableMarkup {
    return match ($reason) {
      DocumentBuildResult::SKIP_DELETED => new TranslatableMarkup('The item does not exist.'),
      DocumentBuildResult::SKIP_TRANSLATION_MISSING => new TranslatableMarkup('The item has no translation in this language.'),
      DocumentBuildResult::SKIP_LANGUAGE_EXCLUDED => new TranslatableMarkup('This language is not indexed by the source.'),
      DocumentBuildResult::SKIP_UNPUBLISHED => new TranslatableMarkup('The default revision of this translation is not published.'),
      DocumentBuildResult::SKIP_EXCLUDED => new TranslatableMarkup('A field with the Exclusion role is set.'),
      DocumentBuildResult::SKIP_NO_ACCESS => new TranslatableMarkup('Nobody can view this item: its access list is empty.'),
      DocumentBuildResult::SKIP_NOT_APPLICABLE => new TranslatableMarkup('The source does not cover this item (entity type, bundle or normalizer).'),
      DocumentBuildResult::SKIP_EMPTY => new TranslatableMarkup('The normalizer produced no document.'),
      default => new TranslatableMarkup('The item is not indexed (@reason).', ['@reason' => $reason]),
    };
  }

  /**
   * Builds the render array of a preview result.
   *
   * @param array<string, mixed> $result
   *   The result stored by submitForm().
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function buildResult(array $result): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['webassistant-source-preview']],
    ];
    if (!empty($result['error'])) {
      $build['error'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The documents could not be built. The details were logged.'),
      ];
      return $build;
    }
    $build['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Result'),
    ];
    $build['translations'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Language: @langcode. Translations of the item: @translations.', [
        '@langcode' => (string) $result['langcode'],
        '@translations' => implode(', ', array_map('strval', (array) ($result['translations'] ?? []))),
      ]),
    ];
    if (isset($result['skip'])) {
      $build['skip'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Not indexed: @reason (@code). The synchronization would delete its documents from the platform.', [
          '@reason' => self::skipReasonLabel((string) $result['skip']),
          '@code' => (string) $result['skip'],
        ]),
        '#attributes' => ['class' => ['webassistant-source-preview__skip']],
      ];
    }
    $warnings = array_map('strval', (array) ($result['warnings'] ?? []));
    if ($warnings !== []) {
      $build['warnings'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Warnings'),
        '#items' => $warnings,
        '#attributes' => ['class' => ['webassistant-source-preview__warnings']],
      ];
    }
    if (isset($result['json'])) {
      $build['count'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Documents that would be sent: @count.', ['@count' => (int) ($result['count'] ?? 0)]),
      ];
      $build['json'] = [
        '#prefix' => '<pre class="webassistant-source-preview__json">',
        '#plain_text' => (string) $result['json'],
        '#suffix' => '</pre>',
      ];
    }
    return $build;
  }

  /**
   * Loads the entity to preview (default revision).
   *
   * @param string $entityId
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity, or NULL if it does not exist or is not a content entity.
   */
  private function loadEntity(string $entityId): ?ContentEntityInterface {
    $entityTypeId = $this->getSource()->getTargetEntityTypeId();
    if (!$this->entityTypeManager->hasDefinition($entityTypeId) || !preg_match(self::ENTITY_ID_PATTERN, $entityId)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Returns the source being previewed.
   */
  private function getSource(): SourceInterface {
    $source = $this->getEntity();
    assert($source instanceof SourceInterface);
    return $source;
  }

  /**
   * Hides credentials of references (never shown, even to administrators).
   *
   * @param array<string, mixed> $document
   *   The canonical document.
   *
   * @return array<string, mixed>
   *   The document with reference.access_token redacted.
   */
  private static function redact(array $document): array {
    if (isset($document['reference']) && is_array($document['reference']) && isset($document['reference']['access_token'])) {
      $document['reference']['access_token'] = '[redacted]';
    }
    return $document;
  }

}
