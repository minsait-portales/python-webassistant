# webassistant-api

API de WebAssistant (FastAPI): API pública v1, administración, ingesta y búsqueda, orquestador de agente,
motor de skills, RAG sobre Qdrant, gateway de modelos, costes, auditoría, observabilidad y CLI
`webassistant` (`migrate`, `seed-demo`, `config-import`, `config-export`, `create-admin`,
`create-client`, `retention`, `rag-eval`, `export-openapi`, `generate-key`).

```bash
uv run --project apps/api uvicorn --factory webassistant.api.app:app_factory --reload
uv run --project apps/api webassistant --help
```

Ver el [README del repositorio](../../README.md) y la [documentación](../../docs/).
