# ADR 0012 · Copias de seguridad y recuperación

**Decisión.** PostgreSQL es la fuente de verdad (`pg_dump` en formato custom); Qdrant se copia con
snapshots por colección; ambos con sumas SHA-256 y almacenamiento cifrado fuera del host. Orden de
restauración: PostgreSQL → Qdrant → secretos → cola/worker → API y tráfico público, verificando conteos,
permisos, una conversación, una ingesta y una búsqueda antes de abrir el tráfico. Qdrant también puede
reconstruirse reindexando desde PostgreSQL o desde el portal.

**Consecuencias.** `scripts/backup.sh` y `scripts/restore.sh` automatizan el piloto; la restauración se
ha ensayado (borrado de colección y documentos, restauración y consulta con citas). RPO/RTO en
[NFR](../requisitos/nfr.md).
