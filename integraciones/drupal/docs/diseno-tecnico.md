# Conector Drupal 11 · Diseño técnico

Documento normativo del conector `webassistant_connector` para Drupal 11.2+. Implementa la capa Drupal
descrita en `capa-drupal.md` (DR-00 a DR-09 y extensiones Commerce y Documents) sobre la API v1 de la
capa genérica (`docs/integracion.md`). Los identificadores de código están en inglés; los textos de
interfaz se escriben en inglés en `t()` y se traducen al español en `translations/webassistant_connector.es.po`.

## 1. Principios

1. **Drupal es la fuente de verdad** de contenidos, usuarios, permisos, publicación, carrito y URLs. La
   capa genérica ejecuta el assistant, las skills y el RAG; Drupal no ejecuta modelos ni embeddings.
2. **Secretos solo en servidor.** El secreto del cliente API vive en `settings.php` o en variables de
   entorno, nunca en configuración exportable ni en el navegador. Los tokens de widget son temporales.
3. **Nunca HTTP durante el guardado de entidades.** Los cambios se registran en una *outbox* dentro de la
   transacción y se envían en segundo plano (cola/cron/Drush), con reintentos y *dead-letter*.
4. **Los scopes no sustituyen permisos.** Cada host action vuelve a comprobar permisos y acceso a
   entidades en Drupal, valida parámetros y es idempotente.
5. **Caché segura.** Nada que dependa del usuario se cachea sin el contexto adecuado; el token jamás se
   incrusta en HTML.
6. **Base sin dependencias opcionales.** El módulo base instala con core (sin Commerce, Media ni Key);
   Documents y Commerce son submódulos.

## 2. Paquetes

```
integraciones/drupal/
  webassistant_connector/                 Módulo base (composer: minsait/webassistant_connector)
    modules/webassistant_connector_documents/   Originales PDF/DOCX por referencia firmada
    modules/webassistant_connector_commerce/    Productos y carrito de Drupal Commerce
  recipes/webassistant_connector_demo/    Receta Drupal 11 para la demo (módulos, config, permisos)
  docker/                                 Imagen y arranque de Drupal para desarrollo y e2e
  compose.drupal.yaml                     Override de Compose: Drupal + plataforma
  tests/e2e/                              Prueba extremo a extremo (Playwright + API)
  docs/                                   Este diseño y guías
```

`core_version_requirement: ^11.2` (hooks OOP con `#[Hook]`, `RequirementSeverity`, `runtime_requirements`).
PHP ≥ 8.3. Todas las clases PHP: `declare(strict_types=1);`, `final` salvo bases/plugins extensibles,
promoción de propiedades `private readonly`, inyección de dependencias (sin `\Drupal::` en clases).

## 3. Configuración (`webassistant_connector.settings`)

```yaml
enabled: true                    # Interruptor global (rollback sin desinstalar)
api:
  base_url: 'http://localhost:8000'   # URL server-to-server de la API (red interna)
  public_url: ''                      # URL de la API para el navegador (loader); vacío = base_url
  timeout: 10                         # segundos
  connect_timeout: 5
site_id: 'site_demo'             # id público del site en la plataforma
tenant_id: ''                    # informativo (diagnóstico)
assistant_id: ''                 # assistant por defecto (vacío = el del site)
client_id: ''                    # id del cliente API (no secreto; puede venir de settings.php)
widget:
  placement: global              # global | block
  position: ''                   # '' | left | right
  open: false
  result_target: ''              # selector CSS del contenedor de resultados ('' = panel)
  pass_locale: true              # envía data-locale con el idioma de interfaz
  visibility:
    exclude_admin_routes: true
    pages: ''                    # patrones de ruta, uno por línea (sintaxis de path.matcher)
    negate: true                 # true: oculta en las rutas listadas; false: solo en ellas
  analytics_datalayer: false     # publica eventos del widget en window.dataLayer (sin datos personales)
identity:
  authenticated: true            # emite tokens de widget para usuarios con sesión
  token_ttl: 900                 # 60..3600
  send_origin: true              # liga el token al origen de la página
  send_roles: true
  base_scopes: ['chat:use', 'knowledge:read', 'documents:read', 'actions:execute']
  scope_permissions:             # scope → permiso Drupal que lo concede
    - { scope: 'catalog:read', permission: 'access content' }
    - { scope: 'cart:read', permission: 'use webassistant cart actions' }
    - { scope: 'cart:write', permission: 'use webassistant cart actions' }
    - { scope: 'content:write', permission: 'use webassistant content actions' }
    - { scope: 'profile:read', permission: 'use webassistant profile actions' }
    - { scope: 'analytics:read', permission: 'use webassistant analytics actions' }
  rate_limit: 30                 # tokens por usuario y minuto (Flood)
sync:
  enabled: true
  source_id_prefix: 'drupal'     # prefijo de source_id remoto: drupal:node:42:es (único por instalación)
  public_base_url: ''            # base absoluta de canonical_url (obligatoria en CLI/cron si difiere)
  default_locale: 'es'           # locale para entidades sin idioma (und/zxx)
  batch_size: 50                 # documentos por petición de ingesta (≤100)
  max_attempts: 8
  process_limit: 200             # eventos por ejecución de cola/cron
  reconcile_interval: 86400      # segundos (0 = desactivada)
  retention_days: 7              # días que se conservan eventos completados
```

