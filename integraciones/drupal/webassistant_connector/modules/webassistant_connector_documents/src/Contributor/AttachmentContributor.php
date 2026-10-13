<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector_documents\Contributor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Normalizer\DocumentContributorInterface;
use Drupal\webassistant_connector\Normalizer\NormalizationContext;
use Drupal\webassistant_connector\Sync\AclResolver;
use Drupal\webassistant_connector\Sync\SourceIdGenerator;
use Drupal\webassistant_connector_documents\AttachmentFieldInspector;
use Drupal\webassistant_connector_documents\DocumentsSettings;
use Drupal\webassistant_connector_documents\Download\SignedUrlGenerator;
use Drupal\webassistant_connector_documents\MediaTypes;

/**
 * Contributes one reference document per attached file (design section 11).
 *
 * For each configured file field or media reference of the translation, and
 * each permanent file of an allowed media type within the size limit:
 * - source_id {base}:file:{uuid} (SourceIdGenerator::forAttachment());
 * - type "document", title from the media label, the file description (or
 *   the image title/alt) or the file name;
 * - reference {download_url (signed), media_type, expires_at}; previews get
 *   a placeholder URL that can never be used;
 * - content_hash = sha256(uuid|changed|size) and version =
 *   m{mapping_version}.{first 16 hex of content_hash} (amendments A8/E2):
 *   both stable while the file does not change, so it is not downloaded
 *   again;
 * - canonical_url of the parent (citations never use the signed URL);
 * - ACL = parent ACL restricted to the principals that may view the media
 *   item and download the file (amendment B6); empty: not contributed;
 * - metadata {file_name, mime_type, size, parent_title, parent_url, drupal};
 * - attachment = file UUID (local only: the map and the download check).
 *
 * The field itself must be viewable by every principal (amendment B2).
 * Skipped files are reported as warnings shown in the preview.
 */
final class AttachmentContributor implements DocumentContributorInterface {

  /**
   * Document type of attachments.
   */
  public const DOCUMENT_TYPE = 'document';

  /**
   * Constructs an AttachmentContributor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository (translations of media items).
   * @param \Drupal\webassistant_connector\Sync\AclResolver $aclResolver
   *   The ACL resolver (access of the principals).
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator.
   * @param \Drupal\webassistant_connector_documents\Download\SignedUrlGenerator $signedUrlGenerator
   *   The signed URL generator.
   * @param \Drupal\webassistant_connector_documents\AttachmentFieldInspector $fieldInspector
   *   The attachment field inspector.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly AclResolver $aclResolver,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly SignedUrlGenerator $signedUrlGenerator,
    private readonly AttachmentFieldInspector $fieldInspector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(SourceInterface $source): bool {
    return DocumentsSettings::fromSource($source)->isActive();
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context, CanonicalDocument $parent): array {
    $settings = DocumentsSettings::fromSource($source);
    if (!$settings->isActive()) {
      return [];
    }
    $documents = [];
    foreach ($this->collect($translation, $settings, $context) as $uuid => $candidate) {
      $document = $this->buildDocument($candidate['file'], $candidate['media'], $candidate['title'], $source, $settings, $context, $parent);
      if ($document !== NULL) {
        $documents[$uuid] = $document;
      }
    }
    return array_values($documents);
  }

  /**
   * Collects the files of the configured fields (one entry per file).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   * @param \Drupal\webassistant_connector_documents\DocumentsSettings $settings
   *   The settings.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context (principals, warnings).
   *
   * @return array<string, array{file: \Drupal\file\FileInterface, media: \Drupal\media\MediaInterface|null, title: string}>
   *   Candidates keyed by file UUID, in field order.
   */
  private function collect(ContentEntityInterface $translation, DocumentsSettings $settings, NormalizationContext $context): array {
    $candidates = [];
    foreach ($settings->fields as $fieldName) {
      if (!$translation->hasField($fieldName)) {
        continue;
      }
      $items = $translation->get($fieldName);
      if ($items->isEmpty()) {
        continue;
      }
      $kind = $this->fieldInspector->kindOf($items->getFieldDefinition());
      if ($kind === NULL) {
        $context->addWarning(sprintf('Attachments of %s ignored: the field holds neither files nor file media.', $fieldName));
        continue;
      }
      if (!$this->aclResolver->fieldViewableByAll($items, $context->principalAccounts)) {
        $context->addWarning(sprintf('Attachments of %s dropped: the field is not viewable by every principal of the ACL.', $fieldName));
        continue;
      }
      $entries = $kind === AttachmentFieldInspector::KIND_FILE
        ? $this->filesOfFileField($items, $context)
        : $this->filesOfMediaField($items, $translation->language()->getId(), $context);
      foreach ($entries as $entry) {
        $uuid = (string) $entry['file']->uuid();
        if ($uuid !== '' && !isset($candidates[$uuid])) {
          $candidates[$uuid] = $entry;
        }
      }
    }
    return $candidates;
  }

