<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Unit\HostAction;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Tests\UnitTestCase;
use Drupal\webassistant_connector\Access\HostActionAccessCheck;
use Drupal\webassistant_connector\HostAction\BundleMatcher;
use Drupal\webassistant_connector\HostAction\HostActionDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Redirect sanitization, origin checks and bundle matching.
 *
 * @group webassistant_connector
 */
#[Group('webassistant_connector')]
final class HostActionSecurityHelpersTest extends UnitTestCase {

  /**
   * Redirects must stay on the current host.
   */
  #[DataProvider('providerRedirects')]
  public function testSafeRedirect(string $redirect, ?string $expected): void {
    $this->assertSame($expected, HostActionDispatcher::safeRedirect($redirect, 'https://example.com'));
  }

  /**
   * Data provider for testSafeRedirect().
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   Cases: redirect, expected value.
   */
  public static function providerRedirects(): array {
    return [
      'root-relative path' => ['/node/add/article', '/node/add/article'],
      'path with query' => ['/user/login?destination=/user', '/user/login?destination=/user'],
      'same origin absolute' => ['https://example.com/node/1/edit?x=1#top', '/node/1/edit?x=1#top'],
      'same origin explicit port' => ['https://EXAMPLE.com:443/a', '/a'],
      'same origin without path' => ['https://example.com', '/'],
      'protocol-relative' => ['//evil.example/x', NULL],
      'backslash trick' => ['/\\evil.example', NULL],
      'other host' => ['https://evil.example/node/1', NULL],
      'subdomain' => ['https://www.example.com/', NULL],
      'other scheme' => ['http://example.com/node/1', NULL],
      'other port' => ['https://example.com:8443/node/1', NULL],
      'javascript' => ['javascript:alert(1)', NULL],
      'relative path' => ['node/1', NULL],
      'credentials' => ['https://user:pass@example.com/', NULL],
      'control characters' => ["/node/1\r\nSet-Cookie: x", NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * The Origin (or Referer) must be the request origin.
   *
   * @param array<string, string> $headers
   *   Request headers.
   * @param bool $expected
   *   Whether the request is same-origin.
   */
  #[DataProvider('providerOrigins')]
  public function testIsSameOrigin(array $headers, bool $expected): void {
    $request = Request::create('https://example.com/webassistant/host-action/content.create', 'POST');
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    $this->assertSame($expected, HostActionAccessCheck::isSameOrigin($request));
  }

  /**
   * Data provider for testIsSameOrigin().
   *
   * @return array<string, array{0: array<string, string>, 1: bool}>
   *   Cases: headers, expected value.
   */
  public static function providerOrigins(): array {
    return [
      'same origin' => [['Origin' => 'https://example.com'], TRUE],
      'same origin uppercase and default port' => [['Origin' => 'HTTPS://Example.COM:443'], TRUE],
      'referer fallback' => [['Referer' => 'https://example.com/node/1?x=y'], TRUE],
      'origin wins over referer' => [['Origin' => 'https://evil.example', 'Referer' => 'https://example.com/'], FALSE],
      'missing both' => [[], FALSE],
      'null origin' => [['Origin' => 'null'], FALSE],
      'other host' => [['Origin' => 'https://evil.example'], FALSE],
      'suffix host' => [['Origin' => 'https://example.com.evil.example'], FALSE],
      'other scheme' => [['Origin' => 'http://example.com'], FALSE],
      'other port' => [['Origin' => 'https://example.com:8443'], FALSE],
      'other referer' => [['Referer' => 'https://evil.example/https://example.com'], FALSE],
      'non http scheme' => [['Origin' => 'file://example.com'], FALSE],
    ];
  }

  /**
   * Bundles are matched by machine name, label and loose label.
   */
  public function testBundleMatcher(): void {
    $bundles = [
      'article' => 'Artículo',
      'basic_page' => 'Basic page',
      'news' => 'News',
      'news_item' => 'News',
    ];
    $matcher = new BundleMatcher(new PhpTransliteration());
    $this->assertSame(['article'], $matcher->match('article', $bundles));
    $this->assertSame(['article'], $matcher->match('ARTICLE', $bundles));
    $this->assertSame(['article'], $matcher->match(' artículo ', $bundles));
    $this->assertSame(['article'], $matcher->match('ARTICULO', $bundles));
    $this->assertSame(['basic_page'], $matcher->match('basic  page', $bundles));
    $this->assertSame(['basic_page'], $matcher->match('basic-page', $bundles));
    $this->assertSame(['news'], $matcher->match('news', $bundles), 'A machine name wins over labels.');
    $this->assertSame(['news'], $matcher->match('NEWS ', $bundles), 'Machine names are case-insensitive.');
    $this->assertSame([], $matcher->match('event', $bundles));
    $this->assertSame([], $matcher->match('', $bundles));
    $this->assertSame([], $matcher->match('文章', ['a' => '文章x']));

    $ambiguous = ['blog' => 'Post', 'story' => 'Post'];
    $this->assertSame(['blog', 'story'], $matcher->match('post', $ambiguous));

    $withoutTransliteration = new BundleMatcher();
    $this->assertSame([], $withoutTransliteration->match('articulo', $bundles));
    $this->assertSame(['article'], $withoutTransliteration->match('Artículo', $bundles));
  }

}
