# Guía de integración (CG-19.2)

Cómo integra un portal (Drupal, Liferay, Adobe, SharePoint, WordPress o una web propia) el asistente:
instalación del widget, identidad, acciones en la página, eventos, ingesta de contenidos y búsqueda.
Referencia completa e interactiva de la API: `/docs` (OpenAPI 3.1 en `/v1/openapi.json` y en
[`packages/contracts/openapi/openapi.json`](../packages/contracts/openapi/openapi.json)).

## 1. Requisitos previos

Un administrador crea en la consola: el **site** (identificador público y orígenes permitidos, p. ej.
`https://www.portal.example`), el **assistant** publicado y, para el backend del portal, un **cliente
API** con los permisos necesarios (`token:issue`, `ingest:write`, `ingest:read`, `search:read`).

## 2. Instalar el widget

```html
<script async src="https://asistente.example.com/widget/v1/loader.js"
        data-site-id="portal_publico"
        data-assistant-id="catalogo"></script>
```

| Atributo | Obligatorio | Descripción |
| --- | --- | --- |
| `data-site-id` | Sí | Identificador público del site |
| `data-assistant-id` | No | Assistant a usar (por defecto, el del site) |
| `data-locale` | No | Idioma (`es`, `en`…); por defecto el `lang` del documento |
| `data-result-target` | No | Selector CSS de un contenedor de la página donde mostrar los resultados ricos |
| `data-open` | No | `true` para abrir el chat al cargar |
| `data-position` | No | `left` o `right` (por defecto, el tema del site) |
| `data-token` | No | Token de widget emitido por el backend del portal (ver §3.2) |
| `data-api-base` | No | URL de la API si no coincide con el origen del script |

El loader no tiene dependencias, evita dobles montajes y carga el bundle versionado con integridad
SRI. El chat se monta en la página en un Shadow DOM (no en un iframe): los estilos del portal no lo
afectan y viceversa. Funciona con CSP estrictas si se permite el origen de la API en `script-src`,
`connect-src` e `img-src`.

## 3. Identidad

### 3.1 Visitante anónimo

Sin más integración, el widget obtiene un token anónimo (`POST /v1/sessions`) con los scopes que el site
permite a anónimos (`settings.anonymous_scopes`). El historial se conserva en el navegador mediante un
identificador firmado. Si el site es `private`, el widget muestra el mensaje de inicio de sesión.

### 3.2 Usuario autenticado del portal

El backend del portal (nunca el navegador) pide un token para su usuario:

```http
POST /v1/token
Content-Type: application/json

{
  "grant_type": "widget_token",
  "client_id": "wa_portal_prod",
  "client_secret": "<secreto del cliente API>",
  "site_id": "portal_publico",
  "user": { "id": "u-123", "roles": ["editor"], "groups": ["marketing"], "locale": "es" },
  "scopes": ["knowledge:read", "documents:read", "actions:execute", "catalog:read", "cart:read", "cart:write"],
  "origin": "https://www.portal.example",
  "ttl_seconds": 900
}
```

`user.id` es un identificador opaco (sin datos personales). Los scopes efectivos son la intersección
de los pedidos con los permitidos (`chat:use`, `knowledge:read`, `documents:read`, `actions:execute` y
`settings.allowed_widget_scopes` del site); los no permitidos se descartan sin error y `chat:use` se
añade siempre. Una lista vacía concede todos los permitidos. Sin `knowledge:read` no hay respuestas desde
contenidos y sin `actions:execute` no se ejecuta ninguna acción. La página entrega el token al widget:

```html
<script>
  window.WebAssistant = window.WebAssistant || [];
  // Valor fijo o función que el widget llama al caducar el token (recomendado).
  WebAssistant.push(['setToken', () => fetch('/mi-portal/webassistant-token').then(r => r.json()).then(d => d.token)]);
</script>
```

Al iniciar sesión, las conversaciones anónimas del navegador se vinculan al usuario
(`link_anonymous_on_login`).

### 3.3 Scopes

