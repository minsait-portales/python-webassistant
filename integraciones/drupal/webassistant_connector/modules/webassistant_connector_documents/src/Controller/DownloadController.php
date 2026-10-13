<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents\Controller;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\file\FileInterface;
use Drupal\webassistant_connector\Sync\DocumentMap;
use Drupal\webassistant_connector_documents\Download\SignedUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves indexed originals to the platform (design section 11).
 *
 * Route webassistant_connector_documents.download (anonymous: the signature
 * is the authorization). Checks, in order: expiry (403), the file exists
 * (404), the signature over uuid|expires|changed with hash_equals (403), the
 * file is permanent, still mapped as an indexed attachment and present on
 * disk (404). Then streams it from any stream wrapper (private:// included)
 * with no-store, noindex, nosniff and attachment headers. It never redirects,
 * never reads or starts a session and never renders a themed error page.
 */
final class DownloadController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Remote statuses of map rows whose file the platform will not download.
   */
  private const NOT_INDEXED_STATUSES = ['rejected'];

  /**
   * Headers of every response of this controller.
   */
  private const SECURITY_HEADERS = [
    'Cache-Control' => 'no-store, private',
    'Pragma' => 'no-cache',
    'X-Robots-Tag' => 'noindex, nofollow, noarchive',
    'X-Content-Type-Options' => 'nosniff',
    'Content-Security-Policy' => "default-src 'none'; sandbox",
    'Referrer-Policy' => 'no-referrer',
  ];

  /**
   * Constructs a DownloadController.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository (files by UUID).
   * @param \Drupal\webassistant_connector_documents\Download\SignedUrlGenerator $signedUrlGenerator
   *   The signed URL generator.
   * @param \Drupal\webassistant_connector\Sync\DocumentMap $documentMap
   *   The map of indexed documents.
   * @param \Psr\Log\LoggerInterface $logger
   *   The connector logger.
   */
  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly SignedUrlGenerator $signedUrlGenerator,
    private readonly DocumentMap $documentMap,
    #[Autowire(service: 'logger.channel.webassistant_connector')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Streams a signed original.
   *
   * @param string $uuid
   *   The file UUID.
   * @param string $expires
   *   The expiry timestamp.
   * @param string $signature
   *   The base64url signature.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   200 with the file, 403 for an invalid or expired signature, 404 for a
   *   file that is missing, temporary or no longer indexed.
   */
  public function download(string $uuid, string $expires, string $signature): Response {
    if (!ctype_digit($expires) || strlen($expires) > 12 || !preg_match('/^[A-Za-z0-9_-]+$/', $signature)) {
      return self::error(Response::HTTP_FORBIDDEN);
    }
    $expiresAt = (int) $expires;
    if ($this->signedUrlGenerator->isExpired($expiresAt)) {
      return self::error(Response::HTTP_FORBIDDEN);
    }
    if (!Uuid::isValid($uuid)) {
      return self::error(Response::HTTP_NOT_FOUND);
    }
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
    if (!$file instanceof FileInterface) {
      return self::error(Response::HTTP_NOT_FOUND);
    }
    if (!$this->signedUrlGenerator->isValidSignature($uuid, $expiresAt, (int) $file->getChangedTime(), $signature)) {
      $this->logger->notice('Download of attachment @uuid refused: invalid signature (or the file changed since the URL was signed).', ['@uuid' => $uuid]);
      return self::error(Response::HTTP_FORBIDDEN);
    }
    $uri = (string) $file->getFileUri();
    if (!$file->isPermanent() || !$this->isIndexed($uuid) || !is_file($uri)) {
      return self::error(Response::HTTP_NOT_FOUND);
    }

    $response = new BinaryFileResponse($uri, Response::HTTP_OK, self::SECURITY_HEADERS + [
      'Content-Type' => self::contentType((string) $file->getMimeType()),
    ], FALSE, NULL, FALSE, FALSE);
    $filename = self::filename((string) $file->getFilename(), $uuid);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, self::asciiFallback($filename));
    return $response;
  }

  /**
   * Whether a file is still mapped as an attachment the platform indexes.
   *
   * @param string $uuid
   *   The file UUID.
   *
   * @return bool
   *   TRUE if a map row has this attachment and was not rejected.
   */
  private function isIndexed(string $uuid): bool {
    foreach ($this->documentMap->findByAttachment($uuid) as $row) {
      if (!in_array($row['remote_status'] ?? '', self::NOT_INDEXED_STATUSES, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds an error response (never cached, no themed page).
   *
   * @param int $status
   *   The status: 403 or 404.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A short plain-text response that tells nothing about the cause.
   */
  private static function error(int $status): Response {
    $text = $status === Response::HTTP_FORBIDDEN ? 'Access denied.' : 'Not found.';
    return new Response($text, $status, self::SECURITY_HEADERS + ['Content-Type' => 'text/plain; charset=utf-8']);
  }

  /**
   * Returns a safe Content-Type for the stored MIME type.
   *
   * @param string $mimeType
   *   The MIME type of the file entity.
   *
   * @return string
   *   The MIME type, or application/octet-stream if it is malformed.
   */
  private static function contentType(string $mimeType): string {
    $mimeType = strtolower(trim($mimeType));
    return preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}$#', $mimeType) ? $mimeType : 'application/octet-stream';
  }

  /**
   * Returns a file name usable in Content-Disposition.
   *
   * @param string $filename
   *   The stored file name.
   * @param string $uuid
   *   The file UUID (fallback).
   *
   * @return string
   *   The name without path separators nor control characters.
   */
  private static function filename(string $filename, string $uuid): string {
    // Invalid UTF-8 makes preg_replace() return NULL: the UUID is used.
    $filename = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $filename));
    if ($filename === '') {
      return $uuid;
    }
    return mb_substr($filename, 0, 200);
  }

  /**
   * Returns the ASCII fallback of a file name (RFC 6266).
   *
   * @param string $filename
   *   The file name.
   *
   * @return string
   *   Printable ASCII without "%" nor quotes.
   */
  private static function asciiFallback(string $filename): string {
    $fallback = (string) preg_replace('/[^\x20-\x7E]|[%"\/\\\\]/u', '_', $filename);
    return $fallback !== '' ? $fallback : 'document';
  }

}
