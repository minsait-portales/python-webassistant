<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Sync\UrlResolver;
use Drupal\webassistant_connector_documents\Download\SignedUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the signature of download URLs (design section 11).
 *
 * @group webassistant_connector
 */
#[CoversClass(SignedUrlGenerator::class)]
#[Group('webassistant_connector')]
final class SignedUrlGeneratorTest extends UnitTestCase {

  /**
   * A file UUID.
   */
  private const UUID = '0b0e2a6c-7f62-4a4e-9c52-1d2f0e6b3a91';

  /**
   * The current time of the tests.
   */
  private const NOW = 1790000000;

  /**
   * The "changed" timestamp of the file.
   */
  private const CHANGED = 1780000000;

  /**
   * The signing key of the tests.
   */
  private const SIGNING_KEY = 'test-signing-key';

  /**
   * The current time returned by the time service.
   */
  private int $now = self::NOW;

  /**
   * Builds a generator.
   *
   * @param string $signingKey
   *   The signing key.
   * @param string $publicBaseUrl
   *   The public base URL of the synchronization settings.
   *
   * @return \Drupal\webassistant_connector_documents\Download\SignedUrlGenerator
   *   The generator.
   */
  private function generator(string $signingKey = self::SIGNING_KEY, string $publicBaseUrl = 'https://www.example.com/'): SignedUrlGenerator {
    $configFactory = $this->getConfigFactoryStub([
      'webassistant_connector.settings' => [
        'sync.public_base_url' => $publicBaseUrl,
      ],
    ]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn (): int => $this->now);
    return new SignedUrlGenerator(
      new Credentials(new Settings(['webassistant_connector' => ['signing_key' => $signingKey]]), $configFactory),
      $time,
      new UrlResolver($configFactory, new RequestStack(), $this->createMock(FileUrlGeneratorInterface::class)),
    );
  }

