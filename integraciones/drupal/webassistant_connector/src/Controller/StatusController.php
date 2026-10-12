<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\webassistant_connector\Form\StatusActionsForm;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Platform\PlatformClientInterface;
use Drupal\webassistant_connector\Platform\PlatformException;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\Reconciler;
use Drupal\webassistant_connector\Sync\SourceIdGenerator;
use Drupal\webassistant_connector\Sync\SyncStatus;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Diagnostics and outbox page (route webassistant_connector.status, sec. 10).
 *
 * Shows connectivity (health), credentials (source only, never values),
 * server token scopes, site/origin (bootstrap), queue counters, knowledge
 * bases and the StatusActionsForm buttons with the recent errors. The page
 * is never cached.
 */
final class StatusController extends ControllerBase {

  /**
   * Server token scopes the synchronization needs.
   */
  public const REQUIRED_SCOPES = ['ingest:read', 'ingest:write', 'token:issue'];

  /**
   * Constructs a StatusController.
   *
   * @param \Drupal\webassistant_connector\Sync\SyncStatus $syncStatus
   *   The synchronization status.
   * @param \Drupal\webassistant_connector\Platform\PlatformClientInterface $platformClient
   *   The platform client.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials.
   * @param \Drupal\webassistant_connector\Sync\Reconciler $reconciler
   *   The reconciler (knowledge bases and last runs).
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The document map.
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator (prefix).
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack (origin of this site).
   */
  public function __construct(
    private readonly SyncStatus $syncStatus,
    private readonly PlatformClientInterface $platformClient,
    private readonly Credentials $credentials,
    private readonly Reconciler $reconciler,
    private readonly DocumentMap $documentMap,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds the status page.
   *
   * @return array<string, mixed>
   *   A render array (max-age 0).
   */
  public function page(): array {
    $config = $this->config('webassistant_connector.settings');
    $build = [
      '#cache' => ['max-age' => 0],
    ];

    $build['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Check'), $this->t('Status'), $this->t('Details')],
        '#rows' => $this->connectionRows(),
        '#attributes' => ['class' => ['webassistant-connector-status-connection']],
      ],
    ];

    $summary = $this->syncStatus->summary();
    $counts = $summary['counts'];
    $statusLabels = [
      OutboxItem::STATUS_PENDING => $this->t('Pending'),
      OutboxItem::STATUS_PROCESSING => $this->t('Processing'),
      OutboxItem::STATUS_DONE => $this->t('Done'),
      OutboxItem::STATUS_FAILED => $this->t('Failed (will be retried)'),
      OutboxItem::STATUS_DEAD => $this->t('Dead letter'),
    ];
    $countRows = [];
    foreach ($statusLabels as $status => $label) {
      $countRows[] = [
        'data' => [$label, (string) ($counts[$status] ?? 0)],
        'data-outbox-status' => $status,
      ];
    }
    $build['outbox'] = [
      '#type' => 'details',
      '#title' => $this->t('Synchronization'),
      '#open' => TRUE,
      'state' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Connector: @state', ['@state' => $config->get('enabled') ? $this->t('enabled') : $this->t('disabled')]),
          $this->t('Synchronization: @state', ['@state' => $config->get('sync.enabled') ? $this->t('enabled') : $this->t('disabled (changes are recorded but not sent)')]),
          $this->t('Source ID prefix: @prefix', ['@prefix' => $this->sourceIdGenerator->getPrefix()]),
          $this->t('Last successful delivery: @time', ['@time' => $summary['last_success'] === NULL ? $this->t('never') : $this->dateFormatter->format($summary['last_success'], 'short')]),
          $this->t('Average latency (24 h): @latency', ['@latency' => $summary['average_latency'] === NULL ? $this->t('n/a') : $this->t('@seconds s', ['@seconds' => $summary['average_latency']])]),
          $this->t('Events waiting for more than an hour: @count', ['@count' => $summary['backlog']]),
          $this->t('Events due now: @count', ['@count' => $summary['due']]),
          $this->t('Documents mapped: @count', ['@count' => $summary['documents']]),
        ],
      ],
      'counts' => [
        '#type' => 'table',
        '#caption' => $this->t('Outbox events per status'),
        '#header' => [$this->t('Status'), $this->t('Events')],
        '#rows' => $countRows,
        '#attributes' => ['class' => ['webassistant-connector-status-counts']],
      ],
    ];

    $lastRuns = $this->reconciler->lastRuns();
    $kbRows = [];
    foreach ($this->reconciler->knowledgeBases() as $knowledgeBase) {
      $kbRows[] = [
        $knowledgeBase,
        (string) $this->documentMap->count($knowledgeBase),
        isset($lastRuns[$knowledgeBase]) ? $this->dateFormatter->format($lastRuns[$knowledgeBase], 'short') : $this->t('never'),
      ];
    }
    $build['knowledge_bases'] = [
      '#type' => 'details',
      '#title' => $this->t('Knowledge bases'),
      '#open' => FALSE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Knowledge base'), $this->t('Documents mapped'), $this->t('Last reconciliation')],
        '#rows' => $kbRows,
        '#empty' => $this->t('No knowledge base yet: add and enable a source.'),
      ],
    ];

    $build['actions'] = $this->formBuilder()->getForm(StatusActionsForm::class);
    return $build;
  }

  /**
   * Builds the connection rows: credentials and live platform checks.
   *
   * @return list<array<int, mixed>>
   *   The table rows.
   */
  private function connectionRows(): array {
    $sourceLabels = [
      Credentials::SOURCE_SETTINGS => $this->t('settings.php'),
      Credentials::SOURCE_ENVIRONMENT => $this->t('environment'),
      Credentials::SOURCE_CONFIG => $this->t('configuration'),
      Credentials::SOURCE_DEFAULT => $this->t('default'),
      Credentials::SOURCE_MISSING => $this->t('missing'),
    ];
    $rows = [];
    $names = [
      'client_id' => $this->t('Client ID'),
      'client_secret' => $this->t('Client secret'),
      'signing_key' => $this->t('Signing key'),
      'api_base_url' => $this->t('API URL'),
      'api_public_url' => $this->t('Browser API URL'),
    ];
    foreach ($names as $name => $label) {
      $source = $this->credentials->source($name);
      $details = '';
      if ($name === 'api_base_url') {
        $details = $this->credentials->apiBaseUrl();
      }
      elseif ($name === 'api_public_url') {
        $details = $this->credentials->apiPublicUrl();
      }
      $rows[] = [
        $label,
        $source === Credentials::SOURCE_MISSING ? $this->t('Missing') : $this->t('Configured (@source)', ['@source' => $sourceLabels[$source] ?? $source]),
        $details,
      ];
    }

    if (!$this->credentials->isConfigured()) {
      $rows[] = [
        $this->t('Platform'),
        $this->t('Not checked'),
        $this->t('Configure the client ID, the client secret and the API URL first.'),
      ];
      return $rows;
    }

    try {
      $health = $this->platformClient->health();
      $rows[] = [
        $this->t('Platform health'),
        $this->t('OK'),
        is_string($health['status'] ?? NULL) ? $health['status'] : '',
      ];
    }
    catch (PlatformException $e) {
      $rows[] = [$this->t('Platform health'), $this->t('Error'), self::describe($e)];
      // The other checks would fail the same way.
      if ($e->getStatus() === NULL) {
        return $rows;
      }
    }

    try {
      $token = $this->platformClient->serverToken();
      $missing = array_values(array_diff(self::REQUIRED_SCOPES, $token->scopes));
      $rows[] = [
        $this->t('Server token scopes'),
        $missing === [] ? $this->t('OK') : $this->t('Missing scopes: @scopes', ['@scopes' => implode(', ', $missing)]),
        implode(', ', $token->scopes),
      ];
    }
    catch (PlatformException $e) {
      $rows[] = [$this->t('Server token'), $this->t('Error'), self::describe($e)];
    }

    $request = $this->requestStack->getCurrentRequest();
    $origin = $request !== NULL ? $request->getSchemeAndHttpHost() : '';
    if ($origin !== '') {
      try {
        $bootstrap = $this->platformClient->bootstrap($origin);
        $site = is_array($bootstrap['site'] ?? NULL) ? $bootstrap['site'] : [];
        $assistant = is_array($bootstrap['assistant'] ?? NULL) ? $bootstrap['assistant'] : [];
        $rows[] = [
          $this->t('Site bootstrap for @origin', ['@origin' => $origin]),
          $this->t('OK'),
          $this->t('Site @site, assistant @assistant', [
            '@site' => is_scalar($site['id'] ?? NULL) ? (string) $site['id'] : '-',
            '@assistant' => is_scalar($assistant['id'] ?? NULL) ? (string) $assistant['id'] : '-',
          ]),
        ];
      }
      catch (PlatformException $e) {
        $rows[] = [
          $this->t('Site bootstrap for @origin', ['@origin' => $origin]),
          $this->t('Error'),
          self::describe($e),
        ];
      }
    }
    return $rows;
  }

  /**
   * Describes a platform error for administrators (no secrets).
   *
   * @param \Drupal\webassistant_connector\Platform\PlatformException $e
   *   The error.
   *
   * @return string
   *   The safe message with the correlation ID.
   */
  private static function describe(PlatformException $e): string {
    return $e->getMessage() . ($e->getCorrelationId() !== NULL ? ' (correlation ID ' . $e->getCorrelationId() . ')' : '');
  }

}
