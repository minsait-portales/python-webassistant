# Configuración

La API, el worker y la CLI leen la configuración de variables de entorno con el prefijo
`WEBASSISTANT_` (o de un fichero `.env` en el directorio de trabajo). Las variables vacías equivalen a no
definidas. Las listas se escriben separadas por comas. En `staging` y `production` el arranque falla si
falta alguno de los requisitos de seguridad (secretos, PostgreSQL, Redis, Qdrant como servicio, sin
proveedor simulado ni demo en producción).

Una prueba (`test_settings_are_documented`) comprueba que todas las variables están en esta página.

## General

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_ENVIRONMENT` | `development` | `development`, `test`, `staging` o `production` (estos dos activan las comprobaciones estrictas) |
| `WEBASSISTANT_SERVICE_NAME` | `webassistant-api` | Nombre del servicio en logs y trazas |
| `WEBASSISTANT_PUBLIC_BASE_URL` | `http://localhost:8000` | URL pública de la API (enlaces, origen de la demo) |
| `WEBASSISTANT_LOG_LEVEL` | `INFO` | Nivel de log |
| `WEBASSISTANT_LOG_JSON` | `true` | Logs JSON con `trace_id`, tenant, site, conversación y run |

## Datos

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_DATABASE_URL` | SQLite local | `postgresql+asyncpg://usuario:clave@host:5432/bd` en cualquier entorno compartido |
| `WEBASSISTANT_DATABASE_POOL_SIZE` | `10` | Conexiones del pool por proceso |
| `WEBASSISTANT_DATABASE_MAX_OVERFLOW` | `10` | Conexiones adicionales temporales |
| `WEBASSISTANT_DATABASE_ECHO` | `false` | Registrar SQL (solo depuración) |
| `WEBASSISTANT_DATABASE_RLS` | `true` | Fijar el tenant en cada transacción para Row Level Security |
| `WEBASSISTANT_REDIS_URL` | — | `redis://` o `rediss://`; obligatoria con varias réplicas, cola Redis y en producción |
| `WEBASSISTANT_QUEUE_MODE` | `inline` | `redis` (worker) o `inline` (en proceso, desarrollo y pruebas) |
| `WEBASSISTANT_QUEUE_NAME` | `webassistant:jobs` | Nombre de la cola de arq |
| `WEBASSISTANT_QDRANT_URL` | — | URL de Qdrant; sin ella se usa `QDRANT_LOCATION` |
| `WEBASSISTANT_QDRANT_API_KEY` | — | API key de Qdrant |
| `WEBASSISTANT_QDRANT_LOCATION` | `:memory:` | Qdrant embebido (solo desarrollo y pruebas) |
| `WEBASSISTANT_QDRANT_COLLECTION_PREFIX` | `wa_chunks` | Prefijo de las colecciones (una por versión de embeddings) |
| `WEBASSISTANT_QDRANT_TIMEOUT_SECONDS` | `10` | Tiempo máximo de las operaciones de Qdrant |

## Seguridad y tokens

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_JWT_SECRET` | — (secreto de desarrollo) | Secreto HS256, al menos 32 caracteres aleatorios en staging/producción |
| `WEBASSISTANT_JWT_PREVIOUS_SECRETS` | — | Secretos anteriores aceptados durante una rotación |
| `WEBASSISTANT_JWT_ISSUER` | `webassistant` | Emisor de los tokens |
| `WEBASSISTANT_WIDGET_TOKEN_TTL_SECONDS` | `900` | Vida del token de widget (máx. 1 h) |
| `WEBASSISTANT_SERVER_TOKEN_TTL_SECONDS` | `600` | Vida del token server-to-server |
| `WEBASSISTANT_ADMIN_TOKEN_TTL_SECONDS` | `3600` | Vida de la sesión de la consola |
| `WEBASSISTANT_CONFIRMATION_TOKEN_TTL_SECONDS` | `120` | Vida del token de confirmación de una acción |
| `WEBASSISTANT_ANONYMOUS_SESSION_TTL_DAYS` | `30` | Vida del identificador de sesión anónima del navegador |
| `WEBASSISTANT_ENCRYPTION_KEY` | — (efímera) | Clave Fernet de los payloads técnicos (`webassistant generate-key`) |
| `WEBASSISTANT_TRUSTED_PROXIES` | — | CIDR de proxies de confianza para `X-Forwarded-For` (gateway, ingress) |
| `WEBASSISTANT_CORS_CACHE_SECONDS` | `60` | Caché de la respuesta preflight |
| `WEBASSISTANT_BOOTSTRAP_ADMIN_EMAIL` | — | Administrador de plataforma creado al arrancar si no existe |
| `WEBASSISTANT_BOOTSTRAP_ADMIN_PASSWORD` | — | Su contraseña (≥ 12 caracteres, mayúsculas, minúsculas y números) |

## Límites

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_MAX_REQUEST_BYTES` | `1000000` | Tamaño máximo de una petición |
| `WEBASSISTANT_MAX_INGEST_REQUEST_BYTES` | `25000000` | Tamaño máximo de un lote de ingesta |
| `WEBASSISTANT_REQUEST_TIMEOUT_SECONDS` | `120` | Tiempo máximo de una petición no streaming |
| `WEBASSISTANT_RATE_LIMIT_SITE_PER_MINUTE` | `1200` | Peticiones por site y minuto (dimensionado para ≥ 20 usuarios activos simultáneos) |
| `WEBASSISTANT_RATE_LIMIT_SUBJECT_PER_MINUTE` | `30` | Mensajes por usuario y minuto (otras peticiones: ×4) |
| `WEBASSISTANT_RATE_LIMIT_IP_PER_MINUTE` | `120` | Peticiones por IP y minuto |
| `WEBASSISTANT_RATE_LIMIT_ADMIN_PER_MINUTE` | `300` | Peticiones por administrador y minuto |

