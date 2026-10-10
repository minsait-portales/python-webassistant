# WebAssistant · capa genérica en Python

Producto **WebAssistant AI** desacoplado de cualquier CMS: una API Python multi-tenant con
orquestador de agente, skills portables, RAG sobre Qdrant, gobierno de costes y una etiqueta
`<script>` que monta el chat **directamente en la página anfitriona** (Drupal, Liferay, Adobe,
SharePoint, WordPress o una web HTML). Implementa el documento
*WebAssistant – Capa genérica en Python* (v1.4) a partir del módulo Drupal `webassistant_ai`.

```html
<script async src="https://asistente.example.com/widget/v1/loader.js"
        data-site-id="site_demo" data-assistant-id="catalogo"></script>
```

## Arranque rápido (Docker Compose)

```bash
docker compose up -d --build
```

| URL | Qué es |
| --- | --- |
| <http://localhost:8000/demo/> | Web de demostración con el widget, carrito y host actions |
| <http://localhost:8000/admin/> | Consola de administración (`admin@demo.local` / `Demo-Admin-2026`, solo desarrollo) |
| <http://localhost:8000/docs> | Referencia interactiva de la API (OpenAPI 3.1) |
| <http://localhost:8000/health/ready> | Estado de PostgreSQL, Redis y Qdrant |

Sin fichero `.env` arranca en modo desarrollo con el **proveedor de modelos simulado** (sin coste ni
claves) y los datos de la demo. Para usar OpenAI (`gpt-6-luna` por defecto) basta con
`WEBASSISTANT_DEFAULT_PROVIDER=openai` y `OPENAI_API_KEY`. Otras variantes:

```bash
docker compose --profile observability up -d                 # + Prometheus :9090 y Grafana :3000
docker compose -f compose.yaml -f compose.prod.yaml up -d     # piloto/producción con gateway HTTPS
make help                                                     # atajos de desarrollo y operación
```

Detrás de un proxy corporativo con inspección TLS: `EXTRA_CA_CERTS=/ruta/ca.crt docker compose build`.

## Arquitectura

```
Página anfitriona ── loader.js ──► widget (Shadow DOM) ──HTTPS/SSE──► Gateway TLS
        ▲  host actions y eventos                                            │
        └──────────────────────────────────────────────────────────────────── API FastAPI
                                                                              │  orquestador · skills · RAG
Portal (backend) ── /v1/token, /v1/ingest ──────────────────────────────────►│  costes · auditoría
                                                                              ├── PostgreSQL (RLS por tenant)
                       worker (arq) ◄── Redis (cola, límites, eventos SSE) ◄──┤
                       ingesta · reindexado · retención · conciliación        ├── Qdrant (vectores filtrados)
                                                                              └── Proveedores (OpenAI / simulado)
```

- **API** (`apps/api`): FastAPI asíncrona; API pública v1, administración, ingesta, SSE con
  reanudación, acciones con confirmación e idempotencia, métricas Prometheus y trazas OpenTelemetry.
- **Worker** (`apps/worker`): arq sobre Redis para ingesta, reindexado de embeddings, retención y
  conciliación PostgreSQL ↔ Qdrant.
- **Widget** (`apps/widget`): `loader.js` estable con SRI + bundle versionado; Shadow DOM, WCAG 2.1 AA,
  renderers seguros, host actions y modo `data-result-target`.
- **Consola** (`apps/admin`): SPA accesible para tenants, sites, clientes API, assistants versionados,
  conocimiento, políticas, costes, auditoría y trazas.
- **SDK** (`packages/sdk-js`, `packages/skill-sdk`): clientes TypeScript generados desde OpenAPI y SDK
  Python para crear skills fuera del núcleo.
- **Contratos** (`packages/contracts`): OpenAPI 3.1 y JSON Schema 2020-12 versionados (API-first).

Más detalle en [docs/arquitectura.md](docs/arquitectura.md) y las [ADR](docs/adr/).

## Estructura

