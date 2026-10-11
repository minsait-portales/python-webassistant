#!/usr/bin/env bash
# Calidad y pruebas del conector Drupal contra una instalación Drupal 11 con las
# dependencias de desarrollo (drupal/core-dev, drush): enlaza el módulo en
# web/modules/custom y ejecuta phpcs, phpstan y PHPUnit.
#
#   DRUPAL_ROOT=/ruta/drupal integraciones/drupal/scripts/check.sh <orden> [rutas de prueba...]
#
# Órdenes: link | phpcs | phpstan | unit | kernel | functional | all
# Variables: DRUPAL_ROOT (por defecto /home/user/d11), SIMPLETEST_DB (SQLite propio por
# ejecución), FUNCTIONAL_PORT (servidor PHP para las pruebas Functional, por defecto 8890).
set -euo pipefail

HERE=$(cd "$(dirname "$0")/.." && pwd)
DRUPAL_ROOT=${DRUPAL_ROOT:-/home/user/d11}
DRUPAL_ROOT=$(cd "$DRUPAL_ROOT" && pwd)
export DRUPAL_ROOT
MODULE_LINK="$DRUPAL_ROOT/web/modules/custom/webassistant_connector"
export SIMPLETEST_DB=${SIMPLETEST_DB:-sqlite://localhost//tmp/wa-tests-$$.sqlite}
FUNCTIONAL_PORT=${FUNCTIONAL_PORT:-8890}
BIN="$DRUPAL_ROOT/vendor/bin"

link() {
  mkdir -p "$DRUPAL_ROOT/web/modules/custom"
  if [ ! -L "$MODULE_LINK" ] || [ "$(readlink "$MODULE_LINK")" != "$HERE/webassistant_connector" ]; then
    rm -rf "$MODULE_LINK"
    ln -s "$HERE/webassistant_connector" "$MODULE_LINK"
  fi
}

suite_dirs() {
  # Directorios de pruebas del módulo base y de los submódulos para un tipo.
  local kind=$1 dir
  for dir in "$MODULE_LINK/tests/src/$kind" "$MODULE_LINK"/modules/*/tests/src/"$kind"; do
    [ -d "$dir" ] && echo "$dir"
  done
  return 0
}

phpunit_config() {
  # Configuración PHPUnit propia (no depende de la del proyecto Drupal).
  local config="$DRUPAL_ROOT/phpunit.webassistant.$$.xml"
  cat >"$config" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="web/core/tests/bootstrap.php" colors="true"
         beStrictAboutTestsThatDoNotTestAnything="true" beStrictAboutOutputDuringTests="true"
         beStrictAboutChangesToGlobalState="true" failOnRisky="true" failOnWarning="true"
         displayDetailsOnTestsThatTriggerDeprecations="true" cacheResult="false">
  <php>
    <ini name="error_reporting" value="32767"/>
    <ini name="memory_limit" value="-1"/>
    <env name="SIMPLETEST_BASE_URL" value="http://127.0.0.1:${FUNCTIONAL_PORT}"/>
    <env name="BROWSERTEST_OUTPUT_DIRECTORY" value="${DRUPAL_ROOT}/web/sites/simpletest/browser_output"/>
    <env name="BROWSERTEST_OUTPUT_BASE_URL" value=""/>
    <env name="MINK_DRIVER_ARGS_WEBDRIVER" value=""/>
  </php>
  <source ignoreSuppressionOfDeprecations="true">
    <include><directory>web/modules/custom/webassistant_connector</directory></include>
  </source>
</phpunit>
XML
  echo "$config"
}

phpunit() {
  local kind=$1
  shift
  local targets=("$@")
  if [ ${#targets[@]} -eq 0 ]; then
    mapfile -t targets < <(suite_dirs "$kind")
  fi
  if [ ${#targets[@]} -eq 0 ]; then
    echo "Sin pruebas $kind."
    return 0
  fi
  local target status=0 config
  config=$(phpunit_config)
  mkdir -p "$DRUPAL_ROOT/web/sites/simpletest/browser_output"
  for target in "${targets[@]}"; do
    (cd "$DRUPAL_ROOT" && "$BIN/phpunit" -c "$config" "$target") || status=1
  done
  return $status
}

functional() {
  local log
  log=$(mktemp)
  (cd "$DRUPAL_ROOT/web" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$FUNCTIONAL_PORT" \
    -t "$DRUPAL_ROOT/web" "$DRUPAL_ROOT/web/.ht.router.php" >"$log" 2>&1) &
  local server=$!
  trap 'kill $server 2>/dev/null || true; rm -f "$log"' RETURN
  for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$FUNCTIONAL_PORT/" && break
    sleep 0.2
  done
  SIMPLETEST_BASE_URL="http://127.0.0.1:$FUNCTIONAL_PORT" phpunit Functional "$@"
}

trap 'rm -f "$DRUPAL_ROOT/phpunit.webassistant.$$.xml"' EXIT

command=${1:-all}
shift || true
link
case "$command" in
  link) echo "Enlazado en $MODULE_LINK" ;;
  phpcs) "$BIN/phpcs" --standard="$HERE/phpcs.xml.dist" --basepath="$HERE" "$HERE/webassistant_connector" "$@" ;;
  phpstan) "$BIN/phpstan" analyse -c "$HERE/phpstan.neon.dist" --memory-limit=2G --no-progress "$@" ;;
  unit) phpunit Unit "$@" ;;
  kernel) phpunit Kernel "$@" ;;
  functional) functional "$@" ;;
  all)
    status=0
    "$BIN/phpcs" --standard="$HERE/phpcs.xml.dist" --basepath="$HERE" "$HERE/webassistant_connector" || status=1
    "$BIN/phpstan" analyse -c "$HERE/phpstan.neon.dist" --memory-limit=2G --no-progress || status=1
    phpunit Unit || status=1
    phpunit Kernel || status=1
    functional || status=1
    exit $status
    ;;
  *) echo "Orden desconocida: $command" >&2; exit 2 ;;
esac
