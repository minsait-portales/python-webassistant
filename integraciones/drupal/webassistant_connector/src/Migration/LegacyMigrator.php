<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Migration;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\webassistant_connector\Plugin\Block\WidgetBlock;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Migrates the legacy webassistant_ai widget to the connector (section 14).
 *
 * Copies the results selector and the open state of webassistant_ai.settings,
 * creates a webassistant_connector_widget block for every block of the
 * webassistant_ai plugin (same theme, region, weight, visibility and label)
 * and disables, never deletes, the legacy blocks. When blocks are migrated
 * the global placement is switched to "block" so the widget is not loaded
 * twice. Everything done is recorded in state so the migration is
 * idempotent and can be rolled back. Only existing configuration is touched:
 * without the legacy module there is nothing to migrate.
 *
 * Service: webassistant_connector.legacy_migrator.
 */
final class LegacyMigrator {

  /**
   * Plugin ID of the legacy block.
   */
  public const LEGACY_PLUGIN = 'webassistant_ai';

  /**
   * Name of the legacy settings.
   */
  public const LEGACY_CONFIG = 'webassistant_ai.settings';

  /**
   * Plugin ID of the connector block.
   */
  public const BLOCK_PLUGIN = 'webassistant_connector_widget';

  /**
   * State key of the migration record.
   */
  public const STATE_KEY = 'webassistant_connector.legacy_migration';

  /**
   * The connector settings.
   */
  private const CONFIG = 'webassistant_connector.settings';

  /**
   * Constructs a LegacyMigrator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns what can be migrated.
   *
   * @return array{settings: bool, blocks: list<string>, migrated: array<string, string>}
   *   Whether legacy settings exist, the legacy block IDs and the blocks
   *   already migrated (legacy ID => connector block ID).
   */
  public function inspect(): array {
    $record = $this->record();
    return [
      'settings' => !$this->configFactory->get(self::LEGACY_CONFIG)->isNew(),
      'blocks' => array_keys($this->legacyBlocks()),
      'migrated' => $record['blocks'],
    ];
  }

  /**
   * Migrates the legacy settings and blocks (idempotent).
   *
   * @param bool $dryRun
   *   Report only, change nothing.
   *
   * @return array{settings: array<string, mixed>, created: list<string>, disabled: list<string>, skipped: list<string>}
   *   The settings copied, the connector blocks created, the legacy blocks
   *   disabled and the legacy blocks already migrated.
   */
  public function migrate(bool $dryRun = FALSE): array {
    $record = $this->record();
    $report = ['settings' => [], 'created' => [], 'disabled' => [], 'skipped' => []];

    // Settings: the results selector and the open state.
    $legacy = $this->configFactory->get(self::LEGACY_CONFIG);
    $changes = [];
    if (!$legacy->isNew()) {
      $selector = trim((string) $legacy->get('result_target_selector'));
      if ($selector !== '' && WidgetBlock::isSafeSelector($selector)) {
        $changes['widget.result_target'] = $selector;
      }
      if ($legacy->get('desktop_always_open') !== NULL) {
        $changes['widget.open'] = (bool) $legacy->get('desktop_always_open');
      }
      $position = $legacy->get('position');
      if (in_array($position, ['left', 'right'], TRUE)) {
        $changes['widget.position'] = $position;
      }
    }
    $blocks = $this->legacyBlocks();
    if ($blocks !== []) {
      $changes['widget.placement'] = 'block';
    }

    $config = $this->configFactory->getEditable(self::CONFIG);
    foreach ($changes as $key => $value) {
      if ($config->get($key) === $value) {
        unset($changes[$key]);
        continue;
      }
      if (!array_key_exists($key, $record['settings'])) {
        $record['settings'][$key] = $config->get($key);
      }
    }
    $report['settings'] = $changes;

    // Blocks.
    $storage = $this->entityTypeManager->hasDefinition('block') ? $this->entityTypeManager->getStorage('block') : NULL;
    foreach ($blocks as $legacyId => $block) {
      $newId = $record['blocks'][$legacyId] ?? $this->newBlockId($legacyId);
      $exists = $storage !== NULL && $storage->load($newId) !== NULL;
      if ($exists) {
        $report['skipped'][] = $legacyId;
      }
      elseif ($storage !== NULL) {
        $report['created'][] = $newId;
        if (!$dryRun) {
          $settings = (array) $block->get('settings');
          $storage->create([
            'id' => $newId,
            'theme' => $block->get('theme'),
            'region' => $block->get('region'),
            'weight' => (int) $block->get('weight'),
            'plugin' => self::BLOCK_PLUGIN,
            'status' => $block->status(),
            'visibility' => (array) $block->get('visibility'),
            'settings' => [
              'id' => self::BLOCK_PLUGIN,
              'label' => (string) ($settings['label'] ?? 'WebAssistant'),
              'label_display' => (string) ($settings['label_display'] ?? '0'),
              'provider' => 'webassistant_connector',
              'assistant_id' => '',
              'result_target' => '',
              'render_result_container' => FALSE,
              'position' => '',
              'open' => FALSE,
            ],
          ])->save();
        }
      }
      if (!isset($record['blocks'][$legacyId])) {
        $record['blocks'][$legacyId] = $newId;
        $record['legacy_status'][$legacyId] = $block->status();
      }
      if ($block->status()) {
        $report['disabled'][] = $legacyId;
        if (!$dryRun) {
          $block->disable()->save();
        }
      }
    }

    if (!$dryRun) {
      foreach ($changes as $key => $value) {
        $config->set($key, $value);
      }
      if ($changes !== []) {
        $config->save();
      }
      if ($record['blocks'] !== [] || $record['settings'] !== []) {
        $this->state->set(self::STATE_KEY, $record);
      }
      $this->logger->notice('Legacy widget migrated: @created blocks created, @disabled legacy blocks disabled.', [
        '@created' => count($report['created']),
        '@disabled' => count($report['disabled']),
      ]);
    }
    return $report;
  }

