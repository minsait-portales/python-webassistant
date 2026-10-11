<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Form\SourceForm;
use Drupal\webassistant_connector\Form\SourcePreviewForm;

/**
 * Defines the WebAssistant source config entity (design section 8.1).
 *
 * Config names: webassistant_connector.source.<id>.
 */
#[ConfigEntityType(
  id: 'webassistant_source',
  label: new TranslatableMarkup('WebAssistant source'),
  label_collection: new TranslatableMarkup('Sources'),
  label_singular: new TranslatableMarkup('source'),
  label_plural: new TranslatableMarkup('sources'),
  config_prefix: 'source',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => SourceListBuilder::class,
    'form' => [
      'default' => SourceForm::class,
      'add' => SourceForm::class,
      'edit' => SourceForm::class,
      'delete' => EntityDeleteForm::class,
      'preview' => SourcePreviewForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/services/webassistant-connector/sources',
    'add-form' => '/admin/config/services/webassistant-connector/sources/add',
    'edit-form' => '/admin/config/services/webassistant-connector/sources/{webassistant_source}',
    'delete-form' => '/admin/config/services/webassistant-connector/sources/{webassistant_source}/delete',
    'preview-form' => '/admin/config/services/webassistant-connector/sources/{webassistant_source}/preview',
  ],
  admin_permission: 'administer webassistant connector',
  label_count: [
    'singular' => '@count source',
    'plural' => '@count sources',
  ],
  config_export: [
    'id',
    'label',
    'entity_type',
    'bundles',
    'langcodes',
    'knowledge_base',
    'document_type',
    'normalizer',
    'acl_mode',
    'site_scoped',
    'mapping_version',
    'fields',
  ],
)]
class Source extends ConfigEntityBase implements SourceInterface {

  /**
   * The source ID (machine name).
   *
   * @var string|null
   */
  protected $id;

  /**
   * The label.
   *
   * @var string|null
   */
  protected $label;

  /**
   * The entity type ID to index.
   *
   * @var string
   */
  protected $entity_type = 'node';

  /**
   * Bundles to index (empty: all).
   *
   * @var string[]
   */
  protected $bundles = [];

  /**
   * Language codes to index (empty: all).
   *
   * @var string[]
   */
  protected $langcodes = [];

  /**
   * Remote knowledge base key.
   *
   * @var string
   */
  protected $knowledge_base = '';

  /**
   * Document type.
   *
   * @var string
   */
  protected $document_type = 'page';

  /**
   * Normalizer plugin ID.
   *
   * @var string
   */
  protected $normalizer = 'content_entity';

  /**
   * ACL mode: access, public or authenticated.
   *
   * @var string
   */
  protected $acl_mode = SourceInterface::ACL_ACCESS;

  /**
   * Whether documents are limited to the configured site.
   *
   * @var bool
   */
  protected $site_scoped = FALSE;

  /**
   * Mapping version.
   *
   * @var int
   */
  protected $mapping_version = 1;

