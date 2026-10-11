<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Identity;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\webassistant_connector\Locale\LocaleMapper;
use Drupal\webassistant_connector\Platform\Credentials;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maps Drupal accounts to platform identities (design section 5).
 *
 * Service: webassistant_connector.identity_mapper.
 */
final class IdentityMapper {

  /**
   * Pattern of identifiers usable in platform ACLs and token claims.
   */
  public const ID_PATTERN = '/^[A-Za-z0-9_.@-]{1,128}$/';

  /**
   * Constructs an IdentityMapper.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\webassistant_connector\Platform\Credentials $credentials
   *   The credentials (signing key).
   * @param \Drupal\webassistant_connector\Locale\LocaleMapper $localeMapper
   *   The locale mapper.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Credentials $credentials,
    private readonly LocaleMapper $localeMapper,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the opaque platform user ID of an account.
   *
   * The key is the identity subkey of the signing key (amendment C4), so
   * rotating the signing key or the hash salt changes every user ID.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return string
   *   'd' . substr(hash_hmac('sha256', site_id . ':' . uid, identity_key),
   *   0, 40): stable per site, no personal data, valid for ACLs.
   */
  public function userId(AccountInterface $account): string {
    $siteId = (string) $this->configFactory->get('webassistant_connector.settings')->get('site_id');
    $data = $siteId . ':' . $account->id();
    return 'd' . substr(hash_hmac('sha256', $data, $this->credentials->identityKey()), 0, 40);
  }

  /**
   * Returns the role IDs of an account without anonymous/authenticated.
   *
   * Role IDs that cannot be represented with the platform pattern
   * [A-Za-z0-9_.@-] are skipped and logged, never rewritten (amendment B4).
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return list<string>
   *   Role IDs, sorted.
   */
  public function roles(AccountInterface $account): array {
    $roles = [];
    foreach ($account->getRoles(TRUE) as $role) {
      $role = (string) $role;
      if (in_array($role, [AccountInterface::ANONYMOUS_ROLE, AccountInterface::AUTHENTICATED_ROLE], TRUE)) {
        continue;
      }
      if (!preg_match(self::ID_PATTERN, $role)) {
        $this->logger->warning('The role @role cannot be sent to the WebAssistant platform: its ID does not match [A-Za-z0-9_.@-].', ['@role' => $role]);
        continue;
      }
      $roles[$role] = TRUE;
    }
    $roles = array_map('strval', array_keys($roles));
    sort($roles);
    return $roles;
  }

  /**
   * Returns the current interface locale (LocaleMapper::currentLocale()).
   */
  public function locale(): string {
    return $this->localeMapper->currentLocale();
  }

}
