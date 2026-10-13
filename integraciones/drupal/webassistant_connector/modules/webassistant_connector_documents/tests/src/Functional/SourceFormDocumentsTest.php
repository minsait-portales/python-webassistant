<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector_documents\DocumentsSettings;
use Drupal\webassistant_connector_documents\Hook\SourceFormHooks;
use Drupal\webassistant_connector_documents\MediaTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the attachment settings of the source form (design section 11).
 *
 * @group webassistant_connector
 */
#[CoversClass(SourceFormHooks::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class SourceFormDocumentsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'file',
    'webassistant_connector',
    'webassistant_connector_documents',
    'webassistant_connector_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    FieldStorageConfig::create([
      'field_name' => 'field_document',
      'entity_type' => 'node',
      'type' => 'file',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_document',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Documents',
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer webassistant connector', 'access content']));
  }

  /**
   * Loads a source from storage.
   *
   * @param string $id
   *   The source ID.
   *
   * @return \Drupal\webassistant_connector\Entity\SourceInterface
   *   The source.
   *
   * @phpstan-impure
   */
  private function loadSource(string $id): SourceInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('webassistant_source');
    $storage->resetCache([$id]);
    $source = $storage->load($id);
    $this->assertInstanceOf(SourceInterface::class, $source);
    return $source;
  }

  /**
   * Enabling, validating and disabling the attachments of a source.
   */
  public function testSettings(): void {
    $this->drupalGet('admin/config/services/webassistant-connector/sources/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Attached documents');
    $this->assertSession()->fieldExists('webassistant_connector_documents[fields][field_document]');
    // Only fields holding files are offered.
    $this->assertSession()->fieldNotExists('webassistant_connector_documents[fields][body]');
    $this->assertSession()->checkboxChecked('webassistant_connector_documents[mime_types][application/pdf]');
    $this->assertSession()->checkboxNotChecked('webassistant_connector_documents[mime_types][text/html]');

    $edit = [
      'label' => 'Manuals',
      'id' => 'manuals',
      'entity_type' => 'node',
      'bundles[article]' => TRUE,
      'knowledge_base' => 'help',
      'document_type' => 'guide',
      'acl_mode' => SourceInterface::ACL_ACCESS,
      'field_roles[title][roles][title]' => TRUE,
      'field_roles[body][roles][main_content]' => TRUE,
      'webassistant_connector_documents[enabled]' => TRUE,
      'webassistant_connector_documents[knowledge_base]' => 'Bad KB',
      'webassistant_connector_documents[download_base_url]' => 'http://drupal.internal/?token=1',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('Choose at least one field with attached documents.');
    $this->assertSession()->pageTextContains('The knowledge base of the attached documents must be empty');
    $this->assertSession()->pageTextContains('The download base URL must be an absolute http or https URL');

    $edit['webassistant_connector_documents[fields][field_document]'] = TRUE;
    $edit['webassistant_connector_documents[knowledge_base]'] = 'manuals';
    $edit['webassistant_connector_documents[download_base_url]'] = 'http://drupal.internal:8080/';
    $edit['webassistant_connector_documents[mime_types][text/markdown]'] = TRUE;
    $edit['webassistant_connector_documents[url_ttl]'] = 86400;
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('Created the source Manuals.');
    $this->assertSame([
      'enabled' => TRUE,
      'fields' => ['field_document'],
      'knowledge_base' => 'manuals',
      'mime_types' => [MediaTypes::PDF, MediaTypes::DOCX, MediaTypes::MARKDOWN],
      'max_bytes' => DocumentsSettings::MAX_BYTES,
      'url_ttl' => 86400,
      'download_base_url' => 'http://drupal.internal:8080',
    ], $this->loadSource('manuals')->getThirdPartySettings(DocumentsSettings::MODULE));

    // Two enabled sources never send the attachments of the same items to
    // the same knowledge base (their remote documents would collide).
    $this->drupalGet('admin/config/services/webassistant-connector/sources/manuals');
    $this->submitForm(['status' => TRUE], 'Save');
    $this->assertTrue($this->loadSource('manuals')->status());
    $faq = [
      'label' => 'FAQ',
      'id' => 'faq',
      'status' => TRUE,
      'entity_type' => 'node',
      'knowledge_base' => 'faq',
      'document_type' => 'faq',
      'acl_mode' => SourceInterface::ACL_ACCESS,
      'field_roles[title][roles][title]' => TRUE,
      'webassistant_connector_documents[enabled]' => TRUE,
      'webassistant_connector_documents[fields][field_document]' => TRUE,
      'webassistant_connector_documents[knowledge_base]' => 'manuals',
    ];
    $this->drupalGet('admin/config/services/webassistant-connector/sources/add');
    $this->submitForm($faq, 'Save');
    $this->assertSession()->pageTextContains('The enabled source Manuals already sends the attached documents of some of these items to the knowledge base manuals.');
    $faq['webassistant_connector_documents[knowledge_base]'] = 'faq-documents';
    $this->submitForm($faq, 'Save');
    $this->assertSession()->pageTextContains('Created the source FAQ.');

    // The preview form has no attachment settings.
    $this->drupalGet('admin/config/services/webassistant-connector/sources/manuals/preview');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldNotExists('webassistant_connector_documents[enabled]');

    // The edit form shows the stored settings; disabling keeps them.
    $this->drupalGet('admin/config/services/webassistant-connector/sources/manuals');
    $this->assertSession()->checkboxChecked('webassistant_connector_documents[enabled]');
    $this->assertSession()->checkboxChecked('webassistant_connector_documents[fields][field_document]');
    $this->assertSession()->fieldValueEquals('webassistant_connector_documents[knowledge_base]', 'manuals');
    $this->submitForm(['webassistant_connector_documents[enabled]' => FALSE], 'Save');
    $settings = $this->loadSource('manuals')->getThirdPartySettings(DocumentsSettings::MODULE);
    $this->assertFalse($settings['enabled']);
    $this->assertSame(['field_document'], $settings['fields']);
  }

  /**
   * Sources saved without attachments get no third-party settings.
   */
  public function testUntouchedSource(): void {
    $this->drupalGet('admin/config/services/webassistant-connector/sources/add');
    $this->submitForm([
      'label' => 'Pages',
      'id' => 'pages',
      'entity_type' => 'node',
      'knowledge_base' => 'help',
      'document_type' => 'page',
      'acl_mode' => SourceInterface::ACL_ACCESS,
      'field_roles[title][roles][title]' => TRUE,
    ], 'Save');
    $this->assertSession()->pageTextContains('Created the source Pages.');
    $source = $this->loadSource('pages');
    $this->assertSame([], $source->getThirdPartySettings(DocumentsSettings::MODULE));
    $this->assertNotContains(DocumentsSettings::MODULE, $source->getDependencies()['module'] ?? []);
  }

}
