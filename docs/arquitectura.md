# Arquitectura

Este documento describe la capa genérica tal como está implementada. Las decisiones y sus
alternativas están en las [ADR](adr/); los requisitos medibles en [NFR](requisitos/nfr.md).

## 1. Principios

1. **Núcleo independiente del portal.** Ningún componente depende de Drupal, Liferay, Adobe,
   SharePoint o WordPress. El portal integra el producto con una etiqueta `<script>`, un cliente
   server-to-server y, si quiere, host actions.
2. **El modelo, las credenciales y la lógica de skills viven en el servidor.** El navegador solo recibe
   tokens temporales con scopes y presentaciones validadas.
3. **API-first y contratos versionados.** OpenAPI 3.1 y JSON Schema 2020-12 en `packages/contracts`;
   los SDK se generan desde ellos y una prueba detecta la deriva.
4. **Aislamiento por defecto.** Cada consulta lleva tenant, site y ACL: PostgreSQL con Row Level
   Security forzada y Qdrant con filtros obligatorios.
5. **Respuestas estructuradas y verificadas.** El modelo propone JSON; el servidor lo valida contra el
   esquema de la skill, descarta datos inventados (productos, citas) y degrada a texto si no es válido.
6. **Las acciones con efectos se autorizan, se confirman y son idempotentes.**
7. **Todo es trazable y tiene coste.** Cada run registra llamadas, herramientas, acciones, tokens
   (entrada, caché, salida, razonamiento) y coste con precios versionados.

## 2. Componentes

| Componente | Tecnología | Responsabilidad |
| --- | --- | --- |
| Gateway | Caddy (Compose) / Ingress (Kubernetes) | TLS, único punto expuesto, SSE sin buffer |
| API | FastAPI, Uvicorn, SQLAlchemy 2 async | API pública v1, administración, ingesta, búsqueda, SSE, activos estáticos |
| Orquestador | `webassistant.orchestrator` | Assistant publicado → agente con herramientas → skill → presentación |
| Motor de skills | `webassistant.skills` | Descubrimiento, compatibilidad, selección, validación, procesadores y renderers |
| RAG | `webassistant.rag` + Qdrant | Extracción, troceado, embeddings versionados, búsqueda filtrada, evaluación |
| Gateway de modelos | `webassistant.gateway` | Proveedores (OpenAI, simulado), reintentos, circuit breaker, perfiles de modelo, precios y políticas |
| Worker | arq + Redis | Ingesta, reindexado, retención, conciliación, reintentos con backoff |
| Datos | PostgreSQL 16 | Fuente de verdad: configuración, conversaciones, uso, auditoría, documentos y fragmentos |
| Vectores | Qdrant 1.19 | Puntos por fragmento con payload `tenant_id`, `site_id`, `knowledge_base`, `acl`, `locale` |
| Redis | Redis 7 | Cola, rate limiting, idempotencia, eventos de runs (SSE) y cancelación entre réplicas |
| Widget | TypeScript, Shadow DOM | Chat en la página anfitriona, accesible, sin `innerHTML` |
| Consola | TypeScript | Administración por roles |
| Observabilidad | Prometheus, Grafana, OpenTelemetry, logs JSON | Métricas, alertas, trazas y búsqueda por `run_id` |

## 3. Modelo multi-tenant

```
tenant ─┬─ sites (orígenes, idiomas, tema, capacidades, modo de acceso)
        ├─ api_clients (credenciales server-to-server, hash del secreto)
        ├─ admin_users (roles: tenant_admin, editor, auditor, viewer)
        ├─ agent_configs ── assistants ── assistant_versions (inmutables) ── assistant_skills
        ├─ knowledge_bases ── documents ── chunks  ⇄  puntos en Qdrant
        ├─ conversations ── messages, runs ── tool_calls, action_executions
        ├─ usage_events, tenant_policies, retention_policies
        └─ audit_events ── audit_payloads (cifrados con Fernet)
plataforma: tenants, admin_users platform_admin, model_prices, embedding_indexes, skills
```

- Las 19 tablas con `tenant_id` tienen `ENABLE` + `FORCE ROW LEVEL SECURITY` y la política
  `tenant_id = current_setting('app.tenant_id')`. Cada unidad de trabajo fija el tenant con
  `set_config(..., true)` (local a la transacción); solo las operaciones de plataforma activan
  `app.bypass_rls`. La aplicación se conecta con un rol **sin** `SUPERUSER` ni `BYPASSRLS`.
- Los repositorios añaden además el filtro por tenant en SQL (defensa en profundidad) y las pruebas
  negativas comprueban que un tenant no lee, modifica ni busca datos de otro.
- En Qdrant cada búsqueda construye un `SearchScope` obligatorio (tenant, site, bases permitidas,
  ACL del usuario, idioma); no existe una ruta de búsqueda sin filtro.

## 4. Flujos principales