  /**
   * The signature is base64url(HMAC-SHA256) with the download subkey.
   */
  public function testSignature(): void {
    $generator = $this->generator();
    $signature = $generator->sign(self::UUID, self::NOW + 60, self::CHANGED);

    $downloadKey = hash_hmac('sha256', 'download', self::SIGNING_KEY);
    $raw = hash_hmac('sha256', self::UUID . '|' . (self::NOW + 60) . '|' . self::CHANGED, $downloadKey, TRUE);
    $this->assertSame(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), $signature);
    $this->assertSame(SignedUrlGenerator::SIGNATURE_LENGTH, strlen($signature));
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $signature);
    // Deterministic.
    $this->assertSame($signature, $generator->sign(self::UUID, self::NOW + 60, self::CHANGED));
    // Never the plain signing key: a value signed for another purpose (e.g.
    // the opaque user IDs) cannot be replayed.
    $plain = rtrim(strtr(base64_encode(hash_hmac('sha256', self::UUID . '|' . (self::NOW + 60) . '|' . self::CHANGED, self::SIGNING_KEY, TRUE)), '+/', '-_'), '=');
    $this->assertNotSame($plain, $signature);
    // Another key gives another signature (Settings is a singleton: the
    // other generator is built last).
    $this->assertNotSame($signature, $this->generator('another-key')->sign(self::UUID, self::NOW + 60, self::CHANGED));
  }

  /**
   * Generated URLs carry the signature and the expiry in the path.
   */
  public function testGenerate(): void {
    $generator = $this->generator();
    $signed = $generator->generate(self::UUID, self::CHANGED, 3600);
    $this->assertSame(self::NOW + 3600, $signed['expires']);
    $signature = $generator->sign(self::UUID, self::NOW + 3600, self::CHANGED);
    $this->assertSame('https://www.example.com/webassistant/document/' . self::UUID . '/' . (self::NOW + 3600) . '/' . $signature, $signed['url']);
    $this->assertStringNotContainsString('?', $signed['url']);
    $this->assertTrue($generator->verify(self::UUID, $signed['expires'], self::CHANGED, $signature));

    // An internal download base URL (with a base path) wins.
    $internal = $generator->generate(self::UUID, self::CHANGED, 3600, 'http://drupal:8080/portal/');
    $this->assertStringStartsWith('http://drupal:8080/portal/webassistant/document/' . self::UUID . '/', $internal['url']);
  }

  /**
   * Invalid download base URLs fall back to the public base URL.
   *
   * @param string $baseUrl
   *   The configured download base URL.
   */
  #[DataProvider('providerInvalidBaseUrls')]
  public function testInvalidBaseUrl(string $baseUrl): void {
    $this->assertSame('https://www.example.com', $this->generator()->resolveBaseUrl($baseUrl));
  }

  /**
   * Data provider of testInvalidBaseUrl().
   *
   * @return array<string, array{string}>
   *   Base URLs.
   */
  public static function providerInvalidBaseUrls(): array {
    return [
      'empty' => [''],
      'relative' => ['/drupal'],
      'ftp' => ['ftp://drupal.example.com'],
      'query' => ['http://drupal.example.com/?a=1'],
      'fragment' => ['http://drupal.example.com/#top'],
      'credentials' => ['http://user:secret@drupal.example.com'],
    ];
  }

  /**
   * Tampered, foreign or expired URLs are refused.
   */
  public function testVerify(): void {
    $generator = $this->generator();
    $expires = self::NOW + 600;
    $signature = $generator->sign(self::UUID, $expires, self::CHANGED);
    $this->assertTrue($generator->isValidSignature(self::UUID, $expires, self::CHANGED, $signature));
    $this->assertTrue($generator->verify(self::UUID, $expires, self::CHANGED, $signature));

    // One character changed.
    $tampered = substr($signature, 0, -1) . ($signature[42] === 'A' ? 'B' : 'A');
    $this->assertFalse($generator->verify(self::UUID, $expires, self::CHANGED, $tampered));
    // Truncated, padded or empty signatures.
    $this->assertFalse($generator->verify(self::UUID, $expires, self::CHANGED, substr($signature, 0, 42)));
    $this->assertFalse($generator->verify(self::UUID, $expires, self::CHANGED, $signature . '='));
    $this->assertFalse($generator->verify(self::UUID, $expires, self::CHANGED, ''));
    // Another file, another expiry (extending the URL) or a replaced file.
    $this->assertFalse($generator->verify('7f1c1a5e-1111-4222-8333-944455556666', $expires, self::CHANGED, $signature));
    $this->assertFalse($generator->verify(self::UUID, $expires + 86400, self::CHANGED, $signature));
    $this->assertFalse($generator->verify(self::UUID, $expires, self::CHANGED + 1, $signature));
    // Expired: the signature is still authentic but the URL is refused.
    $this->now = $expires + 1;
    $this->assertTrue($generator->isExpired($expires));
    $this->assertTrue($generator->isValidSignature(self::UUID, $expires, self::CHANGED, $signature));
    $this->assertFalse($generator->verify(self::UUID, $expires, self::CHANGED, $signature));
    // The last second is still valid.
    $this->now = $expires;
    $this->assertFalse($generator->isExpired($expires));
    $this->assertTrue($generator->verify(self::UUID, $expires, self::CHANGED, $signature));

    // Signed with another key (Settings is a singleton: built last).
    $this->assertFalse($this->generator('another-key')->verify(self::UUID, $expires, self::CHANGED, $signature));
  }

  /**
   * Preview placeholders look like download URLs but never verify.
   */
  public function testPlaceholder(): void {
    $generator = $this->generator();
    $placeholder = $generator->placeholder(self::UUID, 3600);
    $this->assertSame(self::NOW + 3600, $placeholder['expires']);
    $this->assertSame('https://www.example.com/webassistant/document/' . self::UUID . '/' . (self::NOW + 3600) . '/' . SignedUrlGenerator::PREVIEW_SIGNATURE, $placeholder['url']);
    $this->assertFalse($generator->verify(self::UUID, $placeholder['expires'], self::CHANGED, SignedUrlGenerator::PREVIEW_SIGNATURE));
    // The route only accepts 43-character signatures.
    $this->assertDoesNotMatchRegularExpression('/^[A-Za-z0-9_-]{43}$/', SignedUrlGenerator::PREVIEW_SIGNATURE);
  }

}
