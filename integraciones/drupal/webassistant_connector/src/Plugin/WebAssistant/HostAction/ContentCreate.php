<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\WebAssistant\HostAction;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\BundleMatcher;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens the creation form of a content type (design section 7).
 *
 * Params: content_type (required, max 128; ID or label among the types the
 * user can create), content_title (optional, max 300). Succeeds with a
 * redirect to node.add.
 */
#[HostAction(
  id: 'content.create',
  label: new TranslatableMarkup('Create content'),
  description: new TranslatableMarkup('Redirects to the creation form of a content type the user can create.'),
  permission: 'use webassistant content actions',
)]
final class ContentCreate extends HostActionPluginBase {

  /**
   * Maximum number of content types listed in a failed result.
   */
  public const MAX_LISTED_TYPES = 20;

  /**
   * Constructs a ContentCreate plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Component\Transliteration\TransliterationInterface|null $transliteration
   *   The transliteration service (accent-insensitive label matches).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'transliteration')]
    protected ?TransliterationInterface $transliteration = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Denied when the node module is not installed.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return parent::access($account)->andIf(AccessResult::allowedIf($this->nodeAvailable()));
  }

  /**
   * {@inheritdoc}
   */
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult {
    $params = $request->params;
    $this->assertAllowedParams($params, ['content_type', 'content_title']);
    $requested = (string) $this->stringParam($params, 'content_type', TRUE, 128);
    $title = $this->stringParam($params, 'content_title', FALSE, 300);
    if (!$this->nodeAvailable()) {
      throw $this->failure($this->t('Content actions are not available on this site.'));
    }

    $creatable = $this->creatableTypes($account);
    if ($creatable === []) {
      throw $this->failure($this->t('You are not allowed to create content.'));
    }
    $matches = (new BundleMatcher($this->transliteration))->match($requested, $creatable);
    if ($matches === []) {
      throw $this->failure(
        $this->t('The content type "@type" does not exist or you are not allowed to create it.', ['@type' => $requested]),
        ['content_types' => $this->typeList($creatable)],
      );
    }
    if (count($matches) > 1) {
      throw $this->failure(
        $this->t('Several content types match "@type". Please choose one.', ['@type' => $requested]),
        ['content_types' => $this->typeList(array_intersect_key($creatable, array_flip($matches)))],
      );
    }

    $bundle = $matches[0];
    $url = Url::fromRoute('node.add', ['node_type' => $bundle])->toString(TRUE)->getGeneratedUrl();
    $data = ['content_type' => $bundle, 'label' => $creatable[$bundle], 'url' => $url];
    if ($title !== NULL) {
      $data['content_title'] = $title;
    }
    return HostActionResult::succeeded(
      $data,
      $this->t('Opening the form to create a new @label.', ['@label' => $creatable[$bundle]]),
      $url,
    );
  }

  /**
   * Whether the node entity type is available.
   */
  private function nodeAvailable(): bool {
    return $this->moduleHandler->moduleExists('node') && $this->entityTypeManager->hasDefinition('node_type');
  }

  /**
   * Returns the content types the account may create.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array<string, string>
   *   Labels keyed by machine name, sorted by label.
   */
  private function creatableTypes(AccountInterface $account): array {
    $handler = $this->entityTypeManager->getAccessControlHandler('node');
    $types = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $id => $type) {
      if ($handler->createAccess((string) $id, $account, [], TRUE)->isAllowed()) {
        $types[(string) $id] = (string) $type->label();
      }
    }
    asort($types, SORT_NATURAL | SORT_FLAG_CASE);
    return $types;
  }

  /**
   * Formats content types for a failed result.
   *
   * @param array<string, string> $types
   *   Labels keyed by machine name.
   *
   * @return list<array{id: string, label: string}>
   *   At most MAX_LISTED_TYPES items.
   */
  private function typeList(array $types): array {
    $list = [];
    foreach (array_slice($types, 0, self::MAX_LISTED_TYPES, TRUE) as $id => $label) {
      $list[] = ['id' => (string) $id, 'label' => $label];
    }
    return $list;
  }

}