### 4.1 Carga del widget y sesión

1. `loader.js` (caché corta) valida `data-site-id`, evita dobles montajes y carga
   `assets/widget.<hash>.js` con **SRI**.
2. `GET /v1/bootstrap` devuelve textos, tema validado, sugerencias, límites y capacidades del
   assistant publicado. El origen debe estar registrado en el site.
3. `POST /v1/sessions` emite un token de widget (JWT, 15 min) con scopes. Sesión **anónima** (con un
   identificador firmado en `localStorage`) o **autenticada** si la página aporta el token que su
   backend obtuvo con `POST /v1/token` (`grant_type=widget_token`). Al iniciar sesión se vinculan las
   conversaciones anónimas.

### 4.2 Mensaje y run

```
POST /v1/conversations/{id}/messages (Accept: text/event-stream)
  → límites (tamaño, rate limit, presupuesto, concurrencia) → run en Redis/PostgreSQL
  → contexto: historial (ventana o resumen) + selección activa + skills disponibles
  → agente: catálogo de skills (descubrimiento progresivo) → contrato de la skill elegida
      ↳ herramienta search_knowledge (RAG filtrado) con reintento con RAG forzado si procede
  → JSON de la skill → validación (esquema + procesadores) → una reparación como máximo
  → presentación v1 + acciones permitidas por scopes + citas verificadas
  → eventos SSE: run.started, run.status, reply.delta, run.completed | run.failed | run.cancelled
```

- Los eventos se publican en Redis con número de secuencia: un cliente desconectado reanuda con
  `Last-Event-ID` en `GET .../runs/{run_id}/events`, aunque llegue a otra réplica.
- Límites del agente: iteraciones, llamadas a herramientas, detección de bucles, tiempo y tokens.
  Si se superan, el usuario recibe un mensaje controlado y el run queda trazado.
- Las consultas fuera de ámbito no cambian la presentación en pantalla (`keep_presentation`).

### 4.3 Acciones

1. `POST /v1/actions/prepare`: valida skill, acción, permisos y parámetros (JSON Schema de la
   acción) y devuelve una propuesta; si tiene efectos, un **token de confirmación** de un solo uso.
2. El widget muestra un diálogo accesible; al confirmar llama a `POST /v1/actions/{id}/execute` con
   `Idempotency-Key`. Repetir la llamada devuelve el mismo resultado sin duplicar efectos.
3. Acciones `internal` se ejecutan en el servidor; acciones `host` se delegan a la página
   (`window.WebAssistant.registerHostAction`) con `correlation_id`, y la página informa del resultado
   en `POST /v1/actions/{id}/result`. Todo queda auditado.

### 4.4 Ingesta y búsqueda

1. El portal publica documentos canónicos (`POST /v1/ingest/documents`): texto, Markdown, HTML,
   referencia a PDF/DOCX descargable desde hosts permitidos, ACL y metadatos. Es idempotente por
   `source_id` + `version` + hash de contenido.
2. El worker extrae, trocea según la base de conocimiento, calcula embeddings con el índice activo y
   escribe fragmentos en PostgreSQL y puntos en Qdrant. Los **originales permanecen en el portal**.
3. `GET /v1/ingest/inventory/{kb}` permite conciliar; `PUT /v1/ingest/acl/...` propaga cambios de
   ACL a ambos almacenes; el borrado elimina derivados.
4. Cambiar de modelo de embeddings crea una colección nueva (`building → ready → active`) que se
   activa sin corte y admite rollback.

## 5. Configuración versionada

Assistants, agentes, skills, mensajes, límites y precios se editan en borrador y se publican como
versiones inmutables; cada run guarda la versión con la que se ejecutó. La configuración completa de
un tenant se exporta e importa como **paquete** (`config-package v1`, sin secretos, con dry-run e
idempotente) para promover cambios entre entornos.

## 6. Seguridad (resumen)

JWT con audiencias distintas (widget, servidor, administración, confirmación, sesión anónima) y
rotación de secretos; CORS por site; CSP estricta en consola y demo; Shadow DOM sin HTML del modelo;
secretos de clientes API con hash; payloads técnicos cifrados y de acceso auditado; redacción de PII
en logs; límites por IP, site, usuario y administrador. Ver el
[modelo de amenazas](seguridad/modelo-amenazas.md).

## 7. Despliegue

- **Piloto no HA** (Compose): un servidor con gateway HTTPS, API, worker, PostgreSQL, Redis, Qdrant y
  observabilidad en contenedores separados. Tiene un punto único de fallo y no constituye por sí sola la
  arquitectura de producción.
- **Producción** (Kubernetes o servicios gestionados): API con réplicas en nodos/zonas distintos,
  worker separado, PostgreSQL/Redis/Qdrant gestionados y privados, observabilidad y backups fuera del
  dominio de fallo. Ver [despliegue](despliegue.md).