### 3.1 Secretos (`Credentials`)

Orden de resolución, de más a menos prioritario:

| Valor | `settings.php` | Variable de entorno | Config |
| --- | --- | --- | --- |
| `client_id` | `$settings['webassistant_connector']['client_id']` | `WEBASSISTANT_CLIENT_ID` | `client_id` |
| `client_secret` | `$settings['webassistant_connector']['client_secret']` | `WEBASSISTANT_CLIENT_SECRET` | — (nunca) |
| `signing_key` | `$settings['webassistant_connector']['signing_key']` | `WEBASSISTANT_SIGNING_KEY` | — (derivada de `hash_salt`) |
| `api.base_url` | `$settings['webassistant_connector']['api_base_url']` | `WEBASSISTANT_API_URL` | `api.base_url` |
| `api.public_url` | `$settings['webassistant_connector']['api_public_url']` | `WEBASSISTANT_API_PUBLIC_URL` | `api.public_url` |

La clave de firma por defecto es `hash_hmac('sha256', 'webassistant_connector', Settings::getHashSalt())`.
El estado nunca muestra secretos: solo "configurado (settings.php | entorno)" o "falta".

## 4. Cliente de plataforma (DR-01.1)

`Drupal\webassistant_connector\Platform\PlatformClientInterface` (servicio
`webassistant_connector.platform_client`, alias autowire por interfaz):

```php
public function issueWidgetToken(WidgetTokenRequest $request): TokenResult;
public function serverToken(): TokenResult;                       // client_credentials, caché en memoria hasta exp-30s
public function ingest(array $documents): array;                  // list<array> de items de POST /v1/ingest/documents (202)
public function deleteDocument(string $knowledgeBase, string $sourceId): array;
public function updateAcl(string $knowledgeBase, string $sourceId, array $acl): array;
public function getDocument(string $knowledgeBase, string $sourceId): ?array;   // NULL si 404
public function inventory(string $knowledgeBase, ?string $cursor = NULL, int $limit = 500): array; // {items, next_cursor}
public function search(array $query): array;                      // {items}
public function health(): array;                                  // GET /health/ready
public function bootstrap(string $origin): array;                 // GET /v1/bootstrap?site_id=… con cabecera Origin
```

- Guzzle desde `http_client_factory` con `base_uri`, `timeout`, `connect_timeout`, `http_errors => FALSE`.
- Cabeceras: `Accept: application/json`, `User-Agent: WebAssistantDrupalConnector/1.0 (Drupal <versión>)`,
  `X-Request-Id: <uuid v4>` (correlación, se devuelve en la excepción y se guarda en la outbox).
- `/v1/token` usa el cuerpo JSON con `client_id`/`client_secret`; ingesta y búsqueda usan
  `Authorization: Bearer <serverToken>`; si recibe 401 renueva el token una vez y reintenta.
- Los segmentos de ruta (`knowledge_base`, `source_id`) se codifican con `rawurlencode` salvo `/` y `:`.
- Errores: `PlatformException` (extiende `\RuntimeException`) con `getStatus(): ?int`,
  `getErrorCode(): string` (`code` del problem+json o `network_error`/`invalid_response`),
  `isRetryable(): bool` (red, 408, 425, 429, 5xx o `retryable: true`), `getRetryAfter(): ?int`
  (cabecera `Retry-After`), `getCorrelationId(): ?string`, `getErrors(): array` (`errors[]`). El mensaje
  de la excepción nunca contiene secretos ni cuerpos completos.