  /**
   * Field roles.
   *
   * @var array<int, array<string, mixed>>
   */
  protected $fields = [];

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): string {
    return (string) $this->entity_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    return self::stringList($this->bundles);
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcodes(): array {
    return self::stringList($this->langcodes);
  }

  /**
   * {@inheritdoc}
   */
  public function getKnowledgeBase(): string {
    return (string) $this->knowledge_base;
  }

  /**
   * {@inheritdoc}
   */
  public function getDocumentType(): string {
    return (string) $this->document_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getNormalizerId(): string {
    return (string) ($this->normalizer ?: 'content_entity');
  }

  /**
   * {@inheritdoc}
   */
  public function getAclMode(): string {
    return in_array($this->acl_mode, SourceInterface::ACL_MODES, TRUE) ? $this->acl_mode : SourceInterface::ACL_ACCESS;
  }

  /**
   * {@inheritdoc}
   */
  public function isSiteScoped(): bool {
    return (bool) $this->site_scoped;
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingVersion(): int {
    return max(1, (int) $this->mapping_version);
  }

  /**
   * {@inheritdoc}
   */
  public function getFields(): array {
    $fields = [];
    foreach (is_array($this->fields) ? $this->fields : [] as $row) {
      if (!is_array($row) || empty($row['field']) || empty($row['role'])) {
        continue;
      }
      $item = ['field' => (string) $row['field'], 'role' => (string) $row['role']];
      if (isset($row['key']) && $row['key'] !== '') {
        $item['key'] = (string) $row['key'];
      }
      if (isset($row['depth']) && $row['depth'] !== '') {
        $item['depth'] = (int) $row['depth'];
      }
      if (!empty($row['target_bundles']) && is_array($row['target_bundles'])) {
        $item['target_bundles'] = self::stringList($row['target_bundles']);
      }
      $fields[] = $item;
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldsByRole(string $role): array {
    return array_values(array_filter($this->getFields(), static fn (array $item): bool => $item['role'] === $role));
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() !== $this->getTargetEntityTypeId()) {
      return FALSE;
    }
    $bundles = $this->getBundles();
    return $bundles === [] || in_array($entity->bundle(), $bundles, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function appliesToLangcode(string $langcode): bool {
    $langcodes = $this->getLangcodes();
    return $langcodes === [] || in_array($langcode, $langcodes, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingHash(): string {
    $bundles = $this->getBundles();
    sort($bundles);
    $langcodes = $this->getLangcodes();
    sort($langcodes);
    $fields = array_map(static function (array $item): array {
      ksort($item);
      return $item;
    }, $this->getFields());
    $data = [
      'entity_type' => $this->getTargetEntityTypeId(),
      'bundles' => $bundles,
      'langcodes' => $langcodes,
      'knowledge_base' => $this->getKnowledgeBase(),
      'document_type' => $this->getDocumentType(),
      'normalizer' => $this->getNormalizerId(),
      'acl_mode' => $this->getAclMode(),
      'site_scoped' => $this->isSiteScoped(),
      'mapping_version' => $this->getMappingVersion(),
      'fields' => $fields,
      'third_party_settings' => self::sortRecursive($this->third_party_settings ?? []),
    ];
    return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * {@inheritdoc}
   */
  public function getAttachmentSettings(string $module): array {
    return $this->getThirdPartySettings($module);
  }

  /**
   * {@inheritdoc}
   *
   * Adds the provider of the indexed entity type, the bundle config entities
   * and the provider of the normalizer plugin, so that uninstalling them
   * removes the source.
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityType = $entityTypeManager->getDefinition($this->getTargetEntityTypeId(), FALSE);
    if ($entityType !== NULL) {
      $this->addDependency('module', $entityType->getProvider());
      $bundleEntityType = $entityType->getBundleEntityType();
      if ($bundleEntityType !== NULL && $this->getBundles() !== []) {
        foreach ($entityTypeManager->getStorage($bundleEntityType)->loadMultiple($this->getBundles()) as $bundle) {
          $this->addDependency($bundle->getConfigDependencyKey(), $bundle->getConfigDependencyName());
        }
      }
    }
    $normalizer = \Drupal::service('plugin.manager.webassistant_connector.normalizer')->getDefinition($this->getNormalizerId(), FALSE);
    if (is_array($normalizer) && isset($normalizer['provider']) && $normalizer['provider'] !== 'webassistant_connector') {
      $this->addDependency('module', $normalizer['provider']);
    }
    return $this;
  }

  /**
   * Casts a configuration list to a list of non-empty strings.
   *
   * @param mixed $values
   *   The raw value.
   *
   * @return list<string>
   *   The strings.
   */
  private static function stringList(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $list = [];
    foreach ($values as $value) {
      if (is_scalar($value) && (string) $value !== '') {
        $list[] = (string) $value;
      }
    }
    return array_values(array_unique($list));
  }

  /**
   * Sorts associative arrays by key recursively (lists keep their order).
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   The sorted value.
   */
  private static function sortRecursive(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value);
    }
    return array_map([self::class, 'sortRecursive'], $value);
  }

}
