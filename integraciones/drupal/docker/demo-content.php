<?php

/**
 * @file
 * Contenido de demostración del conector (drush php:script).
 *
 * - Usuario "editor" (rol content_editor) para probar el token autenticado y
 *   las host actions de contenido.
 * - Página publicada con información que solo existe en Drupal.
 * - Artículo con un PDF privado (original por referencia firmada).
 * - Borrador sin publicar, que nunca debe llegar a la plataforma.
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

$langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();

if (!user_load_by_name('editor')) {
  $editor = User::create([
    'name' => 'editor',
    'mail' => 'editor@example.com',
    'pass' => getenv('DRUPAL_EDITOR_PASSWORD') ?: 'editor',
    'status' => 1,
    'roles' => ['content_editor'],
  ]);
  $editor->save();
}

$page = Node::create([
  'type' => 'page',
  'langcode' => $langcode,
  'title' => 'Horario de la oficina de Zaragoza',
  'status' => 1,
  'body' => [
    'value' => '<p>La oficina de Zaragoza del portal Drupal atiende de lunes a viernes de 8:00 a 15:00 y los jueves por la tarde de 16:00 a 19:00.</p>'
    . '<h2>Cita previa</h2><p>La cita previa para la oficina de Zaragoza se solicita en el teléfono 976 000 000 o en el área de cliente.</p>',
    'format' => 'basic_html',
  ],
]);
$page->save();

$filesystem = \Drupal::service('file_system');
$directory = 'private://documentos';
$filesystem->prepareDirectory($directory, 1 | 2);
$uri = $filesystem->copy('/opt/drupal/docker/demo/guia-garantia.pdf', $directory . '/guia-garantia.pdf', FileExists::Replace);
$file = File::create([
  'uri' => $uri,
  'filename' => 'guia-garantia.pdf',
  'filemime' => 'application/pdf',
  'status' => 1,
  'uid' => 1,
]);
$file->save();

$article = Node::create([
  'type' => 'article',
  'langcode' => $langcode,
  'title' => 'Garantía de los productos',
  'status' => 1,
  'body' => [
    'value' => '<p>Consulta en la guía adjunta las condiciones de la garantía de los productos del portal.</p>',
    'format' => 'basic_html',
  ],
  'field_documentos' => [['target_id' => $file->id(), 'display' => 1, 'description' => 'Guía de garantía del portal']],
]);
$article->save();

$draft = Node::create([
  'type' => 'page',
  'langcode' => $langcode,
  'title' => 'Borrador confidencial',
  'status' => 0,
  'body' => ['value' => '<p>Contenido interno sin publicar: código BORRADOR-SECRETO-42.</p>', 'format' => 'basic_html'],
]);
$draft->save();

echo sprintf("Contenido de demostración: página %d, artículo %d (PDF %d), borrador %d.\n", $page->id(), $article->id(), $file->id(), $draft->id());
