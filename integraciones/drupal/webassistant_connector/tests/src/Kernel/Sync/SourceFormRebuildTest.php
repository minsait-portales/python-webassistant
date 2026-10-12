<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Kernel\Sync;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\webassistant_connector\Entity\Source;
use Drupal\webassistant_connector\Form\SourceForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the entity-type dependent parts of the source form (AJAX rebuild).
 *
 * @group webassistant_connector
 */
#[CoversClass(SourceForm::class)]
#[Group('webassistant_connector')]
#[RunTestsInSeparateProcesses]
final class SourceFormRebuildTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'taxonomy',
    'webassistant_connector',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'node', 'user', 'webassistant_connector']);
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
  }

  /**
   * Builds the add form of a new source.
   *
   * @param array<string, mixed> $values
   *   Form state values (as after an AJAX request).
   *
   * @return array{0: array<mixed>, 1: \Drupal\Core\Form\FormStateInterface, 2: \Drupal\webassistant_connector\Form\SourceForm}
   *   The form, its state and the form object.
   */
  private function buildAddForm(array $values = []): array {
    $formObject = $this->container->get('entity_type.manager')->getFormObject('webassistant_source', 'add');
    $this->assertInstanceOf(SourceForm::class, $formObject);
    $formObject->setEntity(Source::create([]));
    $formState = new FormState();
    $formState->setValues($values);
    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);
    return [$form, $formState, $formObject];
  }

  /**
   * The bundles, normalizers and field table follow the entity type.
   */
  public function testEntityTypeSwitch(): void {
    [$form] = $this->buildAddForm();
    $options = $form['scope']['entity_type']['#options'];
    $this->assertArrayHasKey('node', $options);
    $this->assertArrayHasKey('taxonomy_term', $options);
    $this->assertArrayNotHasKey('user', $options, 'User accounts are never offered.');
    $this->assertSame('node', $form['scope']['entity_type']['#default_value']);
    $this->assertSame(['article' => 'Article'], $form['scope']['dependent']['bundles']['#options']);
    $this->assertArrayHasKey('body', $form['mapping']['field_roles']);
    $this->assertArrayNotHasKey('nid', $form['mapping']['field_roles']);
    $this->assertFalse($form['status']['#default_value']);

    [$form, $formState, $formObject] = $this->buildAddForm(['entity_type' => 'taxonomy_term']);
    $this->assertSame('taxonomy_term', $form['scope']['entity_type']['#default_value']);
    $this->assertSame(['tags' => 'Tags', 'topics' => 'Topics'], $form['scope']['dependent']['bundles']['#options']);
    $this->assertArrayHasKey('content_entity', $form['scope']['dependent']['normalizer']['#options']);
    $table = $form['mapping']['field_roles'];
    $this->assertArrayHasKey('name', $table);
    $this->assertArrayHasKey('description', $table);
    $this->assertArrayNotHasKey('body', $table);
    $this->assertArrayHasKey('main_content', $table['description']['roles']['#options']);
    // Parent terms are references: depth and allowed bundles are offered.
    $this->assertTrue($table['parent']['depth']['#access']);

    $commands = $formObject->ajaxRefresh($form, $formState)->getCommands();
    $this->assertCount(2, $commands);
    $this->assertSame('#webassistant-source-scope', $commands[0]['selector']);
    $this->assertSame('#webassistant-source-fields', $commands[1]['selector']);

    // Unknown values are ignored: the default entity type is used.
    [$form] = $this->buildAddForm(['entity_type' => 'user']);
    $this->assertSame('node', $form['scope']['entity_type']['#default_value']);
  }

}