  /**
   * Returns the files of a file or image field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   *
   * @return list<array{file: \Drupal\file\FileInterface, media: null, title: string}>
   *   The files with their title candidate.
   */
  private function filesOfFileField(FieldItemListInterface $items, NormalizationContext $context): array {
    $displayField = (bool) $items->getFieldDefinition()->getSetting('display_field');
    $entries = [];
    foreach ($items as $item) {
      // Files hidden from the display ("Include file in display" off) are
      // not shown to readers, so they are not indexed either.
      if ($displayField && self::propertyValue($item, 'display') !== NULL && !self::propertyValue($item, 'display')) {
        continue;
      }
      $file = $this->loadFile(self::propertyValue($item, 'target_id'));
      if ($file === NULL) {
        $context->addWarning(sprintf('An attachment of %s was skipped: the file does not exist.', $items->getName()));
        continue;
      }
      $title = '';
      foreach (['description', 'title', 'alt'] as $property) {
        $value = self::propertyValue($item, $property);
        if (is_string($value) && trim($value) !== '') {
          $title = trim($value);
          break;
        }
      }
      $entries[] = ['file' => $file, 'media' => NULL, 'title' => $title];
    }
    return $entries;
  }

  /**
   * Returns the files of a media reference field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param string $langcode
   *   The language of the translation (media labels).
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   *
   * @return list<array{file: \Drupal\file\FileInterface, media: \Drupal\media\MediaInterface, title: string}>
   *   The files with their media item and title candidate.
   */
  private function filesOfMediaField(FieldItemListInterface $items, string $langcode, NormalizationContext $context): array {
    $entries = [];
    $storage = $this->entityTypeManager->getStorage('media');
    foreach ($items as $item) {
      $mediaId = self::propertyValue($item, 'target_id');
      if (!is_scalar($mediaId) || (string) $mediaId === '') {
        continue;
      }
      $media = $storage->loadUnchanged((string) $mediaId);
      if (!$media instanceof MediaInterface) {
        $context->addWarning(sprintf('An attachment of %s was skipped: the media item does not exist.', $items->getName()));
        continue;
      }
      $media = $this->entityRepository->getTranslationFromContext($media, $langcode);
      if (!$media->isPublished()) {
        $context->addWarning(sprintf('Media item %s of %s was skipped: it is unpublished.', $media->id(), $items->getName()));
        continue;
      }
      $file = $this->fieldInspector->mediaFile($media, TRUE);
      if ($file === NULL) {
        // Media items without a file source (e.g. remote videos).
        continue;
      }
      $entries[] = ['file' => $file, 'media' => $media, 'title' => trim((string) $media->label())];
    }
    return $entries;
  }

