#!/bin/sh
# Copia de seguridad del despliegue con Docker Compose (CG-13.4): PostgreSQL (fuente de verdad,
# formato custom) y snapshots de Qdrant. Uso: scripts/backup.sh [directorio_destino]
set -eu
COMPOSE=${COMPOSE:-docker compose}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DEST=${1:-backups/$STAMP}
DB=${WEBASSISTANT_DB_NAME:-webassistant}
mkdir -p "$DEST/qdrant"
chmod 0777 "$DEST/qdrant"

echo "PostgreSQL -> $DEST/postgres.dump"
$COMPOSE exec -T postgres pg_dump -U postgres -Fc "$DB" > "$DEST/postgres.dump"

echo "Qdrant -> $DEST/qdrant/"
$COMPOSE run --rm --no-deps -T --user root \
  -v "$(pwd)/scripts:/scripts:ro" -v "$(cd "$DEST/qdrant" && pwd):/backup" \
  --entrypoint python api /scripts/qdrant_snapshots.py backup /backup

( cd "$DEST" && find . -type f ! -name SHA256SUMS -exec sha256sum {} + > SHA256SUMS )
echo "Copia completada en $DEST"
