<?php

declare(strict_types=1);

namespace Drupal\webassistant_connector\Sync;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\link\LinkItemInterface;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Locale\LocaleMapper;
use Drupal\webassistant_connector\Normalizer\CanonicalDocument;
use Drupal\webassistant_connector\Normalizer\NormalizationContext;
use Drupal\webassistant_connector\Normalizer\NormalizerManager;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Builds the canonical documents of a translation (design section 8.3).
 *
 * Service: webassistant_connector.document_builder.
 */
final class DocumentBuilder {

  /**
   * Values of an exclusion field that do not exclude.
   */
  private const FALSY_STRINGS = ['', '0', 'false', 'no', 'off'];

  /**
   * Constructs a DocumentBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizerManager $normalizerManager
   *   The normalizer plugin manager.
   * @param \Drupal\webassistant_connector\Sync\AclResolver $aclResolver
   *   The ACL resolver.
   * @param \Drupal\webassistant_connector\Sync\SourceIdGenerator $sourceIdGenerator
   *   The source ID generator.
   * @param \Drupal\webassistant_connector\Sync\UrlResolver $urlResolver
   *   The URL resolver.
   * @param \Drupal\webassistant_connector\Locale\LocaleMapper $localeMapper
   *   The locale mapper.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher (documents are rendered as the least privileged
   *   principal of their ACL, amendment B1).
   * @param iterable<\Drupal\webassistant_connector\Normalizer\DocumentContributorInterface> $contributors
   *   Services tagged webassistant_connector.document_contributor.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager when the workspaces module is enabled: documents
   *   are always built from the live site (amendment A13).
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NormalizerManager $normalizerManager,
    private readonly AclResolver $aclResolver,
    private readonly SourceIdGenerator $sourceIdGenerator,
    private readonly UrlResolver $urlResolver,
    private readonly LocaleMapper $localeMapper,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly iterable $contributors = [],
    private readonly ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Builds the documents of an entity translation.
   *
   * Steps (design section 8.3 and amendments A8, B1-B5, E2, E3):
   * 1. the source must be enabled (unless previewing) and target the entity
   *    type and bundle (else not_applicable); load the default revision
   *    (missing: deleted);
   * 2. take the translation (missing: translation_missing; outside the
   *    source langcodes: language_excluded);
   * 3. unpublished (EntityPublishedInterface): unpublished; a truthy
   *    exclusion field: excluded;
   * 4. ACL via AclResolver (empty: no_access);
   * 5. source_id {prefix}:{type}:{id}:{langcode};
   * 6. locale via LocaleMapper;
   * 7. after normalization, content_hash = sha256(text) and version =
   *    m{mapping_version}.{first 16 hex characters of content_hash};
   * 8. canonical_url via UrlResolver (or the "url" field role);
   * 9. metadata.drupal {entity_type, bundle, id, uuid, langcode, source,
   *    mapping: first 12 characters of getMappingHash()};
   * 10. site_id when site scoped; then the normalizer and the contributors,
   *    rendered as the least privileged principal of the ACL.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string $entityTypeId
   *   The entity type ID.
   * @param int|string $entityId
   *   The entity ID.
   * @param string $langcode
   *   The Drupal language code of the translation.
   * @param bool $preview
   *   TRUE for the preview form or Drush preview: disabled sources are
   *   built too and contributors must avoid side effects.
   *
   * @return \Drupal\webassistant_connector\Sync\DocumentBuildResult
   *   The documents (main first) or the skip reason, plus warnings.
   */
  public function build(SourceInterface $source, string $entityTypeId, int|string $entityId, string $langcode, bool $preview = FALSE): DocumentBuildResult {
    if ($this->workspaceManager !== NULL && $this->workspaceManager->hasActiveWorkspace()) {
      return $this->workspaceManager->executeOutsideWorkspace(fn (): DocumentBuildResult => $this->doBuild($source, $entityTypeId, $entityId, $langcode, $preview));
    }
    return $this->doBuild($source, $entityTypeId, $entityId, $langcode, $preview);
  }