- VO `WidgetTokenRequest` (readonly): `userId`, `roles` (list), `groups` (list), `locale` (?string),
  `scopes` (list), `origin` (?string), `ttl` (int), `assistantId` (?string). `TokenResult` (readonly):
  `accessToken` (#[\SensitiveParameter] en el constructor), `expiresAt` (int, timestamp), `scopes` (list).

## 5. Identidad y token de widget (DR-03)

- `Identity\IdentityMapper`:
  - `userId(AccountInterface $account): string` → `'d' . substr(hash_hmac('sha256', site_id . ':' . uid, signing_key), 0, 40)`
    (opaco, estable por site, sin datos personales, válido para el patrón ACL `[A-Za-z0-9_.@-]+`).
  - `roles(AccountInterface $account): array` → ids de rol del usuario sin `anonymous`/`authenticated`.
  - `locale(): string` → idioma de interfaz mapeado con `Locale\LocaleMapper`.
- `Identity\ScopeResolver::resolve(AccountInterface $account): array` → `base_scopes` ∪ scopes cuyo permiso
  tiene el usuario (ordenados, sin duplicados). Los permisos inexistentes no conceden nada.
- `Event\WidgetTokenEvent` (despachado antes de emitir): permite a otros módulos ajustar `groups`, `roles`
  y `scopes` (p. ej. el módulo Group). Getters/setters tipados.
- `Identity\WidgetTokenManager::getToken(AccountInterface $account, Request $request): TokenResult`:
  reutiliza el token guardado en la sesión (`webassistant_connector.token`: token, expiración, huella de
  uid+scopes+roles+origen) si quedan más de 60 s; si no, aplica Flood (`webassistant_connector.token`,
  `identity.rate_limit`/min por usuario; excedido → `TooManyRequestsHttpException` con `Retry-After`),
  construye la petición y llama a la plataforma.
- Ruta `webassistant_connector.token`: `POST /webassistant/token`, requisitos `_user_is_logged_in: 'TRUE'`,
  `_permission: 'use webassistant connector'`, `_csrf_request_header_token: 'TRUE'`, opción
  `no_cache: TRUE`. Respuesta `200 {"token": "...", "expires_at": "<ISO 8601>"}` con
  `Cache-Control: no-store, private` y `Pragma: no-cache`. Errores: 403 (sin permiso o identidad
  desactivada), 429 (Flood), 503 `{"error":"unavailable"}` si la plataforma falla (se registra en el log
  con el `correlation_id`, sin detalles al navegador).
- El origen enviado es `$request->getSchemeAndHttpHost()` si `identity.send_origin`.
- Anónimos (DR-03.6): el widget obtiene su propia sesión anónima (`POST /v1/sessions`). Si el site es
  privado, el widget muestra el aviso de inicio de sesión.

## 6. Widget (DR-02)

- Librería `webassistant_connector/widget` (`js/webassistant-connector.js`, dependencias `core/drupal`,
  `core/drupalSettings`, `core/once`). Behavior `webassistantConnector` con `once()` sobre `html` y clave
  por instancia: inserta **un** `<script async src="{public_url}/widget/v1/loader.js" data-site-id …>`
  por instancia (`data-assistant-id`, `data-locale`, `data-result-target`, `data-open`, `data-position`,
  `data-api-base`). Reattach (AJAX/BigPipe) no duplica nada.
- `drupalSettings.webassistantConnector`:
  ```js
  {
    loaderUrl, apiBase, siteId,
    tokenUrl,            // solo si el usuario puede pedir token; si no, null
    csrfTokenUrl,        // '/session/token' (con base path)
    hostActionUrl,       // '/webassistant/host-action/' (prefijo)
    hostActions: [],     // nombres permitidos para el usuario (registro de plugins)
    dataLayer: false,
    instances: { "<clave>": { assistantId, locale, resultTarget, open, position } }
  }
  ```
- Token: si `tokenUrl`, registra `setToken(fn)`; `fn` hace `POST tokenUrl` con `X-CSRF-Token` (obtenido
  de `csrfTokenUrl` y cacheado en memoria) y devuelve `token`. Si `tokenUrl` falta pero
  `sessionStorage['webassistant_connector.auth']` indica una sesión previa (logout), limpia las claves
  `wa:*` de `sessionStorage`/`localStorage` antes de cargar el loader.
- Host actions: para cada nombre de `hostActions`, `registerHostAction(name, handler)`; el handler hace
  `POST hostActionUrl + name` con el objeto host action en JSON, `credentials: 'same-origin'`,
  `X-CSRF-Token` y `X-WebAssistant-Request: 1`; devuelve `{status, data, message}`; si la respuesta trae
  `redirect` (URL del mismo origen) navega tras devolver el resultado. Respuestas no 2xx → `{status:'failed'}`.
- Llamadas a `window.WebAssistant` con el patrón de cola (`WebAssistant = WebAssistant || []` + `push`).
- `dataLayer` (opcional): `WebAssistant.on('*')` → `window.dataLayer.push({event: 'webassistant_<tipo>',
  webassistant: {type, instance_id, skill?, action?, status?}})` sin parámetros ni datos personales.
- Colocación global: `#[Hook('page_attachments')]` adjunta la instancia por defecto si `enabled`,
  `widget.placement === 'global'`, no es ruta de administración (si se excluyen) y la ruta cumple
  `visibility`. Metadatos de caché: tag `config:webassistant_connector.settings`, contextos
  `url.path`, `user.permissions`, `languages:language_interface`, `route.name`.
- Bloque `webassistant_connector_widget` (`#[Block]`, categoría "Minsait"): configuración por instancia
  (`assistant_id`, `result_target`, `render_result_container`, `position`, `open`); renderiza un punto de
  montaje `<div class="webassistant-connector" data-webassistant-instance="…">` y, si se pide, el
  contenedor de resultados `<div id="…" class="webassistant-connector__results" tabindex="-1">`. Mismos
  contextos de caché que la colocación global.
- `Widget\WidgetAttachmentBuilder::build(array $instance = []): array` construye `#attached` y `#cache`
  para ambos casos (una sola fuente de verdad). `isVisibleOnCurrentRoute(): bool`.
- CSP (DR-02.6): documentar `script-src`, `connect-src` e `img-src` con el origen de la API.

## 7. Host actions (DR-06)

- Atributo de plugin `Drupal\webassistant_connector\Attribute\HostAction(id, label, description = NULL,
  permission = NULL, effects = FALSE)`; plugins en `src/Plugin/WebAssistant/HostAction`; gestor
  `HostAction\HostActionManager` (servicio `plugin.manager.webassistant_connector.host_action`, hook de
  alteración `webassistant_host_action_info`).
- `HostAction\HostActionInterface`:
  ```php
  public function access(AccountInterface $account): AccessResultInterface;   // permiso + condiciones del plugin
  public function execute(HostActionRequest $request, AccountInterface $account): HostActionResult;
  ```
  `HostAction\HostActionPluginBase` (abstracta, `ContainerFactoryPluginInterface`) ofrece helpers de
  validación: `stringParam($params, 'name', required, maxLength)`, `intParam(...)`, etc. que lanzan
  `HostActionException` (mensaje seguro para el usuario).
- VO `HostActionRequest::fromArray(array $payload)`: valida `version === '1'`, `name`, `execution_id`
  (`^[A-Za-z0-9_.:-]{1,128}$`), `correlation_id`, `skill`, `action`, `run_id`, `params` (array) y
  `expires_at` (ISO; caducado con 30 s de tolerancia → rechazo). VO `HostActionResult` con constructores
  `succeeded(array $data = [], ?string $message = NULL, ?string $redirect = NULL)` y `failed(string
  $message, array $data = [])`, y `toArray()` (omite nulos).
- `HostAction\HostActionDispatcher::dispatch(string $name, array $payload, AccountInterface $account,
  Request $request): HostActionResult`: plugin existente (404), nombre coincidente, acceso (403),
  idempotencia con `keyvalue.expirable` (`webassistant_connector.host_action`, 24 h; clave
  `sha256(uid|session-id-si-anónimo|name|execution_id)`; repetición → mismo resultado con
  `data.replayed = true`), bloqueo `lock` por clave (concurrente → 409), registro en log con
  `correlation_id`. Excepciones de validación → `failed` con mensaje seguro.
- `HostAction\HostActionRegistry::availableFor(AccountInterface $account): array` → nombres permitidos
  (para `drupalSettings`).
- Ruta `webassistant_connector.host_action`: `POST /webassistant/host-action/{name}`, requisitos
  `name: '[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+'`, `_custom_access` (permiso
  `use webassistant connector` + cabecera `X-WebAssistant-Request: 1` + `Origin`/`Referer` del mismo host
  si vienen), `_csrf_request_header_token: 'TRUE'`, `_format: json`… (el cuerpo es JSON). Respuesta 200
  con `HostActionResult::toArray()`; 400 cuerpo inválido; 403; 404; 409.
- Plugins del módulo base (requieren `node` instalado para las de contenido; si no, `access()` deniega):
  | id | Parámetros | Comportamiento |
  | --- | --- | --- |
  | `content.create` | `content_type` (≤128), `content_title?` | Resuelve el tipo por id o etiqueta entre los que el usuario puede crear; `redirect` a `node.add` |
  | `content.edit` | `content_title` (≤300), `content_type?`, `content_id?` | Busca nodos con `accessCheck(TRUE)`; uno con acceso `update` → `redirect` al formulario; varios → `failed` con candidatos `{title,url}` |
  | `user.profile` | — | Autenticado con acceso → `redirect` a `entity.user.edit_form`; anónimo → login con `destination` |

## 8. Fuentes y normalización (DR-04)

### 8.1 Entidad de configuración `webassistant_source`

Clase `Entity\Source` (`config_prefix: source`, admin `administer webassistant connector`, list builder,
formularios `add`/`edit`/`delete`/`preview`, rutas con `AdminHtmlRouteProvider`). Propiedades:

```yaml
id: articles_es
label: 'Artículos'
status: true
entity_type: node
bundles: [article, page]          # vacío = todos
langcodes: []                     # vacío = todos los idiomas
knowledge_base: ayuda             # ^[a-z][a-z0-9_-]{1,62}$
document_type: guide              # ^[a-z][a-z0-9_]{1,40}$
normalizer: content_entity        # plugin
acl_mode: access                  # access | public | authenticated
site_scoped: false                # true: envía site_id = config site_id
mapping_version: 1
fields:                           # roles de campo (DR-04.3)
  - { field: title, role: title }
  - { field: body, role: main_content }
  - { field: field_tags, role: metadata, key: tags, depth: 1 }
  - { field: field_image, role: image }
  - { field: changed, role: date }
  - { field: field_private, role: exclusion }
third_party_settings: {}          # p. ej. webassistant_connector_documents
```

Roles: `title`, `main_content`, `context`, `metadata`, `filter`, `url`, `image`, `date`, `exclusion`.
`SourceInterface` expone getters tipados, `appliesTo(EntityInterface $entity): bool`,
`getFieldsByRole(string $role): array`, `getMappingHash(): string` (sha256 de la configuración relevante)
y `getAttachmentSettings(string $module): array` (atajo a third-party settings).

El formulario descubre campos con `EntityFieldManager` (DR-04.2), lista solo tipos compatibles, avisa de
campos sensibles (email, teléfono, contraseña, IP, campos de usuario) y valida claves y patrones. La
pestaña **Previsualizar** (DR-04.8) muestra el JSON canónico exacto de una entidad elegida.

### 8.2 Normalizadores

- Atributo `Attribute\Normalizer(id, label, description = NULL, entity_types = [])`; plugins en
  `src/Plugin/WebAssistant/Normalizer`; gestor `plugin.manager.webassistant_connector.normalizer`.
- `Normalizer\NormalizerInterface::normalize(ContentEntityInterface $translation, SourceInterface $source,
  NormalizationContext $context): array` → `list<CanonicalDocument>` (el documento principal primero);
  `applies(string $entityTypeId): bool`.
- Contribuyentes adicionales (submódulos): servicios etiquetados `webassistant_connector.document_contributor`
  que implementan `Normalizer\DocumentContributorInterface` (`applies(SourceInterface $source): bool`,
  `contribute(ContentEntityInterface $translation, SourceInterface $source, NormalizationContext $context,
  CanonicalDocument $parent): array`).
- `content_entity` (por defecto): título del rol `title` (o `label()`); texto HTML con `<h1>` del título y,
  por cada campo `main_content`/`context`, su valor procesado (`processed` en textos con formato, escapado
  en cadenas, etiquetas de referencias con profundidad ≤ 2 y detección de ciclos); `metadata` con las
  claves de `metadata`/`filter` (escalares o listas de etiquetas); `image` → `metadata.image_url`
  absoluta; `date` → `updated_at`; `exclusion` (booleano verdadero) → no indexable.
- VO `Normalizer\CanonicalDocument` (inmutable con `with*()`): `sourceId`, `knowledgeBase`, `type`,
  `title`, `version`, `locale`, `text`, `contentFormat`, `reference`, `canonicalUrl`, `acl`, `siteId`,
  `metadata`, `contentHash`, `createdAt`, `updatedAt`, `attachment` (uuid de fichero, solo local).
  `toArray(): array` produce exactamente `canonical-document.v1` (omite nulos y `attachment`).
- VO `Normalizer\NormalizationContext`: `acl`, `locale`, `langcode`, `baseSourceId`, `version`,
  `canonicalUrl`, `preview` (bool) y un registro de entidades visitadas.

### 8.3 Reglas comunes (`Sync\DocumentBuilder`)

`build(SourceInterface $source, string $entityTypeId, int|string $entityId, string $langcode, bool
$preview = FALSE): DocumentBuildResult` (`documents`, `skipReason`):

1. Carga la **revisión por defecto** (DR-04.6); si no existe → `skipReason: deleted`.
2. Toma la traducción `langcode` (si falta → `translation_missing`); idiomas fuera de `langcodes` → skip.
3. `EntityPublishedInterface` no publicado → `unpublished`. Campo `exclusion` verdadero → `excluded`.
4. ACL (`Sync\AclResolver`, DR-04.7): `acl_mode=public` → `['public']`; `authenticated` →
   `['authenticated']`; `access` → `['public']` si el anónimo puede ver; si no, `['authenticated']` si
   un autenticado sin roles extra puede; si no, `role:<rid>` de cada rol que puede ver (cuentas
   `UserSession` sintéticas, `resetCache()` del access handler entre comprobaciones). Vacío → `no_access`.
5. `source_id = {prefix}:{entity_type}:{id}:{langcode}` (`Sync\SourceIdGenerator`).
6. `locale` con `Locale\LocaleMapper` (`es`, `pt-br`→`pt-BR`, `zh-hans`→`zh`, `und`/`zxx`→`default_locale`).
7. `version = {changed YmdHis}.r{revision}.m{mapping_version}` (≤64). `content_hash = sha256(text)`.
8. `canonical_url` absoluta con `sync.public_base_url` si existe (`Sync\UrlResolver`).
9. `metadata.drupal = {entity_type, bundle, id, uuid, langcode, source, mapping: getMappingHash()[0:12]}`.
10. `site_id` si `site_scoped`. Después, normalizador + contribuyentes.

## 9. Outbox, cola e ingesta (DR-05)

### 9.1 Tablas (`hook_schema` en `.install`)

`webassistant_connector_outbox`:

| Columna | Tipo | Nota |
| --- | --- | --- |
| `id` | serial | PK |
| `event_id` | varchar(36) | UUID |
| `operation` | varchar(16) | `upsert` \| `delete` |
| `source` | varchar(64) | id de la fuente |
| `entity_type`, `entity_id`, `entity_uuid` | varchar(64/64/36) | |
| `langcode` | varchar(12) | |
| `revision_id` | varchar(32) | informativo |
| `status` | varchar(16) | `pending` \| `processing` \| `done` \| `failed` \| `dead` |
| `attempts` | int | |
| `next_attempt` | int | timestamp |
| `last_error` | varchar(1024) | sin secretos |
| `correlation_id` | varchar(64) | `X-Request-Id` |
| `remote` | text (JSON) | resultado remoto resumido |
| `created`, `changed` | int | |

Índices: `(status, next_attempt)`, `(source, entity_type, entity_id, langcode)`.

`webassistant_connector_document` (lo que se envió por entidad y traducción, DR-05.7):

| Columna | Tipo |
| --- | --- |
| `knowledge_base` + `remote_id` | varchar(64) + varchar(256), PK compuesta |
| `source`, `entity_type`, `entity_id`, `langcode` | |
| `attachment` | varchar(64) nullable (uuid de fichero) |
| `version`, `content_hash` | varchar(64) |
| `acl` | text (JSON) |
| `remote_status` | varchar(16) |
| `synced` | int |

### 9.2 Captura (DR-05.1–05.3, 05.7)

`Hook\SyncHooks` con `#[Hook]` para `entity_insert`, `entity_update`, `entity_delete`,
`entity_translation_insert`, `entity_translation_delete` y `cron`. Para cada `ContentEntityInterface`
con fuentes activas (`Sync\SourceMatcher::forEntity()`):

- insert/update de la revisión por defecto → `upsert` de cada traducción afectada (en update, todas las
  traducciones de la entidad); revisiones no por defecto (borradores) se ignoran.
- delete → `delete` de cada traducción (con los ids remotos del mapa).
- translation_delete → `delete` de ese idioma.

`Sync\Outbox::record(string $operation, SourceInterface $source, ContentEntityInterface $entity, string
$langcode)` **coalesce**: si existe un evento `pending`/`failed` de la misma clave, lo actualiza (operación,
revisión, `attempts = 0`, `next_attempt = now`); si no, inserta. Se ejecuta dentro de la transacción de la
entidad (si el guardado se revierte, el evento también). Después encola un ticket en la cola
`webassistant_connector_dispatch` si no hay ninguno pendiente.

### 9.3 Procesado (DR-05.4–05.6, 05.8)

`Sync\SyncProcessor::process(int $limit, int $timeBudget): SyncReport` (cola, cron, Drush y lote):

1. Reclama eventos `pending`/`failed` vencidos (`status = processing`, transacción con bloqueo `lock`).
2. `upsert`: `DocumentBuilder::build()`; si hay `skipReason`, se convierte en `delete` de los documentos
   del mapa; si no, se envían los documentos (lotes ≤ `batch_size`) y se eliminan los del mapa que ya no
   se generan (p. ej. adjunto retirado).
3. `delete`: `DELETE` de cada documento del mapa (404/`unchanged` es éxito) y borrado del mapa.
4. Resultado por ítem: `queued/ready/...` → mapa actualizado y evento `done`; `rejected` → `dead` con
   errores; `PlatformException` reintentable → `failed`, `next_attempt = now + min(3600, 30·2^attempts)`
   o `Retry-After`; no reintentable (401/403/422) o `attempts ≥ max_attempts` → `dead`.
5. Registra latencia (`changed - created`) y `correlation_id`.

### 9.4 Indexación inicial, reconciliación y estado (DR-05.9–05.11)

- `Sync\Indexer::enqueue(SourceInterface $source, ?string $bundle, ?string $langcode, int $lastId, int
  $limit): array{count, last_id}` paginado por id (Batch API y Drush).
- `Sync\Reconciler::reconcile(string $knowledgeBase, bool $dryRun = FALSE): array` (informe): recorre el
  inventario remoto (`cursor`); solo considera `source_id` con el prefijo propio. Huérfanos (no
  corresponden a una entidad/traducción indexable) → `delete`; versión distinta, ACL distinta o estado
  `failed` → `upsert`; locales sin remoto → `upsert`. Cron la lanza cada `reconcile_interval` por base de
  conocimiento mediante la cola `webassistant_connector_reconcile`.
- `Sync\SyncStatus`: conteos por estado, último éxito, latencia media (24 h), errores recientes.

### 9.5 Colas

- `webassistant_connector_dispatch` (`#[QueueWorker(cron: ['time' => 60])]`): procesa la outbox.
- `webassistant_connector_reconcile` (`cron: ['time' => 120]`): reconcilia una base de conocimiento.

## 10. Administración, diagnóstico y Drush (DR-01.6, DR-05.11, DR-09)

Rutas (`/admin/config/services/webassistant-connector`, permiso `administer webassistant connector`):

| Ruta | Pantalla |
| --- | --- |
| `webassistant_connector.settings` | Conexión, site, widget (pestaña por defecto) |
| `webassistant_connector.identity` | Identidad y mapa permisos → scopes |
| `webassistant_connector.sync_settings` | Sincronización |
| `entity.webassistant_source.collection` (`/sources`) | Fuentes (+ añadir, editar, borrar, previsualizar) |
| `webassistant_connector.status` (`/status`) | Diagnóstico y outbox: conectividad, credenciales, site/origen (bootstrap), cola, últimos errores, botones *Procesar ahora*, *Reintentar fallidos*, *Reconciliar*, *Indexar fuente* (Batch API) |

`#[Hook('runtime_requirements')]`: credenciales, URL https fuera de localhost, eventos `dead` y atrasos.

Drush 13 (`src/Drush/Commands/WebAssistantCommands.php`, `AutowireTrait`):

| Comando | Alias | Uso |
| --- | --- | --- |
| `webassistant:status` | `wa-status` | Estado de conexión y outbox (`--format=json`) |
| `webassistant:check` | `wa-check` | Diagnóstico completo (salida ≠ 0 si falla algo) |
| `webassistant:index [source]` | `wa-index` | Indexación inicial (`--bundle`, `--langcode`, `--process`) |
| `webassistant:sync` | `wa-sync` | Procesa la outbox (`--limit`, `--time`) |
| `webassistant:reconcile [kb]` | `wa-reconcile` | Reconciliación (`--dry-run`) |
| `webassistant:retry` | `wa-retry` | Pasa a `pending` los `failed`/`dead` (`--status`, `--id`) |
| `webassistant:preview <entity_type> <id> [langcode]` | `wa-preview` | JSON canónico |
| `webassistant:migrate-legacy` | `wa-migrate-legacy` | DR-07: migra ajustes y bloques de `webassistant_ai` (`--rollback`) |

## 11. Extensión Documents (`webassistant_connector_documents`)

- Dependencias: `webassistant_connector`, `drupal:file` (Media opcional).
- Ajustes third-party en la fuente: `enabled`, `fields` (campos `file`/`image`/referencias a `media`),
  `knowledge_base` (vacío = la de la fuente), `mime_types` (PDF, DOCX, HTML, texto, Markdown),
  `max_bytes` (50 MB), `url_ttl` (604800 s), `download_base_url` (URL con la que la plataforma alcanza
  Drupal; vacío = `sync.public_base_url`).
- `Contributor\AttachmentContributor` genera un documento por fichero: `source_id =
  {base}:file:{uuid}`, `type: document`, título (etiqueta del media, descripción o nombre), `reference`
  `{download_url, media_type, expires_at}`, `version = {changed}.m{mapping}`, `content_hash =
  sha256(uuid|changed|size)` (estable: no se re-descarga si no cambia), `canonical_url` = la de la
  entidad que lo publica (las citas nunca usan la URL firmada), ACL de la entidad, `metadata`
  `{file_name, mime_type, size, parent_title, parent_url}`, `attachment = uuid`.
- `Download\SignedUrlGenerator`: `/webassistant/document/{uuid}/{expires}/{signature}` con
  `signature = base64url(hmac_sha256("{uuid}|{expires}|{changed}", signing_key))`. La plataforma elimina
  la query, por eso la firma va en la ruta.
- `Controller\DownloadController`: valida firma (`hash_equals`) y caducidad, que el fichero sea
  permanente y siga indexado en el mapa (`attachment`), y responde 200 con el binario
  (`BinaryFileResponse`/stream), `Cache-Control: no-store`, `X-Robots-Tag: noindex`,
  `Content-Disposition: attachment`. 403 firma inválida o caducada; 404 fichero retirado. Sin sesión ni
  redirecciones.
- Hooks: `file_update`, `media_update`, `media_delete` → `upsert` de las entidades que los referencian
  en fuentes con adjuntos; `form_webassistant_source_form_alter` añade los ajustes.

## 12. Extensión Commerce (`webassistant_connector_commerce`)

- Dependencias: `webassistant_connector`, `commerce:commerce_product`, `commerce:commerce_cart`,
  `commerce:commerce_order`, `commerce:commerce_store`, `commerce:commerce_price`.
- Normalizador `commerce_product`: un documento `product` por producto y traducción; texto con título,
  descripción y variaciones (SKU, atributos, precio); `metadata` `{product_id, variation_id (variación por
  defecto), sku, price (formateado con `commerce_price.currency_formatter`), price_amount, currency, url,
  image_url, image_alt, category, brand, store_name}` (claves y campos configurables por rol).
- `Cart\CartService` (único punto de verdad, recalcula en Drupal): `resolveVariation(string $productId,
  ?string $variationId): ProductVariationInterface` (publicada, del producto, accesible; SKU como
  alternativa), `resolveStore()`, `add()`, `update()`, `remove()`, `view(AccountInterface): array`
  (`{items: ProductItem[], total, items_count}` con los campos de `product_item`), `checkoutUrl()`.
- Host actions: `cart.get`, `cart.add`, `cart.add_many` (≤100, informe parcial), `cart.update`
  (cantidad 0 = eliminar), `cart.remove`, `cart.checkout` (`commerce_checkout.form` del carrito o la página
  del carrito) y `analytics.sales_report` (con `access commerce administration pages`: `redirect` a la
  administración de pedidos). Permiso `use webassistant cart actions` (y `use webassistant analytics
  actions` para informes). Todas devuelven el carrito real recalculado.
- Commerce no se instala en el entorno de verificación: las pruebas usan *stubs* de las interfaces de
  Commerce (`tests/stubs`) comprobadas contra el código fuente de Commerce.

## 13. Permisos

| Permiso | Uso |
| --- | --- |
| `administer webassistant connector` (restrict access) | Configuración, fuentes, estado y Drush |
| `use webassistant connector` | Identidad autenticada (token) y host actions |
| `use webassistant content actions` | Scope `content:write` y acciones de contenido |
| `use webassistant profile actions` | Scope `profile:read` y `user.profile` |
| `use webassistant cart actions` (Commerce) | Scopes `cart:read`/`cart:write` y acciones de carrito |
| `use webassistant analytics actions` (Commerce) | Scope `analytics:read` |

## 14. Migración desde `webassistant_ai` (DR-07)

Ambos módulos pueden convivir. `drush webassistant:migrate-legacy` copia el selector de resultados y la
posición, crea bloques `webassistant_connector_widget` en las mismas regiones y con la misma visibilidad
que los bloques `webassistant_ai`, y **desactiva** (no borra) los bloques antiguos; `--rollback` revierte.
`enabled: false` desactiva el conector sin desinstalar.

## 15. Limitaciones conocidas de la API v1

- No existe un campo de contexto de página (nodo o producto actual) en la API de conversación: el
  conector transmite idioma, roles y scopes, pero no el contexto (DR-06.7 queda pendiente de la API).
- Las host actions no llevan firma de la plataforma: Drupal trata el payload como no confiable y revalida
  todo (permisos, acceso, parámetros, idempotencia).
- La plataforma descarga los originales con la URL firmada sin query; los reintentos manuales o la
  reindexación posterior a la caducidad fallan hasta que la reconciliación reenvía el documento con una
  URL nueva (los `failed` se reenvían automáticamente).