Los presupuestos, los modelos permitidos y la concurrencia por tenant o assistant se configuran en la
consola (políticas).

## Modelos

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_DEFAULT_PROVIDER` | `simulated` | `openai` o `simulated` (determinista, sin coste; prohibido en producción) |
| `WEBASSISTANT_DEFAULT_CHAT_MODEL` | `gpt-6-luna` | Modelo de chat por defecto de los assistants nuevos |
| `WEBASSISTANT_DEFAULT_EMBEDDING_MODEL` | `text-embedding-3-small` | Modelo de embeddings del índice inicial |
| `WEBASSISTANT_OPENAI_API_KEY` | — | Clave de OpenAI (obligatoria con el proveedor `openai`) |
| `WEBASSISTANT_OPENAI_BASE_URL` | — | Endpoint compatible OpenAI (Azure OpenAI, pasarela corporativa) |
| `WEBASSISTANT_OPENAI_ORGANIZATION` | — | Organización de OpenAI |
| `WEBASSISTANT_PROVIDER_TIMEOUT_SECONDS` | `60` | Tiempo máximo por llamada al proveedor |
| `WEBASSISTANT_PROVIDER_MAX_RETRIES` | `2` | Reintentos con backoff y jitter ante errores transitorios |
| `WEBASSISTANT_CIRCUIT_BREAKER_FAILURES` | `5` | Fallos consecutivos que abren el circuito de un modelo |
| `WEBASSISTANT_CIRCUIT_BREAKER_RESET_SECONDS` | `30` | Tiempo hasta reintentar con el circuito abierto |

## Skills

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_SKILL_PATHS` | — | Directorios adicionales con paquetes de skills |
| `WEBASSISTANT_LOAD_BUILTIN_SKILLS` | `true` | Cargar las skills integradas |
| `WEBASSISTANT_LOAD_ENTRY_POINT_SKILLS` | `true` | Cargar skills instaladas como paquetes Python (`webassistant.skills`) |

## Ingesta y RAG

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_INGEST_ALLOWED_DOWNLOAD_HOSTS` | — | Hosts desde los que se descargan originales (y sus subdominios). **Obligatoria en staging/producción** si se ingieren referencias; sin ella solo se aceptan destinos públicos |
| `WEBASSISTANT_INGEST_MAX_DOWNLOAD_BYTES` | `50000000` | Tamaño máximo de un original |
| `WEBASSISTANT_INGEST_DOWNLOAD_TIMEOUT_SECONDS` | `30` | Tiempo máximo de descarga |
| `WEBASSISTANT_INGEST_TEMP_DIR` | temporal del sistema | Directorio de ficheros temporales (se borran al terminar) |
| `WEBASSISTANT_RAG_DEFAULT_LIMIT` | `8` | Fragmentos por búsqueda si la política no indica otro valor |
| `WEBASSISTANT_RAG_DEFAULT_MIN_SCORE` | `0.25` | Puntuación mínima por defecto |

## Activos estáticos y demo

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_WIDGET_DIST_DIR` | `/app/static/widget` o `apps/widget/dist` | Directorio de `loader.js` y bundles |
| `WEBASSISTANT_ADMIN_DIST_DIR` | `/app/static/admin` o `apps/admin/dist` | Directorio de la consola |
| `WEBASSISTANT_DEMO_ENABLED` | `false` | Datos y web de demostración (prohibido en producción) |
| `WEBASSISTANT_DEMO_ORIGINS` | — | Orígenes adicionales del site de demo (variable leída por la semilla) |
| `WEBASSISTANT_DEMO_CLIENT_SECRET` | — | Secreto del cliente API de demo (variable leída por la semilla) |

## Observabilidad

| Variable | Por defecto | Descripción |
| --- | --- | --- |
| `WEBASSISTANT_METRICS_ENABLED` | `true` | Exponer `/metrics` (API) y el puerto de métricas del worker |
| `WEBASSISTANT_METRICS_TOKEN` | — | Token Bearer exigido por `/metrics` |
| `WEBASSISTANT_WORKER_METRICS_PORT` | `9100` | Puerto de métricas del worker (vacío para desactivarlo) |
| `WEBASSISTANT_OTEL_ENABLED` | `false` | Trazas OpenTelemetry de API y worker: HTTP, PostgreSQL, Redis, llamadas salientes y spans del agente, modelos, RAG, acciones e ingesta |
| `WEBASSISTANT_OTEL_EXPORTER_ENDPOINT` | — | Endpoint OTLP/HTTP del colector |

## Docker Compose

Además de las anteriores, `compose.yaml` usa: `WEBASSISTANT_PORT`, `POSTGRES_PASSWORD`,
`WEBASSISTANT_DB_NAME`, `WEBASSISTANT_DB_USER`, `WEBASSISTANT_DB_PASSWORD`, `QDRANT_API_KEY`,
`OPENAI_API_KEY`, `OPENAI_BASE_URL`, `WEBASSISTANT_IMAGE`, `EXTRA_CA_CERTS`, `GRAFANA_ADMIN_USER`,
`GRAFANA_ADMIN_PASSWORD`, `PROMETHEUS_PORT`, `GRAFANA_PORT`, `JAEGER_PORT` y, en `compose.prod.yaml`,
`WEBASSISTANT_DOMAIN`, `WEBASSISTANT_TLS`, `ACME_EMAIL`, `HTTP_PORT` y `HTTPS_PORT`. Ver
[.env.example](../.env.example).
