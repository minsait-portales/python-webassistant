<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Media types of originals accepted by the platform (reference.media_type).
 *
 * The platform extracts the text according to reference.media_type (PDF with
 * page numbers, DOCX, raw HTML and Markdown, anything else as plain text) and
 * ignores the Content-Type of the download.
 */
final class MediaTypes {

  /**
   * PDF.
   */
  public const PDF = 'application/pdf';

  /**
   * Word (Office Open XML).
   */
  public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

  /**
   * HTML.
   */
  public const HTML = 'text/html';

  /**
   * Plain text.
   */
  public const TEXT = 'text/plain';

  /**
   * Markdown.
   */
  public const MARKDOWN = 'text/markdown';

  /**
   * Every media type accepted by the platform.
   */
  public const ALL = [self::PDF, self::DOCX, self::HTML, self::TEXT, self::MARKDOWN];

  /**
   * Media types allowed by default (the originals of the design: PDF/DOCX).
   */
  public const DEFAULTS = [self::PDF, self::DOCX];

  /**
   * Other MIME types of the same formats.
   */
  private const ALIASES = [
    'application/x-pdf' => self::PDF,
    'application/acrobat' => self::PDF,
    'application/xhtml+xml' => self::HTML,
    'text/x-markdown' => self::MARKDOWN,
  ];

  /**
   * Media types by file extension, for unknown or generic MIME types.
   */
  private const EXTENSIONS = [
    'pdf' => self::PDF,
    'docx' => self::DOCX,
    'html' => self::HTML,
    'htm' => self::HTML,
    'txt' => self::TEXT,
    'md' => self::MARKDOWN,
    'markdown' => self::MARKDOWN,
  ];

  /**
   * MIME types that tell nothing about the format.
   */
  private const GENERIC = ['', 'application/octet-stream', 'binary/octet-stream'];

  /**
   * Returns the platform media type of a file.
   *
   * @param string $mimeType
   *   The MIME type stored in the file entity.
   * @param string $filename
   *   The file name (its extension is used for generic MIME types; Drupal
   *   has no MIME type for Markdown files).
   *
   * @return string|null
   *   One of the ALL constants, or NULL if the platform cannot extract the
   *   file.
   */
  public static function resolve(string $mimeType, string $filename): ?string {
    $mime = strtolower(trim(explode(';', $mimeType)[0]));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $byExtension = self::EXTENSIONS[$extension] ?? NULL;
    if ($byExtension === self::MARKDOWN && ($mime === self::TEXT || in_array($mime, self::GENERIC, TRUE))) {
      return self::MARKDOWN;
    }
    if (in_array($mime, self::ALL, TRUE)) {
      return $mime;
    }
    if (isset(self::ALIASES[$mime])) {
      return self::ALIASES[$mime];
    }
    return in_array($mime, self::GENERIC, TRUE) ? $byExtension : NULL;
  }

  /**
   * Returns the labels of the media types, for forms.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by media type.
   */
  public static function labels(): array {
    return [
      self::PDF => new TranslatableMarkup('PDF'),
      self::DOCX => new TranslatableMarkup('Word (DOCX)'),
      self::HTML => new TranslatableMarkup('HTML'),
      self::TEXT => new TranslatableMarkup('Plain text'),
      self::MARKDOWN => new TranslatableMarkup('Markdown'),
    ];
  }

}
