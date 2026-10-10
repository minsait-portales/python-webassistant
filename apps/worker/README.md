# webassistant-worker

Worker asíncrono (arq + Redis) de WebAssistant: ingesta y extracción de documentos, reindexado de
embeddings, retención y conciliación PostgreSQL ↔ Qdrant. Expone métricas Prometheus en `:9100` y
`webassistant-worker --check` para las comprobaciones de salud.

```bash
WEBASSISTANT_REDIS_URL=redis://localhost:6379/0 uv run --project apps/worker webassistant-worker
```
