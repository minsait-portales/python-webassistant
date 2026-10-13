<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector_documents\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Entity\SourceInterface;
use Drupal\webassistant_connector\Form\SourceForm;
use Drupal\webassistant_connector\Sync\Outbox;
use Drupal\webassistant_connector\Sync\OutboxItem;
use Drupal\webassistant_connector_documents\AttachmentFieldInspector;
use Drupal\webassistant_connector_documents\DocumentsSettings;
use Drupal\webassistant_connector_documents\Hook\SourceFormHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the attachment fields offered by the source form and the uninstall.
 *
 * @group webassistant_connector
 */
#[CoversClass(SourceFormHooks::class)]
#[CoversClass(AttachmentFieldInspector::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class SourceSettingsTest extends DocumentsKernelTestBase {

  /**
   * Builds a form of a source.
   *
   * @param \Drupal\webassistant_connector\Entity\SourceInterface $source
   *   The source.
   * @param string $operation
   *   The form operation.
   * @param array<string, mixed> $values
   *   Form state values (as after an AJAX request).
   *
   * @return array{0: array<mixed>, 1: \Drupal\Core\Form\FormStateInterface, 2: \Drupal\Core\Entity\EntityFormInterface}
   *   The form, its state and the form object.
   */
  private function buildForm(SourceInterface $source, string $operation, array $values = []): array {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('webassistant_source', $operation);
    $formObject->setEntity($source);
    $formState = new FormState();
    $formState->setValues($values);
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    return [$form, $formState, $formObject];
  }

  /**
   * Only file fields and file media references are offered.
   */
  public function testEligibleFields(): void {
    $this->createEntityReferenceField('node', 'article', 'field_author', 'Author', 'user');
    $inspector = $this->container->get('webassistant_connector_documents.field_inspector');
    $fields = $inspector->eligibleFields('node');
    $this->assertSame(['field_document', 'field_media'], array_keys($fields));
    $this->assertSame(AttachmentFieldInspector::KIND_FILE, $fields['field_document']['kind']);
    $this->assertSame(AttachmentFieldInspector::KIND_MEDIA, $fields['field_media']['kind']);
    $this->assertSame(['article'], $fields['field_media']['bundles']);
    $this->assertSame(['document' => 'field_media_file'], $inspector->fileMediaTypes());
    // Sources of media items can index their own source field.
    $this->assertArrayHasKey('field_media_file', $inspector->eligibleFields('media'));
    $this->assertSame([], $inspector->eligibleFields('webassistant_source'));
  }

  /**
   * The add form offers the fields of the selected entity type.
   */
  public function testFormAlter(): void {
    [$form, $formState, $formObject] = $this->buildForm(Source::create([]), 'add');
    $this->assertInstanceOf(SourceForm::class, $formObject);
    $element = $form[SourceFormHooks::ELEMENT];
    $this->assertSame(['field_document', 'field_media'], array_keys($element['fields']['#options']));
    $this->assertFalse($element['enabled']['#default_value']);
    $this->assertContains([SourceFormHooks::class, 'buildEntity'], $form['#entity_builders']);
    $this->assertSame([SourceFormHooks::class, 'ajaxRefresh'], $form['scope']['entity_type']['#ajax']['callback']);

    // Switching to media items refreshes the field list too.
    [$form, $formState] = $this->buildForm(Source::create([]), 'add', ['entity_type' => 'media']);
    $this->assertSame(['field_media_file'], array_keys($form[SourceFormHooks::ELEMENT]['fields']['#options']));
    $commands = SourceFormHooks::ajaxRefresh($form, $formState)->getCommands();
    $this->assertCount(3, $commands);
    $this->assertSame('#' . SourceFormHooks::WRAPPER_ID, $commands[2]['selector']);

    // The edit form shows the stored settings; the preview and delete forms
    // are not altered.
    [$form] = $this->buildForm($this->source, 'edit');
    $this->assertTrue($form[SourceFormHooks::ELEMENT]['enabled']['#default_value']);
    $this->assertSame(['field_document', 'field_media'], $form[SourceFormHooks::ELEMENT]['fields']['#default_value']);
    $this->assertSame(self::DOWNLOAD_BASE_URL, $form[SourceFormHooks::ELEMENT]['download_base_url']['#default_value']);
    [$form] = $this->buildForm($this->source, 'preview');
    $this->assertArrayNotHasKey(SourceFormHooks::ELEMENT, $form);
    [$form] = $this->buildForm($this->source, 'delete');
    $this->assertArrayNotHasKey(SourceFormHooks::ELEMENT, $form);
  }

  /**
   * Uninstalling keeps the sources enabled and drops attachment rows.
   */
  public function testUninstall(): void {
    $this->installSchema('user', ['users_data']);
    $this->container->get('webassistant_connector.outbox')->recordKey(OutboxItem::OPERATION_UPSERT, Outbox::SOURCE_ATTACHMENT, 'file', '1', 'und');
    $this->container->get('webassistant_connector.outbox')->recordKey(OutboxItem::OPERATION_UPSERT, 'articles', 'node', '1', 'en');
    $this->assertSame(['webassistant_connector_documents'], $this->source->getThirdPartyProviders());

    $this->container->get('module_installer')->uninstall(['webassistant_connector_documents']);

    $source = $this->container->get('entity_type.manager')->getStorage('webassistant_source')->loadUnchanged('articles');
    $this->assertInstanceOf(SourceInterface::class, $source);
    $this->assertTrue($source->status(), 'The source stays enabled.');
    $this->assertSame([], $source->getThirdPartyProviders());
    $this->assertNotContains(DocumentsSettings::MODULE, $source->getDependencies()['module'] ?? []);
    $sources = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['source'])->execute()->fetchCol();
    $this->assertSame(['articles'], $sources);
  }

}
