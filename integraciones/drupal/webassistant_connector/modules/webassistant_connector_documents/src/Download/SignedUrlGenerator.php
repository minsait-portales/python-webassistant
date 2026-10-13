<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents\Download;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\webassistant_connector\Platform\Credentials;
use Drupal\webassistant_connector\Sync\UrlResolver;
use Drupal\webassistant_connector_documents\DocumentsSettings;

/**
 * Signs the download URLs of indexed originals (design section 11).
 *
 * URL: {base}/webassistant/document/{uuid}/{expires}/{signature} with
 * signature = base64url(hmac_sha256("{uuid}|{expires}|{changed}", key)).
 * The platform removes the query string before downloading, so everything
 * travels in the path. The key is the download subkey of the signing key
 * (Credentials::downloadKey(), amendment C4): it never signs anything else.
 * Including the "changed" timestamp of the file invalidates the URLs of a
 * replaced file.
 *
 * Service: webassistant_connector_documents.signed_url_generator.
 */
final class SignedUrlGenerator {

  /**
   * Path prefix of the download route.
   */
  public const PATH_PREFIX = '/webassistant/document/';

  /**
   * Length of an encoded signature (32 bytes, base64url without padding).
   */
  public const SIGNATURE_LENGTH = 43;

  /**
   * Placeholder of the signature in previews (never a valid signature).
   */
  public const PREVIEW_SIGNATURE = 'preview-placeholder-not-a-signature';

  /**
   * Constructs a SignedUrlGenerator.
   *
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials (download key).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\webassistant_connector\Sync\UrlResolver $urlResolver
   *   The URL resolver (public base URL fallback).
   */
  public function __construct(
    private readonly Credentials $credentials,
    private readonly TimeInterface $time,
    private readonly UrlResolver $urlResolver,
  ) {}

  /**
   * Computes the signature of a download.
   *
   * @param string $uuid
   *   The file UUID.
   * @param int $expires
   *   The expiry timestamp.
   * @param int $changed
   *   The "changed" timestamp of the file.
   *
   * @return string
   *   The base64url signature without padding (SIGNATURE_LENGTH characters).
   */
  public function sign(string $uuid, int $expires, int $changed): string {
    $raw = hash_hmac('sha256', $uuid . '|' . $expires . '|' . $changed, $this->credentials->downloadKey(), TRUE);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

  /**
   * Builds a signed download URL.
   *
   * @param string $uuid
   *   The file UUID.
   * @param int $changed
   *   The "changed" timestamp of the file.
   * @param int $ttl
   *   Lifetime in seconds.
   * @param string $baseUrl
   *   The base URL with which the platform reaches Drupal ('' = the public
   *   base URL of the synchronization settings, else the current host).
   *
   * @return array{url: string, expires: int}
   *   The absolute URL and its expiry timestamp.
   */
  public function generate(string $uuid, int $changed, int $ttl, string $baseUrl = ''): array {
    $expires = $this->time->getCurrentTime() + max(1, $ttl);
    return [
      'url' => $this->buildUrl($uuid, $expires, $this->sign($uuid, $expires, $changed), $baseUrl),
      'expires' => $expires,
    ];
  }

  /**
   * Builds a URL that looks like a download URL but can never be used.
   *
   * Used by previews, which must not issue credentials.
   *
   * @param string $uuid
   *   The file UUID.
   * @param int $ttl
   *   Lifetime in seconds (shown in the expiry segment).
   * @param string $baseUrl
   *   The base URL (see generate()).
   *
   * @return array{url: string, expires: int}
   *   The placeholder URL and the expiry a real URL would have.
   */
  public function placeholder(string $uuid, int $ttl, string $baseUrl = ''): array {
    $expires = $this->time->getCurrentTime() + max(1, $ttl);
    return [
      'url' => $this->buildUrl($uuid, $expires, self::PREVIEW_SIGNATURE, $baseUrl),
      'expires' => $expires,
    ];
  }

  /**
   * Assembles a download URL.
   *
   * @param string $uuid
   *   The file UUID.
   * @param int $expires
   *   The expiry timestamp.
   * @param string $signature
   *   The signature.
   * @param string $baseUrl
   *   The base URL (see generate()).
   *
   * @return string
   *   The absolute URL.
   */
  public function buildUrl(string $uuid, int $expires, string $signature, string $baseUrl = ''): string {
    return $this->resolveBaseUrl($baseUrl) . self::PATH_PREFIX . rawurlencode($uuid) . '/' . $expires . '/' . rawurlencode($signature);
  }

  /**
   * Returns the base URL of download URLs, without trailing slash.
   *
   * @param string $baseUrl
   *   The configured download base URL ('' or invalid: the public base URL
   *   of the synchronization settings, else the current host).
   *
   * @return string
   *   The base URL.
   */
  public function resolveBaseUrl(string $baseUrl = ''): string {
    $baseUrl = trim($baseUrl);
    if (DocumentsSettings::isValidBaseUrl($baseUrl)) {
      return rtrim($baseUrl, '/');
    }
    return rtrim($this->urlResolver->getBaseUrl(), '/');
  }

  /**
   * Whether a signature is valid for a file (constant-time comparison).
   *
   * @param string $uuid
   *   The file UUID.
   * @param int $expires
   *   The expiry timestamp of the URL.
   * @param int $changed
   *   The current "changed" timestamp of the file.
   * @param string $signature
   *   The signature of the URL.
   *
   * @return bool
   *   TRUE if the signature matches. The expiry is not checked here: see
   *   isExpired() and verify().
   */
  public function isValidSignature(string $uuid, int $expires, int $changed, string $signature): bool {
    if (strlen($signature) !== self::SIGNATURE_LENGTH) {
      return FALSE;
    }
    return hash_equals($this->sign($uuid, $expires, $changed), $signature);
  }

  /**
   * Whether an expiry timestamp is in the past.
   *
   * @param int $expires
   *   The expiry timestamp.
   *
   * @return bool
   *   TRUE if expired.
   */
  public function isExpired(int $expires): bool {
    return $expires < $this->time->getCurrentTime();
  }

  /**
   * Whether a download URL is valid now: signature and expiry.
   *
   * @param string $uuid
   *   The file UUID.
   * @param int $expires
   *   The expiry timestamp of the URL.
   * @param int $changed
   *   The current "changed" timestamp of the file.
   * @param string $signature
   *   The signature of the URL.
   *
   * @return bool
   *   TRUE if the signature matches and the URL has not expired.
   */
  public function verify(string $uuid, int $expires, int $changed, string $signature): bool {
    // Both checks always run, so the timing does not tell which one failed.
    $valid = $this->isValidSignature($uuid, $expires, $changed, $signature);
    $expired = $this->isExpired($expires);
    return $valid && !$expired;
  }

}