| Scope | Concede |
| --- | --- |
| `chat:use` | Conversar (siempre incluido) |
| `knowledge:read`, `documents:read` | Respuestas desde contenidos y documentos con ACL `public`/`authenticated`/roles/grupos |
| `catalog:read` | Skills de catálogo y productos |
| `cart:read`, `cart:write` | Ver y modificar el carrito (host actions) |
| `content:write` | Crear y editar contenidos (host actions) |
| `profile:read` | Perfil del usuario |
| `analytics:read` | Informes de ventas |
| `actions:execute` | Ejecutar acciones permitidas por los scopes anteriores |
| `token:issue`, `ingest:write`, `ingest:read`, `search:read` | Solo clientes server-to-server |

Las skills declaran los scopes que necesitan: el assistant solo ofrece al modelo las que el usuario puede
usar y las acciones que su token permite ejecutar.

## 4. API pública de la página (`window.WebAssistant`)

El objeto existe desde que se carga el loader; antes, las llamadas se encolan con `push`.

```js
window.WebAssistant = window.WebAssistant || [];
WebAssistant.push(['registerHostAction', 'cart.add', async (action) => { /* ... */ }]);
WebAssistant.push(['on', 'response', (event) => console.log(event.payload)]);

// Una vez cargado:
WebAssistant.open();                    // abrir el chat
WebAssistant.close();
WebAssistant.send('Enséñame el carrito'); // enviar un mensaje en nombre del usuario
WebAssistant.setToken(tokenOFuncion);   // cambiar de identidad (login/logout)
const off = WebAssistant.on('*', handler); off(); // suscribirse y cancelar
```

Con varias instancias en la misma página (p. ej. con distinto `data-result-target`), los métodos
aceptan el identificador de instancia `site:assistant:target` como último argumento.

### 4.1 Host actions

Las acciones que ejecuta el portal (carrito, formularios, perfil…) llegan con este contrato
([`host-action.v1`](../packages/contracts/schemas/host-action.v1.schema.json)):

```json
{
  "version": "1",
  "name": "cart.add",
  "execution_id": "exe_…",
  "correlation_id": "corr_…",
  "skill": "event_product_selection",
  "action": "add_to_cart",
  "run_id": "run_…",
  "params": { "product_id": "p-1001", "variation_id": "p-1001-v1", "quantity": 2 },
  "expires_at": "2026-10-10T15:00:00Z"
}
```

El handler devuelve `{ status?: 'succeeded' | 'failed', data?: {...}, message?: string }` (o una
promesa). El widget informa del resultado a la API (`POST /v1/actions/{id}/result`), lo anuncia de forma
accesible y lo audita. Si no hay handler, la acción se marca como fallida (`no_handler`). Tiempo máximo:
30 s.

| Host action | Skill | Parámetros | Respuesta esperada (`data`) |
| --- | --- | --- | --- |
| `cart.get` | `shopping_cart_view` (automática) | — | `{ items: ProductItem[], total }` |
| `cart.add` | `shopping_cart_add`, productos, evento | `product_id`, `variation_id?`, `quantity?` | carrito actualizado |
| `cart.add_many` | `event_product_selection` | `items[]` | carrito actualizado |
| `cart.update` | `shopping_cart_view` | `product_id`, `variation_id?`, `quantity` | carrito actualizado |
| `cart.remove` | `shopping_cart_view` (con confirmación) | `product_id`, `variation_id?` | carrito actualizado |
| `cart.checkout` | `payment` | — | — |
| `content.create` | `content_create` | `content_type`, `content_title?` | — |
| `content.edit` | `content_edit` | `content_title`, `content_type?`, `content_id?` | — |
| `user.profile` | `user_profile` | — | — |
| `analytics.sales_report` | `sales_analytics` | `range`, `focus`, `from?`, `to?` | — |

Las acciones con efectos piden confirmación al usuario en un diálogo accesible antes de llegar al
handler. La web de demostración (`demo/demo.js`) contiene implementaciones de referencia.

### 4.2 Eventos

Cada evento ([`widget-events.v1`](../packages/contracts/schemas/widget-events.v1.schema.json)) se entrega
a los suscriptores de `WebAssistant.on(tipo | '*')` y como `CustomEvent` `webassistant:<tipo>` en
`document`:

