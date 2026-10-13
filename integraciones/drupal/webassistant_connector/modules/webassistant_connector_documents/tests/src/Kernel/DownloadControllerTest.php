<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Kernel;

use Drupal\file\FileInterface;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector_documents\Controller\DownloadController;
use Drupal\webassistant_connector_documents\Download\SignedUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the signed download of indexed originals (design section 11).
 *
 * @group webassistant_connector
 */
#[CoversClass(DownloadController::class)]
#[CoversClass(SignedUrlGenerator::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class DownloadControllerTest extends DocumentsKernelTestBase {

  /**
   * The indexed file.
   */
  private FileInterface $file;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->file = $this->createFile('manual.pdf');
    $this->mapAttachment($this->file, 'sending');
  }

  /**
   * Maps a file as an attachment document.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param string $status
   *   The remote status.
   */
  private function mapAttachment(FileInterface $file, string $status): void {
    $this->container->get('webassistant_connector.document_map')->save([
      'knowledge_base' => 'help',
      'remote_id' => self::PREFIX . ':node:1:en:file:' . $file->uuid(),
      'source' => 'articles',
      'entity_type' => 'node',
      'entity_id' => '1',
      'langcode' => 'en',
      'attachment' => (string) $file->uuid(),
      'version' => 'm1.0123456789abcdef',
      'content_hash' => str_repeat('a', 64),
      'payload_hash' => str_repeat('b', 64),
      'acl' => ['public'],
      'remote_status' => $status,
    ]);
  }

  /**
   * Returns the signed path of a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param int $ttl
   *   Lifetime (negative: already expired).
   *
   * @return string
   *   The path of the download URL.
   */
  private function signedPath(FileInterface $file, int $ttl = 600): string {
    $generator = $this->container->get('webassistant_connector_documents.signed_url_generator');
    $expires = \Drupal::time()->getCurrentTime() + $ttl;
    return SignedUrlGenerator::PATH_PREFIX . $file->uuid() . '/' . $expires . '/' . $generator->sign((string) $file->uuid(), $expires, (int) $file->getChangedTime());
  }

  /**
   * Sends an anonymous GET request through the HTTP kernel.
   *
   * @param string $path
   *   The path.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function request(string $path): Response {
    $request = Request::create($path);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->container->get('http_kernel')->terminate($request, $response);
    return $response;
  }

  /**
   * Returns the body of a response, streaming binary files.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   *
   * @return string
   *   The body.
   */
  private function body(Response $response): string {
    ob_start();
    $response->sendContent();
    return (string) ob_get_clean();
  }

  /**
   * A valid URL streams the file with protective headers.
   */
  public function testValidDownload(): void {
    $response = $this->request($this->signedPath($this->file));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertInstanceOf(BinaryFileResponse::class, $response);
    $this->assertSame(self::PDF_BYTES, $this->body($response));
    $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    $this->assertSame('attachment; filename=manual.pdf', $response->headers->get('Content-Disposition'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    $this->assertFalse($response->isRedirection());
    $this->assertSame([], $response->headers->getCookies(), 'No session cookie is set.');

    // Every indexed status serves the file, including "sending" (mapped
    // before the ingest request, amendment A14).
    $this->mapAttachment($this->file, 'ready');
    $this->assertSame(200, $this->request($this->signedPath($this->file))->getStatusCode());
  }

  /**
   * Non-ASCII file names get an ASCII fallback.
   */
  public function testUnicodeFilename(): void {
    $file = $this->createFile('guía técnica.pdf');
    $this->mapAttachment($file, 'queued');
    $response = $this->request($this->signedPath($file));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame("attachment; filename=\"gu_a t_cnica.pdf\"; filename*=utf-8''gu%C3%ADa%20t%C3%A9cnica.pdf", $response->headers->get('Content-Disposition'));
  }

  /**
   * Tampered, expired or outdated signatures are refused with 403.
   */
  public function testInvalidSignatures(): void {
    $path = $this->signedPath($this->file);
    $signature = substr($path, -43);
    $tampered = substr($path, 0, -1) . ($signature[42] === 'A' ? 'B' : 'A');
    $response = $this->request($tampered);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('Access denied.', $response->getContent());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

    // A longer expiry with the original signature.
    $parts = explode('/', $path);
    $parts[4] = (string) ((int) $parts[4] + 3600);
    $this->assertSame(403, $this->request(implode('/', $parts))->getStatusCode());

    // Expired.
    $this->assertSame(403, $this->request($this->signedPath($this->file, -10))->getStatusCode());

    // The URL of another file.
    $other = $this->createFile('other.pdf');
    $this->mapAttachment($other, 'ready');
    $this->assertSame(403, $this->request(str_replace((string) $this->file->uuid(), (string) $other->uuid(), $path))->getStatusCode());

    // The file was replaced after the URL was signed.
    $this->file->setChangedTime((int) $this->file->getChangedTime() + 5);
    $this->file->save();
    $this->assertSame(403, $this->request($path)->getStatusCode());

    // Malformed signatures do not match the route.
    $this->assertSame(404, $this->request(substr($path, 0, -1))->getStatusCode());
  }

  /**
   * Files that are not indexed any more are not found.
   */
  public function testNotIndexed(): void {
    // Rejected by the platform.
    $this->mapAttachment($this->file, 'rejected');
    $this->assertSame(404, $this->request($this->signedPath($this->file))->getStatusCode());

    // No longer mapped.
    $map = $this->container->get('database');
    $map->delete(DocumentMap::TABLE)->execute();
    $response = $this->request($this->signedPath($this->file));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('Not found.', $response->getContent());

    // Temporary.
    $this->mapAttachment($this->file, 'ready');
    $this->file->setTemporary();
    $this->file->save();
    $this->assertSame(404, $this->request($this->signedPath($this->file))->getStatusCode());

    // Missing from the file system.
    $this->file->setPermanent();
    $this->file->save();
    unlink((string) $this->file->getFileUri());
    $this->assertSame(404, $this->request($this->signedPath($this->file))->getStatusCode());

    // Deleted.
    $path = $this->signedPath($this->file);
    $this->file->delete();
    $this->assertSame(404, $this->request($path)->getStatusCode());

    // Unknown UUID.
    $this->assertSame(404, $this->request(str_replace((string) $this->file->uuid(), '8e9c5a0e-5b7f-4c1e-9d3a-2f6e8a7b9c0d', $path))->getStatusCode());
  }

}
