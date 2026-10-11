<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Platform;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Resolves the connector secrets and URLs (design section 3.1).
 *
 * Resolution order, from highest to lowest priority:
 * - settings.php: $settings['webassistant_connector'][<name>];
 * - environment variable (WEBASSISTANT_*);
 * - configuration (only for non-secret values).
 *
 * The client secret and the signing key are never read from configuration.
 * The signing key defaults to an HMAC derived from the site hash salt.
 *
 * Service: webassistant_connector.credentials.
 */
final class Credentials {

  /**
   * Key of the connector entry in settings.php.
   */
  public const SETTINGS_KEY = 'webassistant_connector';

  /**
   * The value comes from settings.php.
   */
  public const SOURCE_SETTINGS = 'settings';

  /**
   * The value comes from an environment variable.
   */
  public const SOURCE_ENVIRONMENT = 'environment';

  /**
   * The value comes from webassistant_connector.settings.
   */
  public const SOURCE_CONFIG = 'config';

  /**
   * The value is derived (signing key from hash_salt, public URL from base).
   */
  public const SOURCE_DEFAULT = 'default';

  /**
   * The value is not available.
   */
  public const SOURCE_MISSING = 'missing';

  /**
   * Purpose of the subkey that signs opaque user IDs (amendment C4).
   */
  public const PURPOSE_IDENTITY = 'identity';

  /**
   * Purpose of the subkey that signs download URLs (amendment C4).
   */
  public const PURPOSE_DOWNLOAD = 'download';

  /**
   * Where each value may come from: settings key, env variable, config key.
   */
  private const MAP = [
    'client_id' => ['client_id', 'WEBASSISTANT_CLIENT_ID', 'client_id'],
    'client_secret' => ['client_secret', 'WEBASSISTANT_CLIENT_SECRET', NULL],
    'signing_key' => ['signing_key', 'WEBASSISTANT_SIGNING_KEY', NULL],
    'api_base_url' => ['api_base_url', 'WEBASSISTANT_API_URL', 'api.base_url'],
    'api_public_url' => ['api_public_url', 'WEBASSISTANT_API_PUBLIC_URL', 'api.public_url'],
  ];

  /**
   * Constructs a Credentials object.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly Settings $settings,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the API client ID ('' if missing).
   */
  public function clientId(): string {
    return $this->resolve('client_id')[0];
  }

  /**
   * Returns the API client secret ('' if missing). Never log it.
   */
  public function clientSecret(): string {
    return $this->resolve('client_secret')[0];
  }

  /**
   * Returns the key used to sign user IDs and download URLs.
   *
   * Defaults to hash_hmac('sha256', 'webassistant_connector', hash_salt).
   */
  public function signingKey(): string {
    $value = $this->resolve('signing_key')[0];
    if ($value !== '') {
      return $value;
    }
    return hash_hmac('sha256', 'webassistant_connector', Settings::getHashSalt());
  }

  /**
   * Returns a subkey of the signing key for one purpose (amendment C4).
   *
   * Each use of the signing key gets its own subkey, so that a value signed
   * for one purpose (e.g. a download URL) can never be replayed for another
   * (e.g. an opaque user ID).
   *
   * @param string $purpose
   *   The purpose (see the PURPOSE_* constants).
   *
   * @return string
   *   hash_hmac('sha256', $purpose, signing_key), hexadecimal.
   */
  public function derivedKey(string $purpose): string {
    return hash_hmac('sha256', $purpose, $this->signingKey());
  }

  /**
   * Returns the key that signs opaque user IDs (IdentityMapper).
   */
  public function identityKey(): string {
    return $this->derivedKey(self::PURPOSE_IDENTITY);
  }

  /**
   * Returns the key that signs document download URLs (Documents submodule).
   */
  public function downloadKey(): string {
    return $this->derivedKey(self::PURPOSE_DOWNLOAD);
  }

  /**
   * Returns the server-to-server API URL without trailing slash.
   */
  public function apiBaseUrl(): string {
    return rtrim($this->resolve('api_base_url')[0], '/');
  }

  /**
   * Returns the API URL used by the browser (falls back to the base URL).
   */
  public function apiPublicUrl(): string {
    $value = rtrim($this->resolve('api_public_url')[0], '/');
    return $value !== '' ? $value : $this->apiBaseUrl();
  }

  /**
   * Tells where a value comes from, for the status page (never the value).
   *
   * @param string $name
   *   One of client_id, client_secret, signing_key, api_base_url,
   *   api_public_url.
   *
   * @return string
   *   One of the SOURCE_* constants.
   *
   * @throws \InvalidArgumentException
   *   For an unknown name.
   */
  public function source(string $name): string {
    if (!isset(self::MAP[$name])) {
      throw new \InvalidArgumentException(sprintf('Unknown credential "%s".', $name));
    }
    $source = $this->resolve($name)[1];
    if ($source === self::SOURCE_MISSING) {
      if ($name === 'signing_key') {
        return self::SOURCE_DEFAULT;
      }
      if ($name === 'api_public_url' && $this->apiBaseUrl() !== '') {
        return self::SOURCE_DEFAULT;
      }
    }
    return $source;
  }

  /**
   * Whether the client ID, the client secret and the API URL are available.
   */
  public function isConfigured(): bool {
    return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->apiBaseUrl() !== '';
  }

  /**
   * Resolves a value following the settings > environment > config order.
   *
   * @param string $name
   *   The value name (a key of self::MAP).
   *
   * @return array{0: string, 1: string}
   *   The trimmed value ('' if missing) and its SOURCE_* constant.
   */
  private function resolve(string $name): array {
    [$settingsKey, $envName, $configKey] = self::MAP[$name];

    $settings = $this->settings->get(self::SETTINGS_KEY, []);
    if (is_array($settings) && isset($settings[$settingsKey]) && is_scalar($settings[$settingsKey])) {
      $value = trim((string) $settings[$settingsKey]);
      if ($value !== '') {
        return [$value, self::SOURCE_SETTINGS];
      }
    }

    $env = getenv($envName);
    if (is_string($env) && trim($env) !== '') {
      return [trim($env), self::SOURCE_ENVIRONMENT];
    }

    if ($configKey !== NULL) {
      $value = $this->configFactory->get('webassistant_connector.settings')->get($configKey);
      if (is_scalar($value) && trim((string) $value) !== '') {
        return [trim((string) $value), self::SOURCE_CONFIG];
      }
    }

    return ['', self::SOURCE_MISSING];
  }

}