  /**
   * Reverts migrate(): re-enables legacy blocks, removes the created ones.
   *
   * @return array{settings: array<string, mixed>, removed: list<string>, enabled: list<string>}
   *   The settings restored, the connector blocks removed and the legacy
   *   blocks enabled again.
   */
  public function rollback(): array {
    $record = $this->record();
    $report = ['settings' => [], 'removed' => [], 'enabled' => []];
    $storage = $this->entityTypeManager->hasDefinition('block') ? $this->entityTypeManager->getStorage('block') : NULL;
    if ($storage !== NULL) {
      foreach ($record['blocks'] as $legacyId => $newId) {
        $created = $storage->load($newId);
        if ($created instanceof ConfigEntityInterface && $created->get('plugin') === self::BLOCK_PLUGIN) {
          $created->delete();
          $report['removed'][] = $newId;
        }
        $legacy = $storage->load($legacyId);
        if ($legacy instanceof ConfigEntityInterface && ($record['legacy_status'][$legacyId] ?? TRUE) && !$legacy->status()) {
          $legacy->enable()->save();
          $report['enabled'][] = $legacyId;
        }
      }
    }
    if ($record['settings'] !== []) {
      $config = $this->configFactory->getEditable(self::CONFIG);
      foreach ($record['settings'] as $key => $value) {
        $config->set($key, $value);
      }
      $config->save();
      $report['settings'] = $record['settings'];
    }
    $this->state->delete(self::STATE_KEY);
    $this->logger->notice('Legacy widget migration rolled back: @removed blocks removed, @enabled legacy blocks enabled.', [
      '@removed' => count($report['removed']),
      '@enabled' => count($report['enabled']),
    ]);
    return $report;
  }

  /**
   * Returns the blocks of the legacy plugin.
   *
   * @return array<string, \Drupal\Core\Config\Entity\ConfigEntityInterface>
   *   The blocks keyed by ID.
   */
  private function legacyBlocks(): array {
    if (!$this->entityTypeManager->hasDefinition('block')) {
      return [];
    }
    $blocks = [];
    foreach ($this->entityTypeManager->getStorage('block')->loadByProperties(['plugin' => self::LEGACY_PLUGIN]) as $id => $block) {
      $blocks[(string) $id] = $block;
    }
    ksort($blocks);
    return $blocks;
  }

  /**
   * Builds the ID of the connector block that replaces a legacy block.
   *
   * @param string $legacyId
   *   The legacy block ID.
   *
   * @return string
   *   A machine name of at most 64 characters.
   */
  private function newBlockId(string $legacyId): string {
    $suffix = '_connector';
    $base = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($legacyId));
    return substr($base, 0, 64 - strlen($suffix)) . $suffix;
  }

  /**
   * Returns the migration record.
   *
   * @return array{blocks: array<string, string>, legacy_status: array<string, bool>, settings: array<string, mixed>}
   *   Migrated blocks, their previous status and the previous settings.
   */
  private function record(): array {
    $record = $this->state->get(self::STATE_KEY, []);
    $record = is_array($record) ? $record : [];
    return [
      'blocks' => is_array($record['blocks'] ?? NULL) ? $record['blocks'] : [],
      'legacy_status' => is_array($record['legacy_status'] ?? NULL) ? $record['legacy_status'] : [],
      'settings' => is_array($record['settings'] ?? NULL) ? $record['settings'] : [],
    ];
  }

}
