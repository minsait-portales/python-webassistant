#!/bin/bash
# Arranque del Drupal de demostración: instala Drupal 11 (SQLite) la primera vez, aplica la
# receta del conector WebAssistant, crea el contenido de ejemplo y deja un bucle de cron que
# procesa la outbox. Todo se ejecuta como www-data.
set -euo pipefail

cd /opt/drupal
DATA=/var/www/drupal-data
MARKER="$DATA/.installed"
SITE_NAME=${DRUPAL_SITE_NAME:-Portal Drupal WebAssistant}
LANGCODE=${DRUPAL_DEFAULT_LANGCODE:-es}
CRON_INTERVAL=${DRUPAL_CRON_INTERVAL:-30}

drush() {
  runuser -u www-data -- /opt/drupal/vendor/bin/drush --root=/opt/drupal/web "$@"
}

mkdir -p "$DATA" /var/www/private
chown -R www-data:www-data "$DATA" /var/www/private /opt/drupal/web/sites/default

if [ ! -f "$MARKER" ]; then
  echo "[drupal] Instalando Drupal 11 (${SITE_NAME})..."
  drush site:install standard -y \
    --db-url="sqlite://localhost/${DATA}/site.sqlite" \
    --site-name="$SITE_NAME" \
    --account-name=admin \
    --account-pass="${DRUPAL_ADMIN_PASSWORD:-admin}" \
    --account-mail=admin@example.com \
    --site-mail=admin@example.com
  chmod u+w web/sites/default web/sites/default/settings.php
  if ! grep -q settings.docker.php web/sites/default/settings.php; then
    printf "\ninclude '/opt/drupal/docker/settings.docker.php';\n" >> web/sites/default/settings.php
  fi
  drush cache:rebuild

  if [ "$LANGCODE" != "en" ]; then
    echo "[drupal] Idioma por defecto: ${LANGCODE}"
    drush pm:install language -y
    drush language:add "$LANGCODE"
    drush config:set system.site default_langcode "$LANGCODE" -y
    drush config:set language.negotiation selected_langcode "$LANGCODE" -y || true
    # El idioma por defecto sin prefijo en la URL (/node/1) y el inglés con /en.
    drush config:set language.negotiation url.prefixes."$LANGCODE" '' -y
    drush config:set language.negotiation url.prefixes.en en -y
  fi

  echo "[drupal] Receta del conector WebAssistant..."
  drush recipe /opt/drupal/recipes/webassistant_connector_demo \
    --input="webassistant_connector_demo.site_id=${WEBASSISTANT_SITE_ID:-site_demo}" \
    --input="webassistant_connector_demo.public_base_url=${DRUPAL_PUBLIC_URL:-http://localhost:8080}" \
    --input="webassistant_connector_demo.download_base_url=${WEBASSISTANT_DOWNLOAD_BASE_URL:-http://drupal}"

  if [ "${DRUPAL_DEMO_CONTENT:-1}" = "1" ]; then
    echo "[drupal] Contenido de demostración..."
    drush php:script /opt/drupal/docker/demo-content.php
    drush webassistant:index --process || echo "[drupal] La sincronización inicial se reintentará con cron."
  fi
  touch "$MARKER"
  chown www-data:www-data "$MARKER"
fi

# Cron y outbox en segundo plano (en producción: cron del sistema o drush queue:run).
if [ "$CRON_INTERVAL" != "0" ]; then
  (
    while sleep "$CRON_INTERVAL"; do
      drush -q cron >/dev/null 2>&1 || true
    done
  ) &
fi

exec docker-php-entrypoint "$@"
