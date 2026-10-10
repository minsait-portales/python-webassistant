# ADR 0005 · Cola de trabajos con Redis y arq

**Contexto.** Ingesta, reindexado, retención y conciliación no deben bloquear la API y necesitan
reintentos controlados.

**Decisión.** arq sobre Redis con reintentos exponenciales, `job_timeout` de 15 minutos, identificadores
de trabajo deterministas (evitan duplicados) y crons nocturnos de retención y conciliación. En
desarrollo y pruebas, `InlineQueue` ejecuta los mismos trabajos en proceso. El worker expone métricas en
`:9100` y una comprobación de salud (`webassistant-worker --check`).

**Alternativas.** Celery (más pesado, síncrono); colas gestionadas (dependencia de un proveedor de nube).

**Consecuencias.** Redis requiere persistencia AOF y política `noeviction`; un trabajo perdido se
recupera con la conciliación (inventario PostgreSQL ↔ Qdrant).