```json
{ "version": "1", "type": "response", "instance_id": "portal_publico:catalogo:panel",
  "timestamp": "…", "conversation_id": "conv_…", "run_id": "run_…", "correlation_id": null,
  "payload": { "skill": "product_results", "has_presentation": true, "actions": ["add_to_cart"] } }
```

Tipos: `ready`, `open`, `close`, `message_sent` (solo longitud, nunca el texto), `response`,
`presentation`, `action_requested`, `host_action`, `action_result`, `error`, `reset`. Los eventos no
contienen datos personales: son aptos para analítica.

### 4.3 Resultados en la página

Con `data-result-target="#resultados"`, las presentaciones ricas (productos, guías, citas) se muestran
en ese contenedor y el chat ofrece "Ver resultado", que mueve el foco al resultado. El contenido original
del contenedor se oculta y se restaura con "Nuevo chat".

### 4.4 Tema

El tema se configura por site con tokens validados (colores hexadecimales con contraste AA comprobado,
`font_family` entre `system`, `forfuture`, `serif` o `inherit`, `radius`, `position`, `launcher_label`,
`logo_url`, `chat_width`, `z_index`). No se acepta CSS arbitrario.

## 5. API de conversación (clientes propios)

El SDK TypeScript (`@webassistant/sdk`) implementa estas llamadas; cualquier cliente HTTP sirve:

```ts
import { WebAssistantClient } from '@webassistant/sdk';

const client = new WebAssistantClient({ baseUrl: 'https://asistente.example.com', token: async () => miToken() });
const conversation = await client.createConversation('catalogo', 'es');
for await (const event of client.streamMessage(conversation.conversation_id, '¿Cómo devuelvo un pedido?')) {
  if (event.type === 'reply.delta') process.stdout.write(event.delta ?? '');
  if (event.type === 'run.completed') console.log(event.response?.presentation);
}
```

- `POST /v1/conversations/{id}/messages` con `Accept: text/event-stream` emite `run.started`,
  `run.status` (`thinking`, `searching`, `composing`), `reply.delta`, `run.completed` (respuesta
  [`chat-response.v1`](../packages/contracts/schemas/chat-response.v1.schema.json)), `run.failed` o
  `run.cancelled`. Sin esa cabecera devuelve la respuesta JSON completa.
- Reanudación tras una desconexión: `GET .../runs/{run_id}/events` con `Last-Event-ID: <seq>`.
- Cancelación: `POST .../runs/{run_id}/cancel`.
- Acciones: `POST /v1/actions/prepare` → (confirmación) → `POST /v1/actions/{id}/execute` con
  `Idempotency-Key` y `confirmation_token` → `POST /v1/actions/{id}/result` para host actions.

## 6. Ingesta de contenidos (server-to-server)

```ts
import { PortalClient } from '@webassistant/sdk';

const portal = new PortalClient({ baseUrl: 'https://asistente.example.com', clientId: 'wa_portal_prod', clientSecret: process.env.WA_SECRET! });
await portal.ingest([{
  source_id: 'node-42', knowledge_base: 'ayuda', type: 'guide', locale: 'es',
  title: 'Devoluciones y reembolsos', version: '2026-10-01T10:00:00Z',
  text: '# Plazo\nDispones de 30 días…', content_format: 'markdown',
  canonical_url: 'https://www.portal.example/ayuda/devoluciones', acl: ['public'],
}]);
```

Documento canónico ([`canonical-document.v1`](../packages/contracts/schemas/canonical-document.v1.schema.json)):

| Campo | Descripción |
| --- | --- |
| `source_id`, `knowledge_base`, `type`, `title`, `version` | Obligatorios. `version` cambia cuando cambia el contenido |
| `text` + `content_format` | Texto autorizado (`text`, `markdown` o `html`) |
| `reference` | Alternativa a `text`: `download_url`, `media_type` (PDF, DOCX, HTML, texto, Markdown), `access_token` temporal (no se guarda) y `expires_at` |
| `acl` | `public`, `authenticated`, `role:<rol>`, `user:<id>`, `group:<id>` |
| `site_id` | Limita el documento a un site (por defecto, todos los del tenant) |
| `canonical_url`, `locale`, `metadata` | Enlace de las citas, idioma y metadatos (`product_id`, `price_amount`, `currency`, `image_url`, `category`…) |

