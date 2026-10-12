<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\SyncPipeline;

use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Form\SyncSettingsForm;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector\Sync\SyncProcessor;
use Drupal\webassistant_connector\Sync\SyncReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the outbox value objects and the pure helpers of the pipeline.
 *
 * @group webassistant_connector
 */
#[CoversClass(OutboxItem::class)]
#[CoversClass(SyncReport::class)]
#[CoversClass(DocumentMap::class)]
#[CoversClass(SyncProcessor::class)]
#[CoversClass(SyncSettingsForm::class)]
#[Group('webassistant_connector')]
final class SyncValueObjectsTest extends UnitTestCase {

  /**
   * Rows round-trip through OutboxItem, including the A1 columns.
   */
  public function testOutboxItemRoundTrip(): void {
    $row = [
      'id' => '12',
      'event_id' => 'c0ffee00-0000-4000-8000-000000000000',
      'operation' => 'delete',
      'source' => 'articles',
      'entity_type' => 'node',
      'entity_id' => '42',
      'entity_uuid' => 'uuid-42',
      'langcode' => 'es',
      'revision_id' => '',
      'status' => 'processing',
      'seq' => '3',
      'claimed_seq' => '2',
      'claim_token' => 'token',
      'lease_until' => '1700000300',
      'attempts' => '1',
      'next_attempt' => '1700000000',
      'last_error' => str_repeat('x', 2000),
      'correlation_id' => NULL,
      'remote' => '{"sent":1}',
      'created' => '1700000000',
      'changed' => '1700000042',
    ];
    $item = OutboxItem::fromRow((object) $row);
    $this->assertSame(12, $item->id);
    $this->assertTrue($item->isDelete());
    $this->assertFalse($item->isUpsert());
    $this->assertNull($item->revisionId);
    $this->assertSame(3, $item->seq);
    $this->assertSame(2, $item->claimedSeq);
    $this->assertSame('token', $item->claimToken);
    $this->assertSame(1700000300, $item->leaseUntil);
    $this->assertSame(['sent' => 1], $item->remote);
    $this->assertSame('articles:node:42:es', $item->getKey());
    $this->assertSame(42, $item->getLatency());
    $this->assertFalse($item->isPseudoSource());

    $back = $item->toRow();
    $this->assertSame(1024, mb_strlen($back['last_error']));
    $this->assertSame('{"sent":1}', $back['remote']);
    $this->assertSame(3, $back['seq']);
    $this->assertSame('token', $back['claim_token']);
    $new = OutboxItem::fromRow(['id' => 0, 'source' => '_orphan', 'remote' => 'not json']);
    $this->assertArrayNotHasKey('id', $new->toRow());
    $this->assertSame([], $new->remote);
    $this->assertNull($new->toRow()['remote']);
    $this->assertTrue($new->isPseudoSource());
  }

  /**
   * Reports accumulate, merge and cap their errors.
   */
  public function testSyncReport(): void {
    $report = (new SyncReport())->addProcessed(2)->addUpserted()->addUnchanged(3)->addReleased();
    $other = (new SyncReport())->addFailed()->addDead()->addDeleted(2)->addSkipped()->suspend(60);
    for ($i = 0; $i < SyncReport::MAX_ERRORS + 5; $i++) {
      $other->addError('error ' . $i, $i, 'corr');
    }
    $report->merge($other);
    $this->assertTrue($report->hasFailures());
    $this->assertTrue($report->isSuspended());
    $this->assertSame(60, $report->getSuspendDelay());
    $this->assertSame([
      'processed' => 2,
      'upserted' => 1,
      'deleted' => 2,
      'failed' => 1,
      'dead' => 1,
      'skipped' => 1,
      'unchanged' => 3,
      'released' => 1,
      'suspended' => TRUE,
    ], array_diff_key($report->toArray(), ['errors' => TRUE]));
    $this->assertCount(SyncReport::MAX_ERRORS, $report->getErrors());
    $this->assertSame(120, (new SyncReport())->suspend(120)->suspend(30)->getSuspendDelay());
    $this->assertNull((new SyncReport())->suspend()->getSuspendDelay());
  }

  /**
   * The payload hash ignores volatile reference members and key order.
   */
  public function testPayloadHashAndUpToDate(): void {
    $document = [
      'source_id' => 'drupal-x:node:1:en:file:abc',
      'knowledge_base' => 'docs',
      'reference' => [
        'download_url' => 'https://www.example.com/webassistant/document/abc/1700000000/sig1',
        'media_type' => 'application/pdf',
        'expires_at' => '2026-01-01T00:00:00+00:00',
      ],
      'metadata' => ['b' => 1, 'a' => 2],
      'acl' => ['public'],
      'version' => 'm1.0123456789abcdef',
    ];
    $minted = $document;
    $minted['reference']['download_url'] = 'https://www.example.com/webassistant/document/abc/1800000000/sig2';
    $minted['reference']['expires_at'] = '2027-01-01T00:00:00+00:00';
    $minted['metadata'] = ['a' => 2, 'b' => 1];
    $hash = DocumentMap::payloadHash($document);
    $this->assertSame($hash, DocumentMap::payloadHash($minted));
    $changed = $document;
    $changed['acl'] = ['authenticated'];
    $this->assertNotSame($hash, DocumentMap::payloadHash($changed));
    $reordered = $document;
    $reordered['acl'] = ['role:a', 'public'];
    $this->assertNotSame(DocumentMap::payloadHash($reordered), DocumentMap::payloadHash(['acl' => ['public', 'role:a']] + $document), 'Lists keep their order.');

    $this->assertTrue(DocumentMap::isUpToDate(['payload_hash' => $hash, 'remote_status' => 'ready'], $hash));
    $this->assertTrue(DocumentMap::isUpToDate(['payload_hash' => $hash, 'remote_status' => 'queued'], $hash));
    foreach (['failed', 'stale', 'sending', 'rejected', ''] as $status) {
      $this->assertFalse(DocumentMap::isUpToDate(['payload_hash' => $hash, 'remote_status' => $status], $hash), $status);
    }
    $this->assertFalse(DocumentMap::isUpToDate(['payload_hash' => 'other', 'remote_status' => 'ready'], $hash));
    $this->assertFalse(DocumentMap::isUpToDate(NULL, $hash));
  }

  /**
   * Backoff and base URL validation.
   */
  public function testHelpers(): void {
    $this->assertSame([30, 60, 120, 240, 480, 960, 1920, 3600, 3600], array_map([SyncProcessor::class, 'backoff'], range(0, 8)));
    $this->assertSame(120, SyncProcessor::backoff(0, 120));
    $this->assertSame(SyncProcessor::MAX_RETRY_AFTER, SyncProcessor::backoff(0, 10 ** 9));
    $this->assertSame(3600, SyncProcessor::backoff(PHP_INT_MAX));

    $this->assertTrue(SyncSettingsForm::isValidBaseUrl('https://www.example.com'));
    $this->assertTrue(SyncSettingsForm::isValidBaseUrl('http://localhost:8080/drupal'));
    $this->assertFalse(SyncSettingsForm::isValidBaseUrl('ftp://example.com'));
    $this->assertFalse(SyncSettingsForm::isValidBaseUrl('https://user:pass@example.com'));
    $this->assertFalse(SyncSettingsForm::isValidBaseUrl('https://example.com/?a=1'));
    $this->assertFalse(SyncSettingsForm::isValidBaseUrl('/relative'));
    $this->assertSame('https://www.example.com', SyncSettingsForm::trimUrlToConfig(' https://www.example.com/ '));
  }

}
