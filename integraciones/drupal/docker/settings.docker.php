<?php

/**
 * @file
 * Ajustes del Drupal de demostración en Docker (incluido desde settings.php).
 *
 * El conector lee sus secretos de variables de entorno (WEBASSISTANT_API_URL,
 * WEBASSISTANT_API_PUBLIC_URL, WEBASSISTANT_CLIENT_ID, WEBASSISTANT_CLIENT_SECRET
 * y WEBASSISTANT_SIGNING_KEY), por lo que aquí no aparece ninguno.
 */

declare(strict_types=1);

$settings['file_private_path'] = '/var/www/private';
$settings['config_sync_directory'] = '/var/www/drupal-data/config/sync';

// Hosts admitidos: el navegador (localhost) y la red de Docker (drupal), desde la
// que el worker de la plataforma descarga los originales firmados.
$hosts = getenv('DRUPAL_TRUSTED_HOSTS') ?: '^localhost$,^127\.0\.0\.1$,^drupal$';
$settings['trusted_host_patterns'] = array_values(array_filter(array_map('trim', explode(',', $hosts))));

// Cron lo ejecuta el bucle del contenedor; automated_cron no hace HTTP en las visitas.
$config['automated_cron.settings']['interval'] = 0;

// Errores visibles solo en el log: es un entorno de demostración, no de depuración.
$config['system.logging']['error_level'] = 'hide';
