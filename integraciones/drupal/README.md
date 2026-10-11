# Conector Drupal 11 para WebAssistant

Módulo `webassistant_connector` (con las extensiones `webassistant_connector_documents` y
`webassistant_connector_commerce`) que integra un portal **Drupal 11.2+** con la capa genérica
WebAssistant. Implementa la capa Drupal de `capa-drupal.md` (DR-00 a DR-09): Drupal deja de ejecutar
modelos, skills y RAG, y pasa a ser el adaptador que instala el widget, transmite la identidad, sincroniza
los contenidos y ejecuta las acciones del portal.

```
Página Drupal ── bloque/adjunto ──► loader.js ──► widget (Shadow DOM) ──HTTPS──► API WebAssistant
     ▲    │ token del usuario (POST /webassistant/token → POST /v1/token)              │
     │    └ host actions (POST /webassistant/host-action/{name}, con permisos Drupal) ◄─┘
CRUD Drupal ─► outbox (misma transacción) ─► cola/cron/Drush ─► POST /v1/ingest/documents
Originales PDF/DOCX ◄── GET /webassistant/document/{uuid}/{exp}/{firma} ◄── worker de la plataforma
```

| Documento | Contenido |
| --- | --- |
| [Diseño técnico](docs/diseno-tecnico.md) | Arquitectura, contratos, tablas, servicios y decisiones |
| [Operación](docs/operacion.md) | Instalación en un portal, configuración, cron, runbooks y migración |
| [Decisiones (ADR)](docs/adr.md) | Drupal 11, convivencia con `webassistant_ai`, límites de la API v1 |

## Prueba rápida con Docker

Desde la raíz del repositorio:

```bash
docker compose -f compose.yaml -f integraciones/drupal/compose.drupal.yaml up -d --build --wait
```

| URL | Qué es |
| --- | --- |
| <http://localhost:8080> | Drupal 11 con el widget (`admin`/`admin`, `editor`/`editor`) |
| <http://localhost:8080/admin/config/services/webassistant-connector> | Configuración, fuentes y estado del conector |
| <http://localhost:8000/admin/> | Consola de la plataforma (`admin@demo.local` / `Demo-Admin-2026`) |

La primera vez el contenedor instala Drupal (SQLite) en español, aplica la receta
[`webassistant_connector_demo`](recipes/webassistant_connector_demo/recipe.yml), crea contenido de ejemplo
(una página, un artículo con un PDF privado y un borrador) y lo sincroniza. Prueba en el chat:
*«¿Cuál es el horario de la oficina de Zaragoza?»* o *«¿Qué cubre la garantía GAR-DRUPAL-2026?»*.

Prueba extremo a extremo (sincronización, PDF, búsqueda, widget, token, host action y despublicación):

```bash
npx playwright test -c integraciones/drupal/tests/e2e/playwright.config.ts
```

En redes que bloquean drupal.org o las descargas zip de GitHub:
`DRUPAL_PACKAGES=0 DRUPAL_COMPOSER_PREFER_SOURCE=1` (y, tras un proxy, `DRUPAL_BUILD_NETWORK=host
DRUPAL_BUILD_HTTPS_PROXY=$HTTPS_PROXY EXTRA_CA_CERTS=/ruta/ca.crt`).

## Qué hace

| Capacidad | Cómo | DR |
| --- | --- | --- |
| Widget | Colocación global (con visibilidad por rutas) o bloque por región; un loader por instancia, `once()`, sin token en el HTML y caché por permisos, rol autenticado e idioma | DR-02 |
| Identidad | `POST /webassistant/token` (sesión, CSRF, Flood, `no-store`) pide a la plataforma un token de widget con id opaco (HMAC), roles, idioma y scopes derivados de permisos | DR-03 |
| Fuentes | Entidad de configuración por fuente: tipo de entidad, bundles, idiomas, base de conocimiento, roles de campo, modo de ACL y **previsualización** del JSON exacto | DR-04 |
| Sincronización | Outbox transaccional (una fila por entidad e idioma), cola `webassistant_connector_dispatch`, lotes ≤ 100, reintentos con backoff, *dead-letter*, reconciliación con el inventario y estado | DR-05 |
| Acceso | ACL calculada con el acceso real de Drupal (`public`, `authenticated`, `role:*`), campos sin acceso descartados y revisión cuando cambian los permisos | DR-04.7 |
| Host actions | `content.create`, `content.edit`, `user.profile` (base) y carrito/informes (Commerce), con permisos, validación, idempotencia y URLs internas | DR-06 |
| Originales | PDF/DOCX como referencias con URL firmada (HMAC) que solo sirve ficheros todavía indexados | Documents |
| Commerce | Productos y variaciones como documentos de catálogo y carrito real de Drupal Commerce | Commerce |
| Operación | Pantalla de estado, `hook_runtime_requirements`, comandos Drush y migración desde `webassistant_ai` | DR-01.6, DR-07, DR-09 |

## Instalación en un portal

1. Copia `webassistant_connector/` en `web/modules/custom/` (o añádelo como repositorio `path` de
   Composer: el paquete es `minsait/webassistant_connector`).
2. Secretos en `settings.php` (o variables de entorno `WEBASSISTANT_*`), nunca en la configuración:
   ```php
   $settings['webassistant_connector'] = [
     'api_base_url' => 'https://asistente.example.com',     // servidor → API
     'api_public_url' => 'https://asistente.example.com',   // navegador → loader
     'client_id' => 'wa_portal_prod',
     'client_secret' => getenv('WEBASSISTANT_CLIENT_SECRET'),
   ];
   ```
3. `drush en webassistant_connector` (y `webassistant_connector_documents` / `_commerce` si aplica).
4. En la plataforma: site con el origen del portal, bases de conocimiento y cliente API con
   `token:issue`, `ingest:write`, `ingest:read` y `search:read`; si se ingieren originales,
   `WEBASSISTANT_INGEST_ALLOWED_DOWNLOAD_HOSTS` con el host del portal.
5. En Drupal: *Configuración → Servicios web → WebAssistant connector*: site, widget, identidad y fuentes;
   *Probar conexión* y *Estado* validan credenciales, site, origen y cola.
6. `drush webassistant:index --process` para la carga inicial y cron del sistema (o
   `drush queue:run webassistant_connector_dispatch`) para la sincronización continua.

Guía completa, permisos, scopes, runbooks y migración: [docs/operacion.md](docs/operacion.md).

## Calidad

```bash
DRUPAL_ROOT=/ruta/drupal integraciones/drupal/scripts/check.sh all   # phpcs, phpstan, unit, kernel, functional
```

`DRUPAL_ROOT` es una instalación Drupal 11 con `drupal/core-dev` y `drush/drush`. El CI ejecuta lo mismo
(job *Conector Drupal 11*) y la prueba extremo a extremo con Docker Compose.