- Lotes de hasta 100 documentos; la operación es idempotente (misma versión y contenido → sin cambios).
- Estado: `GET /v1/ingest/documents/{kb}/{source_id}` (`queued`, `processing`, `ready`, `failed`).
- Borrado: `DELETE /v1/ingest/documents/{kb}/{source_id}`. ACL: `PUT /v1/ingest/acl/{kb}/{source_id}`.
- Conciliación: `GET /v1/ingest/inventory/{kb}` devuelve `source_id`, versión y hash por páginas.
- Los originales permanecen en el portal; el producto guarda texto extraído y fragmentos.

## 7. Búsqueda server-to-server

`POST /v1/search` (`search:read`) con `query`, `knowledge_bases`, `site_id`, `locale`, `principals`
(ACL del usuario final), `limit` y `min_score`. Devuelve fragmentos con puntuación, título y URL canónica,
siempre filtrados por el tenant del cliente.

<a id="errores"></a>

## 8. Errores

Todas las respuestas de error usan `application/problem+json` ([`problem`](../packages/contracts/schemas/problem.schema.json))
con `code` estable, `retryable`, `user_message` (texto apto para el usuario final) y `correlation_id`:

| Código | HTTP | Significado |
| --- | --- | --- |
| `validation_error` | 422 | Petición inválida (`errors[].path` y `message`) |
| `unauthorized` / `forbidden` | 401 / 403 | Token ausente, caducado o sin permisos |
| `origin_not_allowed`, `site_disabled` | 403 | Origen no registrado o site desactivado |
| `not_found`, `conflict` | 404 / 409 | Recurso inexistente o en conflicto |
| `rate_limited` | 429 | Límite temporal (`Retry-After`) |
| `payload_too_large`, `message_too_long` | 413 / 422 | Tamaño excedido |
| `idempotency_key_required`, `idempotency_conflict` | 428 / 409 | Clave de idempotencia ausente o reutilizada con otro cuerpo |
| `confirmation_required`, `confirmation_invalid` | 428 / 403 | Acción con efectos sin confirmación válida |
| `budget_exceeded`, `policy_violation`, `concurrency_limit` | 402 / 403 / 429 | Políticas del tenant |
| `provider_unavailable`, `provider_timeout`, `provider_quota`, `provider_rate_limited`, `provider_bad_request` | 503 / 504 / 503 / 503 / 502 | Proveedor de modelos |
| `invalid_model_output`, `tool_failed`, `tool_not_allowed`, `iteration_limit` | 502 / 502 / 403 / 422 | Ejecución del agente |
| `skill_unavailable`, `action_unavailable` | 404 / 404 | Skill o acción no disponible |
| `run_cancelled`, `internal_error`, `service_unavailable` | 409 / 500 / 503 | Otros |


<a id="compatibilidad-y-versionado"></a>

## 9. Compatibilidad y versionado

- **API**: prefijo `/v1`. Dentro de una versión solo se añaden endpoints, campos opcionales y códigos de
  error; los clientes deben ignorar campos desconocidos. Un cambio incompatible crea `/v2`, que convive
  con `/v1` al menos 6 meses con aviso en el CHANGELOG y cabecera `Deprecation`.
- **Widget**: `/widget/v1/loader.js` es estable; los bundles con hash son inmutables y se conservan.
  `window.WebAssistant`, host actions (`host-action.v1`) y eventos (`widget-events.v1`) siguen la misma
  política.
- **Presentaciones**: se versionan por tipo (`product_grid@1`); un tipo desconocido se muestra como texto.
- **Skills**: manifiesto `api_version: webassistant.ai/v1` y dependencia `platform: ">=1.0,<2.0"`.
- **Producto**: SemVer; las notas de cada versión están en el [CHANGELOG](../CHANGELOG.md).
