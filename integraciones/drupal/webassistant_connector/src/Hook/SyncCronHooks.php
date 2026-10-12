<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webassistant_connector\Sync\AccessEpoch;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\Reconciler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Synchronization housekeeping on cron (amendment A6).
 *
 * Kept apart from the capture hooks so that entity saves never build the
 * services needed here. System cron or "drush queue:run
 * webassistant_connector_dispatch" is recommended over automated_cron.
 */
final class SyncCronHooks {

  /**
   * Constructs a SyncCronHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Sync\Outbox $outbox
   *   The outbox.
   * @param \Drupal\webassistant_connector\Sync\Reconciler $reconciler
   *   The reconciler.
   * @param \Drupal\webassistant_connector\Sync\AccessEpoch $accessEpoch
   *   The access epoch.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Outbox $outbox,
    private readonly Reconciler $reconciler,
    private readonly AccessEpoch $accessEpoch,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Releases expired leases, adds a dispatch ticket when due rows exist,
   * purges done rows older than sync.retention_days, schedules due
   * reconciliations and checks the access epoch. Each step is isolated so a
   * failure does not stop the others.
   */
  #[Hook('cron')]
  public function cron(): void {
    $config = $this->configFactory->get('webassistant_connector.settings');
    $now = $this->time->getCurrentTime();
    $enabled = (bool) $config->get('enabled') && (bool) $config->get('sync.enabled');

    $this->step('release expired leases', fn () => $this->outbox->releaseStale($now));
    if ($enabled) {
      $this->step('dispatch ticket', function (): void {
        if ($this->outbox->hasDue()) {
          $this->outbox->enqueueDispatch();
        }
      });
    }
    $this->step('purge', function () use ($config, $now): void {
      $days = max(0, (int) $config->get('sync.retention_days'));
      $deleted = $this->outbox->purge($now - $days * 86400);
      if ($deleted > 0) {
        $this->logger->info('Purged @count completed outbox events.', ['@count' => $deleted]);
      }
    });
    if ($enabled) {
      $this->step('reconciliation scheduling', fn () => $this->reconciler->enqueueDue());
    }
    $this->step('access epoch', fn () => $this->accessEpoch->check());
  }

  /**
   * Runs a step, logging failures.
   *
   * @param string $name
   *   The step name (for the log).
   * @param callable $step
   *   The step.
   */
  private function step(string $name, callable $step): void {
    try {
      $step();
    }
    catch (\Throwable $e) {
      $this->logger->error('WebAssistant cron step "@step" failed: @message', [
        '@step' => $name,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