  /**
   * Builds the documents (see build()).
   */
  private function doBuild(SourceInterface $source, string $entityTypeId, int|string $entityId, string $langcode, bool $preview): DocumentBuildResult {
    if ((!$preview && !$source->status()) || $source->getTargetEntityTypeId() !== $entityTypeId || !$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_NOT_APPLICABLE);
    }
    // loadUnchanged() returns the default revision without the static cache,
    // so values computed earlier for another user (e.g. processed text) are
    // never reused.
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->loadUnchanged($entityId);
    if ($entity === NULL) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_DELETED);
    }
    if (!$entity instanceof ContentEntityInterface || !$source->appliesTo($entity)) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_NOT_APPLICABLE);
    }
    if (!$entity->hasTranslation($langcode)) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_TRANSLATION_MISSING);
    }
    if (!$source->appliesToLangcode($langcode)) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_LANGUAGE_EXCLUDED);
    }
    $translation = $entity->getTranslation($langcode);
    if ($translation instanceof EntityPublishedInterface && !$translation->isPublished()) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_UNPUBLISHED);
    }
    if ($this->isExcluded($source, $translation)) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_EXCLUDED);
    }

    $acl = $this->aclResolver->resolve($source, $translation);
    if ($acl === []) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_NO_ACCESS);
    }
    $accounts = $this->aclResolver->principalAccounts($acl);

    $normalizer = $this->normalizerManager->getNormalizer($source);
    if (!$normalizer->applies($entityTypeId)) {
      return DocumentBuildResult::skip(DocumentBuildResult::SKIP_NOT_APPLICABLE, [
        sprintf('The normalizer "%s" does not support the entity type "%s".', $source->getNormalizerId(), $entityTypeId),
      ]);
    }

    $warnings = [];
    $canonicalUrl = $this->resolveCanonicalUrl($source, $translation, $accounts, $warnings);
    $siteId = NULL;
    if ($source->isSiteScoped()) {
      $siteId = (string) $this->configFactory->get('webassistant_connector.settings')->get('site_id');
      if ($siteId === '') {
        $siteId = NULL;
        $warnings[] = 'The source is site scoped but site_id is empty: the document is shared with every site.';
      }
    }
    $context = new NormalizationContext(
      acl: $acl,
      locale: $this->localeMapper->map($langcode),
      langcode: $langcode,
      baseSourceId: $this->sourceIdGenerator->generate($entityTypeId, (string) $entity->id(), $langcode),
      version: 'm' . $source->getMappingVersion(),
      canonicalUrl: $canonicalUrl,
      preview: $preview,
      siteId: $siteId,
      drupalMetadata: [
        'entity_type' => $entityTypeId,
        'bundle' => $translation->bundle(),
        'id' => (string) $entity->id(),
        'uuid' => (string) $entity->uuid(),
        'langcode' => $langcode,
        'source' => (string) $source->id(),
        'mapping' => substr($source->getMappingHash(), 0, 12),
      ],
      principalAccounts: $accounts,
    );
    foreach ($warnings as $warning) {
      $context->addWarning($warning);
    }

    $this->accountSwitcher->switchTo($this->aclResolver->renderAccount($acl));
    try {
      $documents = [];
      foreach ($normalizer->normalize($translation, $source, $context) as $document) {
        $documents[] = $this->finalize($document, $context, $documents === []);
      }
      if ($documents === []) {
        return DocumentBuildResult::skip(DocumentBuildResult::SKIP_EMPTY, $context->getWarnings());
      }
      $main = $documents[0];
      foreach ($this->contributors as $contributor) {
        if (!$contributor->applies($source)) {
          continue;
        }
        foreach ($contributor->contribute($translation, $source, $context, $main) as $document) {
          if ($document->acl === [] || ($document->text === NULL) === ($document->reference === NULL)) {
            // Never send such documents: an empty ACL defaults to public on
            // the platform.
            $context->addWarning(sprintf('Document %s dropped: it has an empty ACL or no content.', $document->sourceId));
            continue;
          }
          $documents[] = $document;
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    foreach ($documents as $document) {
      foreach ($document->violations() as $violation) {
        $context->addWarning(sprintf('Document %s is invalid and will be rejected: %s', $document->sourceId, $violation));
      }
    }
    return DocumentBuildResult::fromDocuments($documents, $context->getWarnings());
  }

  /**
   * Applies the common rules to a document produced by the normalizer.
   *
   * @param \Drupal\webassistant_connector\Normalizer\CanonicalDocument $document
   *   The document.
   * @param \Drupal\webassistant_connector\Normalizer\NormalizationContext $context
   *   The context.
   * @param bool $main
   *   TRUE for the main document.
   *
   * @return \Drupal\webassistant_connector\Normalizer\CanonicalDocument
   *   The document with content hash, version, ACL, locale, site and
   *   metadata.drupal set.
   */
  private function finalize(CanonicalDocument $document, NormalizationContext $context, bool $main): CanonicalDocument {
    if ($document->text !== NULL) {
      $hash = hash('sha256', $document->text);
      $document = $document->withContentHash($hash);
      if ($main || $document->version === $context->version || $document->version === '') {
        $document = $document->withVersion($context->version . '.' . substr($hash, 0, 16));
      }
    }
    if ($document->acl === []) {
      $document = $document->withAcl($context->acl);
    }
    if ($document->locale === NULL) {
      $document = $document->withLocale($context->locale);
    }
    if ($document->siteId === NULL && $context->siteId !== NULL) {
      $document = $document->withSiteId($context->siteId);
    }
    if ($main) {
      if ($document->canonicalUrl === NULL && $context->canonicalUrl !== NULL) {
        $document = $document->withCanonicalUrl($context->canonicalUrl);
      }
      $document = $document->withMetadataValue('drupal', $context->drupalMetadata);
    }
    return $document;
  }

  /**
   * Whether a truthy exclusion field excludes the translation.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   *
   * @return bool
   *   TRUE if any exclusion field has a truthy value.
   */
  private function isExcluded(SourceInterface $source, ContentEntityInterface $translation): bool {
    foreach ($source->getFieldsByRole(SourceInterface::ROLE_EXCLUSION) as $mapping) {
      if (!$translation->hasField($mapping['field'])) {
        continue;
      }
      foreach ($translation->get($mapping['field']) as $item) {
        if ($item instanceof EntityReferenceItem) {
          if ((string) $item->get('target_id')->getValue() !== '') {
            return TRUE;
          }
          continue;
        }
        $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
        $value = $property !== NULL ? $item->get($property)->getValue() : NULL;
        if (is_bool($value) ? $value : (is_scalar($value) && !in_array(strtolower(trim((string) $value)), self::FALSY_STRINGS, TRUE))) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Returns the canonical URL: the "url" field role, else the entity link.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The translation.
   * @param list<\Drupal\Core\Session\AccountInterface> $accounts
   *   The principal accounts (the URL field must be viewable by all).
   * @param list<string> $warnings
   *   Warnings, by reference.
   *
   * @return string|null
   *   The absolute http(s) URL, or NULL.
   */
  private function resolveCanonicalUrl(SourceInterface $source, ContentEntityInterface $translation, array $accounts, array &$warnings): ?string {
    foreach ($source->getFieldsByRole(SourceInterface::ROLE_URL) as $mapping) {
      $field = $mapping['field'];
      if (!$translation->hasField($field) || $translation->get($field)->isEmpty()) {
        continue;
      }
      $items = $translation->get($field);
      if (!$this->aclResolver->fieldViewableByAll($items, $accounts)) {
        $warnings[] = sprintf('%s dropped: not viewable by every principal of the ACL.', $field);
        continue;
      }
      $item = $items->first();
      $url = NULL;
      try {
        if ($item instanceof LinkItemInterface) {
          $url = $this->urlResolver->absoluteFromUrl($item->getUrl());
        }
        else {
          $property = $item?->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
          $value = $property !== NULL ? trim((string) $item->get($property)->getValue()) : '';
          if (preg_match('#^https?://#i', $value) || str_starts_with($value, '/')) {
            $url = $this->urlResolver->toAbsolute($value);
          }
        }
      }
      catch (\InvalidArgumentException) {
        $url = NULL;
      }
      if ($url !== NULL && preg_match('#^https?://#i', $url) && UrlHelper::isValid($url, TRUE)) {
        return $url;
      }
      $warnings[] = sprintf('%s ignored: it is not an absolute http(s) URL nor a root-relative path.', $field);
    }
    return $this->urlResolver->canonicalUrl($translation);
  }

}
