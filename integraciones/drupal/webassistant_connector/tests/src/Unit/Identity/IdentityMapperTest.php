<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\Identity;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Identity\IdentityMapper;
use Drupal\webassistant_connector\Locale\LocaleMapper;
use Drupal\webassistant_connector\Platform\Credentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the opaque user ID and the roles sent to the platform.
 *
 * @group webassistant_connector
 */
#[CoversClass(IdentityMapper::class)]
#[Group('webassistant_connector')]
final class IdentityMapperTest extends UnitTestCase {

  /**
   * Builds a mapper.
   *
   * @param string $siteId
   *   The site ID.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger.
   *
   * @return \Drupal\webassistant_connector\Identity\IdentityMapper
   *   The mapper.
   */
  private function mapper(string $siteId = 'site_demo', ?LoggerInterface $logger = NULL): IdentityMapper {
    $configFactory = $this->getConfigFactoryStub([
      'webassistant_connector.settings' => ['site_id' => $siteId, 'sync' => ['default_locale' => 'es']],
    ]);
    $settings = new Settings(['webassistant_connector' => ['signing_key' => 'test-signing-key']]);
    return new IdentityMapper(
      $configFactory,
      new Credentials($settings, $configFactory),
      new LocaleMapper($configFactory, $this->createMock(LanguageManagerInterface::class)),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds an account.
   *
   * @param int $uid
   *   The user ID.
   * @param list<string> $roles
   *   The roles returned by getRoles(TRUE).
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  private function account(int $uid, array $roles = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

  /**
   * The user ID is an HMAC of site and uid with the identity subkey.
   */
  public function testUserId(): void {
    $identityKey = hash_hmac('sha256', 'identity', 'test-signing-key');
    $expected = 'd' . substr(hash_hmac('sha256', 'site_demo:42', $identityKey), 0, 40);

    $userId = $this->mapper()->userId($this->account(42));

    $this->assertSame($expected, $userId);
    $this->assertSame(41, strlen($userId));
    $this->assertMatchesRegularExpression(IdentityMapper::ID_PATTERN, $userId);
    $this->assertSame($userId, $this->mapper()->userId($this->account(42)));
    $this->assertNotSame($userId, $this->mapper()->userId($this->account(43)));
    $this->assertNotSame($userId, $this->mapper('other_site')->userId($this->account(42)));
  }

  /**
   * Locked roles are removed and unrepresentable roles are skipped.
   */
  public function testRoles(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $roles = $this->mapper('site_demo', $logger)->roles($this->account(5, [
      'editor',
      AccountInterface::AUTHENTICATED_ROLE,
      'content_admin',
      'bad role',
      'editor',
    ]));
    $this->assertSame(['content_admin', 'editor'], $roles);
  }

}
