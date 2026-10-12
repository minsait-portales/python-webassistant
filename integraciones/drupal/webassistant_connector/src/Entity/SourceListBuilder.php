<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Entity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the WebAssistant sources (design section 10).
 *
 * Columns: label, status, entity type and bundles, languages, knowledge
 * base, document type and ACL mode; operations add "Preview" (preview-form
 * link) before "Edit".
 */
final class SourceListBuilder extends ConfigEntityListBuilder {

  /**
   * Constructs a SourceListBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The storage of sources.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (labels of the indexed entity types).
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new self(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Source');
    $header['status'] = $this->t('Status');
    $header['entities'] = $this->t('Content');
    $header['langcodes'] = [
      'data' => $this->t('Languages'),
      'class' => [RESPONSIVE_PRIORITY_LOW],
    ];
    $header['knowledge_base'] = $this->t('Knowledge base');
    $header['document_type'] = [
      'data' => $this->t('Document type'),
      'class' => [RESPONSIVE_PRIORITY_LOW],
    ];
    $header['acl_mode'] = [
      'data' => $this->t('Access'),
      'class' => [RESPONSIVE_PRIORITY_MEDIUM],
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof SourceInterface);
    $row['label'] = $entity->label();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    $row['entities'] = $this->describeEntities($entity);
    $row['langcodes'] = $this->describeLangcodes($entity);
    $row['knowledge_base'] = $entity->getKnowledgeBase();
    $row['document_type'] = $entity->getDocumentType();
    $row['acl_mode'] = match ($entity->getAclMode()) {
      SourceInterface::ACL_PUBLIC => $this->t('Public'),
      SourceInterface::ACL_AUTHENTICATED => $this->t('Authenticated users'),
      SourceInterface::ACL_FIELD => $this->t('From a field'),
      default => $this->t('Drupal access'),
    };
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    // The parent declares the cacheability argument in a comment only.
    $operations = parent::getDefaultOperations(...func_get_args());
    $cacheability ??= new CacheableMetadata();
    if ($entity->hasLinkTemplate('preview-form')) {
      $url = $entity->toUrl('preview-form');
      $access = $url->access(NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $operations['preview'] = [
          'title' => $this->t('Preview'),
          'weight' => 5,
          'url' => $url,
        ];
      }
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('There are no sources yet. Add a source to send content to the WebAssistant platform.');
    return $build;
  }

  /**
   * Describes the entity type and bundles of a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   *
   * @return string
   *   E.g. "Content: Article, Basic page" or "Content (all bundles)".
   */
  private function describeEntities(SourceInterface $source): string {
    $entityTypeId = $source->getTargetEntityTypeId();
    $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if ($definition === NULL) {
      return (string) $this->t('@type (missing)', ['@type' => $entityTypeId]);
    }
    $label = (string) $definition->getLabel();
    $bundles = $source->getBundles();
    if ($bundles === []) {
      return $definition->getBundleEntityType() !== NULL
        ? (string) $this->t('@type (all bundles)', ['@type' => $label])
        : $label;
    }
    $info = $this->bundleInfo->getBundleInfo($entityTypeId);
    $labels = array_map(static fn (string $bundle): string => (string) ($info[$bundle]['label'] ?? $bundle), $bundles);
    return $label . ': ' . implode(', ', $labels);
  }

  /**
   * Describes the languages of a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   *
   * @return string
   *   Language names, or "All".
   */
  private function describeLangcodes(SourceInterface $source): string {
    $langcodes = $source->getLangcodes();
    if ($langcodes === []) {
      return (string) $this->t('All');
    }
    $names = [];
    foreach ($langcodes as $langcode) {
      $language = $this->languageManager->getLanguage($langcode);
      $names[] = $language !== NULL ? $language->getName() : $langcode;
    }
    return implode(', ', $names);
  }

}
