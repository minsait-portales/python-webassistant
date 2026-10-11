# syntax=docker/dockerfile:1.7
#
# Drupal 11 con el conector WebAssistant para desarrollo, demostración y pruebas extremo a
# extremo. No es una imagen de producción: el portal real instala el módulo con su propio
# proceso de despliegue (ver integraciones/drupal/README.md).
#
#   docker compose -f compose.yaml -f integraciones/drupal/compose.drupal.yaml up -d --build
#
# Argumentos:
#   DRUPAL_IMAGE      imagen oficial de Drupal 11 (PHP 8.3, Apache)
#   WITH_COMMERCE=1   añade Drupal Commerce para probar la extensión de carrito
#   DRUPAL_PACKAGES=0 no consulta packages.drupal.org (redes que lo bloquean; sin Commerce)
#   COMPOSER_PREFER_SOURCE=1  instala desde git (redes sin descargas zip de GitHub)
#
# Proxy corporativo con inspección TLS: EXTRA_CA_CERTS=/ruta/ca.crt (secreto de build).
# Es una imagen de desarrollo: Composer no bloquea paquetes con avisos de seguridad para poder
# añadir drush a la versión de core de la imagen base; usa siempre la imagen de Drupal más reciente.

ARG DRUPAL_IMAGE=drupal:11-php8.3-apache
ARG COMPOSER_IMAGE=composer:2

FROM ${DRUPAL_IMAGE} AS drupal

# --- 1. Dependencias PHP adicionales (drush y, opcionalmente, Commerce) ------------------------
FROM ${COMPOSER_IMAGE} AS tools
ARG WITH_COMMERCE=0
ARG DRUPAL_PACKAGES=1
ARG COMPOSER_PREFER_SOURCE=0
COPY --from=drupal /opt/drupal /opt/drupal
WORKDIR /opt/drupal
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=cache,target=/tmp/composer-cache \
    set -eu; \
    if [ -s /run/secrets/extra_ca ]; then \
      cat /run/secrets/extra_ca >> /etc/ssl/certs/ca-certificates.crt; \
      export SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt GIT_SSL_CAINFO=/etc/ssl/certs/ca-certificates.crt; \
    fi; \
    export COMPOSER_CACHE_DIR=/tmp/composer-cache; \
    cp composer.json /tmp/composer.json.orig; \
    if [ "$DRUPAL_PACKAGES" = "0" ]; then \
      php -r '$j = json_decode(file_get_contents("composer.json"), TRUE); $j["repositories"] = array_values(array_filter($j["repositories"] ?? [], fn ($r) => !str_contains($r["url"] ?? "", "packages.drupal.org"))); if (!$j["repositories"]) { unset($j["repositories"]); } file_put_contents("composer.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'; \
    fi; \
    packages="drush/drush:^13"; \
    if [ "$WITH_COMMERCE" = "1" ]; then packages="$packages drupal/commerce:^3"; fi; \
    prefer=""; \
    if [ "$COMPOSER_PREFER_SOURCE" = "1" ]; then prefer="--prefer-source"; fi; \
    composer require --ignore-platform-reqs --no-security-blocking $prefer $packages; \
    if [ "$DRUPAL_PACKAGES" = "0" ]; then \
      php -r '$j = json_decode(file_get_contents("composer.json"), TRUE); $o = json_decode(file_get_contents("/tmp/composer.json.orig"), TRUE); $j["repositories"] = $o["repositories"] ?? []; file_put_contents("composer.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'; \
    fi; \
    find vendor web/modules/contrib -name .git -type d -prune -exec rm -rf {} + 2>/dev/null || true; \
    mkdir -p web/modules/contrib web/libraries

# --- 2. Imagen final -----------------------------------------------------------------------
FROM drupal
COPY --from=tools /opt/drupal/composer.json /opt/drupal/composer.lock /opt/drupal/
COPY --from=tools /opt/drupal/vendor /opt/drupal/vendor
COPY --from=tools /opt/drupal/web/modules/contrib /opt/drupal/web/modules/contrib
COPY --from=tools /opt/drupal/web/libraries /opt/drupal/web/libraries
COPY webassistant_connector /opt/drupal/web/modules/custom/webassistant_connector
COPY recipes /opt/drupal/recipes
COPY docker/settings.docker.php docker/demo-content.php /opt/drupal/docker/
COPY docker/demo /opt/drupal/docker/demo
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/drupal-webassistant-entrypoint
ENV PATH=/opt/drupal/vendor/bin:$PATH
RUN mkdir -p /var/www/drupal-data /var/www/private \
    && chown -R www-data:www-data /var/www/drupal-data /var/www/private /opt/drupal/web/sites/default
HEALTHCHECK --interval=10s --timeout=5s --start-period=180s --retries=30 \
  CMD php -r 'exit(@file_get_contents("http://localhost/user/login") === false ? 1 : 0);'
ENTRYPOINT ["drupal-webassistant-entrypoint"]
CMD ["apache2-foreground"]
