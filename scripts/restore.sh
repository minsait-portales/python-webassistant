#!/bin/sh
# Restauración desde scripts/backup.sh (CG-13.4). Para API y worker durante la operación.
# Uso: scripts/restore.sh backups/<marca_temporal>
set -eu
COMPOSE=${COMPOSE:-docker compose}
SRC=${1:?Indica el directorio de la copia}
DB=${WEBASSISTANT_DB_NAME:-webassistant}
( cd "$SRC" && sha256sum -c SHA256SUMS )

$COMPOSE stop api worker
echo "Restaurando PostgreSQL desde $SRC/postgres.dump"
$COMPOSE exec -T postgres pg_restore -U postgres --clean --if-exists --no-privileges -d "$DB" < "$SRC/postgres.dump"

echo "Restaurando Qdrant desde $SRC/qdrant/"
$COMPOSE run --rm --no-deps -T --user root \
  -v "$(pwd)/scripts:/scripts:ro" -v "$(cd "$SRC/qdrant" && pwd):/backup:ro" \
  --entrypoint python api /scripts/qdrant_snapshots.py restore /backup

$COMPOSE up -d api worker
echo "Restauración completada. Revisa /health/ready y ejecuta la conciliación desde la consola."
