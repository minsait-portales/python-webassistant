# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[SemVer](https://semver.org/lang/es/).

## [1.0.0-rc.1] - 2026-10-10

Primera release candidata de la capa genérica WebAssistant en Python para preproducción o piloto
controlado **no HA**.

### Añadido

- API v1 (FastAPI): bootstrap, sesiones anónimas y autenticadas, conversaciones con historial, mensajes
  en JSON o SSE con reanudación (`Last-Event-ID`) y cancelación, acciones con confirmación e
  idempotencia, host actions, ingesta canónica, inventario, ACL y búsqueda server-to-server.
- Orquestador con descubrimiento progresivo de skills, herramienta RAG, reintento con RAG forzado,
  reparación de salida, límites de iteraciones y detección de bucles.
- 17 skills migradas del módulo Drupal y SDK de skills (`webassistant-skill-sdk`) con CLI.
- RAG con Qdrant: extracción PDF/DOCX/HTML, troceado por base de conocimiento, filtros obligatorios por
  tenant/site/ACL, versiones de embeddings con activación y rollback, conciliación.
- Gateway de modelos (OpenAI y simulado) con perfiles, reintentos, circuit breaker, precios versionados,
  presupuestos, modelos permitidos y concurrencia.
- Multi-tenant con Row Level Security forzada en PostgreSQL, auditoría con payloads cifrados, retención.
- Administración: API y consola accesible (`/admin/`) con roles, assistants versionados, conocimiento,
  políticas, uso y costes con CSV, auditoría, trazas y paquetes de configuración con simulación.
- Widget embebible en la página (`loader.js` + bundle con SRI, Shadow DOM, WCAG 2.1 AA) y SDK TypeScript.
- Web de demostración autónoma con host actions de carrito, formularios, perfil e informes.
- Docker Compose (desarrollo/demo, producción con gateway HTTPS y perfil de observabilidad), Kustomize
  para la arquitectura objetivo, Prometheus/Grafana/Jaeger, backups y restauración.
- Métricas, alertas, trazas OpenTelemetry (API, worker, PostgreSQL, Redis, HTTP) y logs JSON con
  redacción.
- Documentación de arquitectura, ADR, integración, skills, consola, despliegue, runbooks, accesibilidad,
  pruebas y release.

### Seguridad

- Validación CG-15.10 sin hallazgos altos: carga de 30 minutos, SAST (bandit), dependencias (pip-audit,
  npm audit), DAST con OWASP ZAP y aislamiento entre tenants
  ([informe](docs/pruebas/carga-y-seguridad.md)).
- Descarga de originales solo por HTTPS, hacia hosts permitidos y sin direcciones internas (SSRF).
- Cuerpos JSON con el carácter NUL y entradas de administración no válidas responden `422` en lugar de
  errores de servidor.

### Migraciones

- `0001` (esquema inicial con RLS). Rollback: `alembic downgrade base` en instalaciones nuevas.
