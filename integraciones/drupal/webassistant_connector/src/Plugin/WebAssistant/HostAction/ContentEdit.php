<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Plugin\WebAssistant\HostAction;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\webassistant_connector\Attribute\HostAction;
use Drupal\webassistant_connector\HostAction\BundleMatcher;
use Drupal\webassistant_connector\HostAction\HostActionPluginBase;
use Drupal\webassistant_connector\HostAction\HostActionRequest;
use Drupal\webassistant_connector\HostAction\HostActionResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens the edit form of a node found by title (design section 7).
 *
 * Params: content_title (required unless content_id is given, max 300),
 * content_type (optional, ID or label), content_id (optional, node ID or
 * UUID). Searches nodes with accessCheck(TRUE) and keeps the ones with
 * "view" and "update" access: exact title matches first, then partial
 * matches. One result redirects to its edit form; several fail with up to
 * five candidates [{content_id, title, url}]; none fails.
 */
#[HostAction(
  id: 'content.edit',
  label: new TranslatableMarkup('Edit content'),
  description: new TranslatableMarkup('Redirects to the edit form of a content item the user can update.'),
  permission: 'use webassistant content actions',
)]
final class ContentEdit extends HostActionPluginBase {

  /**
   * Maximum number of candidates returned when several nodes match.
   */
  public const MAX_CANDIDATES = 5;

  /**
   * Maximum number of nodes loaded per search.
   */
  public const SEARCH_LIMIT = 50;

  /**
   * Constructs a ContentEdit plugin.
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
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entityRepository
   *   The entity repository (translation of the current language).
   * @param \Drupal\Component\Transliteration\TransliterationInterface|null $transliteration
   *   The transliteration service (accent-insensitive type matches).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?EntityRepositoryInterface $entityRepository = NULL,
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
    $this->assertAllowedParams($params, ['content_title', 'content_type', 'content_id']);
    $contentId = $this->idParam($params, 'content_id', FALSE, 64);
    $title = $this->stringParam($params, 'content_title', $contentId === NULL, 300);
    $type = $this->stringParam($params, 'content_type', FALSE, 128);
    if (!$this->nodeAvailable()) {
      throw $this->failure($this->t('Content actions are not available on this site.'));
    }

    if ($contentId !== NULL) {
      return $this->editById($contentId, $account);
    }

    $bundle = $type === NULL ? NULL : $this->resolveBundle($type);
    $nodes = $this->search((string) $title, $bundle, TRUE, $account);
    if ($nodes === []) {
      $nodes = $this->search((string) $title, $bundle, FALSE, $account);
    }
    if ($nodes === []) {
      throw $this->failure($this->t('No content titled "@title" was found that you can edit.', ['@title' => $title]));
    }
    if (count($nodes) === 1) {
      return $this->redirectTo(reset($nodes));
    }
    $candidates = [];
    foreach (array_slice($nodes, 0, self::MAX_CANDIDATES) as $node) {
      $candidates[] = [
        'content_id' => (string) $node->id(),
        'title' => (string) $node->label(),
        'url' => $node->toUrl('edit-form')->toString(TRUE)->getGeneratedUrl(),
      ];
    }
    throw $this->failure(
      $this->t('Several content items match "@title". Please choose one.', ['@title' => $title]),
      ['candidates' => $candidates, 'total' => count($nodes)],
    );
  }

  /**
   * Opens the edit form of a node given its ID or UUID.
   *
   * @param string $contentId
   *   The node ID or UUID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The redirect result.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When the node does not exist, cannot be viewed or cannot be updated.
   */
  private function editById(string $contentId, AccountInterface $account): HostActionResult {
    $storage = $this->entityTypeManager->getStorage('node');
    $node = NULL;
    if (preg_match('/^[1-9]\d{0,18}$/', $contentId)) {
      $node = $storage->load($contentId);
    }
    elseif (Uuid::isValid($contentId)) {
      $loaded = $storage->loadByProperties(['uuid' => $contentId]);
      $node = $loaded === [] ? NULL : reset($loaded);
    }
    // Existence is not disclosed to users who cannot view the node.
    if (!$node instanceof ContentEntityInterface || !$node->access('view', $account)) {
      throw $this->failure($this->t('The requested content was not found.'));
    }
    if (!$node->access('update', $account)) {
      throw $this->failure($this->t('You are not allowed to edit this content.'));
    }
    return $this->redirectTo($this->translated($node));
  }

  /**
   * Searches nodes by title among the ones the account can view and update.
   *
   * @param string $title
   *   The title.
   * @param string|null $bundle
   *   The content type, if any.
   * @param bool $exact
   *   TRUE for an exact (case-insensitive) match, FALSE for "contains".
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return list<\Drupal\Core\Entity\ContentEntityInterface>
   *   Nodes (in the current language when translated), most recent first.
   */
  private function search(string $title, ?string $bundle, bool $exact, AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('title', $title, $exact ? '=' : 'CONTAINS')
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, self::SEARCH_LIMIT);
    if ($bundle !== NULL) {
      $query->condition('type', $bundle);
    }
    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }
    $nodes = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      // Like editById(): nodes the account cannot view are never disclosed.
      if ($node->access('view', $account) && $node->access('update', $account)) {
        $nodes[] = $this->translated($node);
      }
    }
    return $nodes;
  }

  /**
   * Resolves a content type by machine name or label.
   *
   * @param string $type
   *   The requested type.
   *
   * @return string
   *   The machine name.
   *
   * @throws \Drupal\webassistant_connector\HostAction\HostActionException
   *   When no single type matches.
   */
  private function resolveBundle(string $type): string {
    $bundles = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $id => $nodeType) {
      $bundles[(string) $id] = (string) $nodeType->label();
    }
    $matches = (new BundleMatcher($this->transliteration))->match($type, $bundles);
    if (count($matches) !== 1) {
      throw $this->failure($this->t('The content type "@type" was not found.', ['@type' => $type]));
    }
    return $matches[0];
  }

  /**
   * Returns the translation of the node for the current language.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The translation, or the node itself.
   */
  private function translated(ContentEntityInterface $node): ContentEntityInterface {
    if ($this->entityRepository === NULL) {
      return $node;
    }
    return $this->entityRepository->getTranslationFromContext($node);
  }

  /**
   * Builds the redirect result to the edit form of a node.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The node the account may update.
   *
   * @return \Drupal\webassistant_connector\HostAction\HostActionResult
   *   The result.
   */
  private function redirectTo(ContentEntityInterface $node): HostActionResult {
    $url = $node->toUrl('edit-form')->toString(TRUE)->getGeneratedUrl();
    $label = (string) $node->label();
    return HostActionResult::succeeded(
      ['content_id' => (string) $node->id(), 'title' => $label, 'url' => $url],
      $this->t('Opening the edit form of "@title".', ['@title' => $label]),
      $url,
    );
  }

  /**
   * Whether the node entity type is available.
   */
  private function nodeAvailable(): bool {
    return $this->moduleHandler->moduleExists('node') && $this->entityTypeManager->hasDefinition('node');
  }

}
