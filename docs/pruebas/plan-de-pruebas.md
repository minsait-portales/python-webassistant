# Plan de pruebas (CG-15.1)

## 1. Estrategia

| Nivel | Herramientas | Alcance | Cuándo |
| --- | --- | --- | --- |
| Unitarias | pytest, Vitest | Seguridad (tokens, scopes, redacción, cifrado, SSRF), precios, políticas, troceado, procesadores, DOM seguro, renderers, tema, SSE | Cada commit (CI) |
| Integración API | pytest + httpx ASGI, SQLite y PostgreSQL real | Flujos públicos, acciones, ingesta, búsqueda, administración, agente con proveedor guionizado, aislamiento | Cada commit (CI, matriz SQLite/PostgreSQL) |
| Contrato | JSON Schema, OpenAPI | Presentaciones, respuestas, eventos, manifiestos, deriva de OpenAPI y del SDK | Cada commit |
| Base de datos | Alembic sobre PostgreSQL | upgrade/downgrade/upgrade y RLS con rol sin `BYPASSRLS` | Cada commit (CI) |
| E2E y accesibilidad | Playwright + axe-core | Widget en página real (demo), consola, CSP estricta, CSS agresivo, móvil, sesión autenticada | Cada commit (Chromium, Firefox, WebKit) |
| Despliegue | Docker Compose | Construcción de imagen, arranque con salud, humo extremo a extremo, backup y restauración, carga breve | Cada commit (CI) |
| Carga y seguridad | `scripts/loadtest.py`, bandit, pip-audit, npm audit, gitleaks, DAST | Perfil del piloto de CG-15.10 | Cada release candidata ([informe](carga-y-seguridad.md)) |
| Exploratorias y negocio | Sesiones guiadas sobre la demo con datos representativos | Comportamientos no previstos, calidad de respuestas | Cada release candidata |
| Accesibilidad manual | NVDA, VoiceOver, TalkBack, zoom | [Protocolo](../accesibilidad.md#revisión-manual) | Antes de la release |

## 2. Entornos y datos

- **Local/CI**: SQLite + Qdrant en memoria + cola en proceso (rápido) y PostgreSQL 16 real para RLS y
  migraciones; proveedor de modelos **simulado** (determinista, sin coste).
- **Compose**: el mismo stack que el piloto (PostgreSQL, Redis, Qdrant, worker) con la demo.
- **Datos**: tenant `demo` con 31 documentos (24 productos, 5 guías, 2 documentos técnicos en PDF), 17
  skills, 3 bases de conocimiento; las pruebas de aislamiento crean un segundo tenant y usuarios con
  distintos roles y ACL. Sin datos personales reales.
- **Navegadores**: Chromium (escritorio y Pixel 7), Firefox (escritorio), WebKit (escritorio e iPhone 13).

## 3. Trazabilidad

Los casos de uso, sus prioridades y las pruebas que los cubren están en
[casos de uso](../requisitos/casos-de-uso.md); las amenazas y sus pruebas en el
[modelo de amenazas](../seguridad/modelo-amenazas.md); la accesibilidad en su
[checklist](../accesibilidad.md).

| Suite | Fichero | Casos |
| --- | --- | --- |
| API pública y chat | `apps/api/tests/test_api_chat.py` | bootstrap, sesiones, orígenes, conversación, SSE y reanudación, cancelación, historial, reset, borrado, límites, fuera de ámbito, permisos de skills |
| Acciones | `apps/api/tests/test_api_actions.py` | preparación, confirmación, idempotencia, host actions, permisos |
| Agente | `apps/api/tests/test_agent.py` | herramientas, RAG forzado, reparación, límites, bucles, selección activa |
| Skills | `apps/api/tests/test_skills.py` | descubrimiento, compatibilidad, matcher, procesadores, citas verificadas, acciones |
| Aislamiento e ingesta | `apps/api/tests/test_isolation_and_ingest.py` | tenants, sites, ACL, ingesta idempotente, borrado, inventario, búsqueda |
| Descargas (SSRF) | `apps/api/tests/test_downloads.py` | esquemas, destinos privados, lista de hosts |
| Administración | `apps/api/tests/test_admin.py` | roles, tenants, sites, clientes, assistants versionados, precios, uso, auditoría, paquetes, administrador inicial |
| Demo | `apps/api/tests/test_demo.py` | web estática, rutas seguras, login simulado, activos sincronizados, configuración documentada |
| Trazas | `apps/api/tests/test_tracing.py` | spans y propagación API → worker |
| PostgreSQL | `apps/api/tests/test_postgres.py` | migraciones y RLS |
| Unidades | `apps/api/tests/test_units.py` | seguridad, precios, políticas, texto, troceado |
| Worker | `apps/worker/tests/test_worker.py` | trabajos, reintentos, crons |
| SDK de skills | `packages/skill-sdk/tests` | manifiestos, plantilla, validación |
| Widget | `apps/widget/tests/*.test.ts` | DOM seguro, renderers, loader, eventos, accesibilidad, chat con API simulada |
| SDK TypeScript | `packages/sdk-js/tests/sdk.test.ts` | SSE, reanudación, errores, renovación de token, clientes |
| E2E widget | `apps/widget/e2e/*.spec.ts` | teclado, axe, acciones, carrito, citas, recarga, CSS agresivo, contenedor, sesión, CSP, móvil |
| E2E consola | `apps/admin/e2e/console.spec.ts` | login, axe en todas las secciones, validación, versiones, CSV, importación, permisos |

## 4. Criterios de entrada y salida

**Entrada a la validación de una release candidata**: CI en verde en la rama de release, imagen
construida y desplegada en el entorno de validación con la demo, migraciones aplicadas, versión
etiquetada.

**Salida (go/no-go, CG-15.9)**:

- 100 % de las pruebas automatizadas en verde en los tres motores de navegador.
- Cero defectos bloqueantes o altos abiertos; los medios con plan y fecha.
- CG-15.10 superado: p95 ≤ 2 s en endpoints locales, < 1 % de 5xx, cero fugas entre tenants, cero
  hallazgos críticos o altos en SAST, dependencias y DAST.
- Checklist WCAG 2.1 AA firmado tras la revisión manual.
- Backup y restauración ensayados en el entorno de validación.

## 5. Gestión de defectos (CG-15.8)

Cada defecto registra: título, severidad (bloqueante, alta, media, baja), pasos, resultado esperado y
obtenido, evidencia (captura, `correlation_id`, traza), entorno, versión, impacto y decisión de cierre. Un
defecto corregido añade una prueba de regresión que lo reproduce.

## 6. Ejecución

```bash
uv run pytest                                  # Python (SQLite)
WEBASSISTANT_TEST_PG_ADMIN_URL=postgresql+asyncpg://webassistant:webassistant@localhost:5432/postgres \
WEBASSISTANT_TEST_DATABASE_URL=postgresql+asyncpg://webassistant:webassistant@localhost:5432/webassistant_test \
  uv run pytest                                # Python sobre PostgreSQL (rol sin BYPASSRLS)
npm test                                       # Vitest
npm run build && npm run e2e                   # Playwright + axe (arranca la API con la demo)
E2E_BROWSERS=firefox,webkit npm run e2e -w @webassistant/widget
E2E_BASE_URL=http://localhost:8000 npm run e2e # contra un stack ya levantado (Compose)
```
