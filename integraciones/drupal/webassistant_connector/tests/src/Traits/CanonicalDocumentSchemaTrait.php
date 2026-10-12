<?php

declare(strict_types=1);

namespace Drupal\Tests\webassistant_connector\Traits;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Validates documents against canonical-document.v1.schema.json.
 *
 * The schema is read from packages/contracts/schemas of the WebAssistant
 * repository (three levels above the module). When the module is installed
 * outside the repository, or justinrainbow/json-schema is missing, the
 * required members and main patterns are asserted instead.
 */
trait CanonicalDocumentSchemaTrait {

  /**
   * Returns the decoded schema, or NULL when it cannot be found.
   */
  protected static function canonicalDocumentSchema(): ?object {
    $module = dirname(__DIR__, 3);
    $path = dirname($module, 3) . '/packages/contracts/schemas/canonical-document.v1.schema.json';
    if (!is_file($path)) {
      return NULL;
    }
    $schema = json_decode((string) file_get_contents($path));
    return is_object($schema) ? $schema : NULL;
  }

  /**
   * Asserts that a document array is a valid canonical-document.v1.
   *
   * @param array<string, mixed> $document
   *   The result of CanonicalDocument::toArray().
   */
  protected function assertCanonicalDocument(array $document): void {
    $schema = self::canonicalDocumentSchema();
    if ($schema !== NULL && class_exists(Validator::class)) {
      $validator = new Validator();
      $data = json_decode((string) json_encode($document, JSON_THROW_ON_ERROR));
      $validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);
      $errors = array_map(static fn (array $error): string => trim($error['property'] . ' ' . $error['message']), $validator->getErrors());
      $this->assertTrue($validator->isValid(), 'canonical-document.v1 violations: ' . implode('; ', $errors));
      return;
    }
    foreach (['source_id', 'knowledge_base', 'type', 'title', 'version', 'acl'] as $key) {
      $this->assertArrayHasKey($key, $document);
    }
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.:\/@-]{1,256}$/', $document['source_id']);
    $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]{1,62}$/', $document['knowledge_base']);
    $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{1,40}$/', $document['type']);
    $this->assertLessThanOrEqual(64, strlen($document['version']));
    $this->assertNotEmpty($document['acl']);
    $this->assertNotSame(isset($document['text']), isset($document['reference']));
  }

}