  /**
   * Builds the reference document of a file, or NULL if it is skipped.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param \Drupal\media\MediaInterface|null $media
   *   The media item that holds the file, if any.
   * @param string $title
   *   Title candidate ('' = the file name).
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\webassistant_connector_documents\DocumentsSettings $settings
   *   The settings.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param \Drupal\webassistant_connector\Normalizer\CanonicalDocument $parent
   *   The main document.
   *
   * @return \Drupal\webassistant_connector\Normalizer\CanonicalDocument|null
   *   The document.
   */
  private function buildDocument(FileInterface $file, ?MediaInterface $media, string $title, SourceInterface $source, DocumentsSettings $settings, NormalizationContext $context, CanonicalDocument $parent): ?CanonicalDocument {
    $uuid = (string) $file->uuid();
    $filename = (string) $file->getFilename();
    $label = sprintf('Attachment %s', $uuid);
    if (!$file->isPermanent()) {
      $context->addWarning(sprintf('%s skipped: the file is temporary.', $label));
      return NULL;
    }
    $mediaType = MediaTypes::resolve((string) $file->getMimeType(), $filename);
    if ($mediaType === NULL || !in_array($mediaType, $settings->mimeTypes, TRUE)) {
      $context->addWarning(sprintf('%s skipped: its media type is not allowed.', $label));
      return NULL;
    }
    $size = (int) $file->getSize();
    if ($size <= 0 || $size > $settings->maxBytes) {
      $context->addWarning(sprintf('%s skipped: its size (%d bytes) is empty or larger than %d bytes.', $label, $size, $settings->maxBytes));
      return NULL;
    }
    if (!is_file((string) $file->getFileUri())) {
      $context->addWarning(sprintf('%s skipped: the file is missing from the file system.', $label));
      return NULL;
    }
    $acl = $this->restrictAcl($context->acl, $file, $media, (string) ($context->drupalMetadata['entity_type'] ?? ''));
    if ($acl === []) {
      $context->addWarning(sprintf('%s skipped: no principal of the ACL may view the media item and download the file.', $label));
      return NULL;
    }
    if ($acl !== $context->acl) {
      $context->addWarning(sprintf('%s is restricted to %s: the other principals may not view the media item or download the file.', $label, implode(', ', $acl)));
    }

    $changed = (int) $file->getChangedTime();
    $hash = hash('sha256', $uuid . '|' . $changed . '|' . $size);
    $baseUrl = $settings->downloadBaseUrl;
    $signed = $context->preview
      ? $this->signedUrlGenerator->placeholder($uuid, $settings->urlTtl, $baseUrl)
      : $this->signedUrlGenerator->generate($uuid, $changed, $settings->urlTtl, $baseUrl);
    $canonicalUrl = $parent->canonicalUrl ?? $context->canonicalUrl;
    $title = $title !== '' ? $title : ($filename !== '' ? $filename : $uuid);

    $metadata = [
      'file_name' => $filename,
      'mime_type' => (string) $file->getMimeType(),
      'size' => $size,
      'parent_title' => $parent->title,
      'parent_url' => $canonicalUrl,
      'drupal' => $context->drupalMetadata + ['attachment' => $uuid],
    ];
    if ($media !== NULL) {
      $metadata['drupal']['media'] = (string) $media->id();
    }

    return new CanonicalDocument(
      sourceId: $this->sourceIdGenerator->forAttachment($context->baseSourceId, $uuid),
      knowledgeBase: $settings->knowledgeBaseFor($source),
      type: self::DOCUMENT_TYPE,
      title: $title,
      version: $context->version . '.' . substr($hash, 0, 16),
      locale: $context->locale,
      reference: [
        'download_url' => $signed['url'],
        'media_type' => $mediaType,
        'expires_at' => CanonicalDocument::formatDate($signed['expires']),
      ],
      canonicalUrl: $canonicalUrl,
      acl: $acl,
      siteId: $context->siteId,
      metadata: array_filter($metadata, static fn (mixed $value): bool => $value !== NULL),
      contentHash: $hash,
      createdAt: CanonicalDocument::formatDate((int) $file->getCreatedTime()),
      updatedAt: CanonicalDocument::formatDate($changed),
      attachment: $uuid,
    );
  }

  /**
   * Restricts the parent ACL to the principals that may get the file (B6).
   *
   * @param list<string> $acl
   *   The parent ACL.
   * @param \Drupal\file\FileInterface $file
   *   The file ("download" access).
   * @param \Drupal\media\MediaInterface|null $media
   *   The media item ("view" access), if any.
   * @param string $parentEntityTypeId
   *   The entity type of the parent.
   *
   * @return list<string>
   *   The principals kept, in the parent order (fails closed).
   */
  private function restrictAcl(array $acl, FileInterface $file, ?MediaInterface $media, string $parentEntityTypeId): array {
    // The download access of a private file checks the view access of the
    // entities that reference it. Access results are cached by user ID, which
    // every synthetic authenticated principal shares: reset the caches of the
    // usual referencing entity types before each principal.
    $referencingTypes = array_filter(
      array_unique([$parentEntityTypeId, 'media']),
      fn (string $type): bool => $type !== '' && $this->entityTypeManager->hasDefinition($type),
    );
    $kept = [];
    foreach ($acl as $principal) {
      foreach ($referencingTypes as $type) {
        $this->entityTypeManager->getAccessControlHandler($type)->resetCache();
      }
      $accounts = $this->aclResolver->principalAccounts([$principal]);
      if ($media !== NULL && !$this->aclResolver->canAccessAll($media, $accounts)) {
        continue;
      }
      if (!$this->aclResolver->canAccessAll($file, $accounts, 'download')) {
        continue;
      }
      $kept[] = $principal;
    }
    return $kept;
  }

  /**
   * Loads a file without the static cache (its size or date may change).
   *
   * @param mixed $fileId
   *   The file ID.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file, or NULL.
   */
  private function loadFile(mixed $fileId): ?FileInterface {
    if (!is_scalar($fileId) || (string) $fileId === '') {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->loadUnchanged((string) $fileId);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Returns the value of a property of a field item (NULL if missing).
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The item.
   * @param string $property
   *   The property name.
   *
   * @return mixed
   *   The value.
   */
  private static function propertyValue(FieldItemInterface $item, string $property): mixed {
    $definitions = $item->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
    if (!isset($definitions[$property])) {
      return NULL;
    }
    return $item->get($property)->getValue();
  }

}