```
apps/api            API, orquestador, skills integradas, RAG, seguridad, administración, CLI
apps/worker         Worker asíncrono (arq)
apps/widget         loader.js + chat embebible (TypeScript, esbuild, Vitest, Playwright)
apps/admin          Consola de administración (TypeScript)
packages/contracts  OpenAPI y JSON Schema de todos los contratos v1
packages/sdk-js     SDK TypeScript (@webassistant/sdk)
packages/skill-sdk  SDK Python de skills (webassistant-skill-sdk + CLI webassistant-skill)
examples/skills     Skill de ejemplo instalada sin tocar el núcleo
demo/               Web HTML de demostración (CG-16.3)
docker/, compose*.yaml   Imagen única y despliegue con Docker Compose
deploy/k8s          Kustomize para la arquitectura objetivo de producción
deploy/observability     Prometheus, alertas y cuadro de mando de Grafana
scripts/            Copias de seguridad, carga/aislamiento, utilidades
docs/               Arquitectura, ADR, guías, runbooks, accesibilidad y pruebas
```

## Desarrollo local

Requisitos: Python 3.12+, [uv](https://docs.astral.sh/uv/), Node.js 22 y Docker (opcional).

```bash
uv sync --all-packages && npm ci        # dependencias
npm run build                           # SDK, widget y consola
make dev                                # API con SQLite + Qdrant en memoria + demo en :8000
uv run pytest                           # pruebas Python (unitarias, integración, contrato, aislamiento)
npm test                                # pruebas del widget y el SDK (Vitest)
npm run e2e                             # Playwright + axe contra la API real
make lint                               # ruff, mypy, ESLint y Prettier
```

Las pruebas sobre PostgreSQL real (migraciones y RLS) se activan con
`WEBASSISTANT_TEST_PG_ADMIN_URL` y `WEBASSISTANT_TEST_DATABASE_URL` (ver
[docs/pruebas/plan-de-pruebas.md](docs/pruebas/plan-de-pruebas.md)).

## Documentación

| Documento | Contenido |
| --- | --- |
| [Arquitectura](docs/arquitectura.md) y [ADR](docs/adr/) | Componentes, flujos y decisiones |
| [Casos de uso](docs/requisitos/casos-de-uso.md) y [NFR](docs/requisitos/nfr.md) | Alcance MVP/V1, requisitos medibles, RPO/RTO |
| [Paridad con Drupal](docs/paridad-drupal.md) | Qué se conserva, rediseña o elimina del módulo |
| [Modelo de amenazas](docs/seguridad/modelo-amenazas.md) | Riesgos y mitigaciones |
| [Guía de integración](docs/integracion.md) | API v1, autenticación, widget, host actions, eventos, SDK e ingesta |
| [Matriz por plataforma](docs/integraciones.md) | Propuesta de integración con Drupal, Liferay, AEM, SharePoint y WordPress |
| [Guía de skills](docs/skills.md) | Crear e instalar skills sin modificar el núcleo |
| [Manual de la consola](docs/consola.md) | Administración, costes, límites y auditoría |
| [Configuración](docs/configuracion.md) | Todas las variables `WEBASSISTANT_*` |
| [Despliegue y operación](docs/despliegue.md) | Piloto no HA, producción, backups, RPO/RTO, rollback |
| [Runbooks](docs/runbooks.md) | Respuesta a alertas e incidentes |
| [Accesibilidad](docs/accesibilidad.md) | Checklist WCAG 2.1 AA y evidencias |
| [Plan de pruebas](docs/pruebas/plan-de-pruebas.md) y [carga/seguridad](docs/pruebas/carga-y-seguridad.md) | Estrategia, trazabilidad y resultados de CG-15.10 |
| [Release](docs/release.md) y [CHANGELOG](CHANGELOG.md) | Checklist de release, SemVer, SBOM y promoción |

## Licencia

Software de Minsait. Consulta con el propietario del producto las condiciones de uso y distribución.
