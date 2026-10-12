# Conector Drupal 11 · Instalación y operación

Guía para administradores y operación (DR-09.4, DR-09.5). El diseño está en
[diseno-tecnico.md](diseno-tecnico.md) y las decisiones en [adr.md](adr.md).

## 1. Requisitos

| Componente | Versión / condición |
| --- | --- |
| Drupal | 11.2 o superior (PHP 8.3+), con cron del sistema |
| Plataforma WebAssistant | API v1 accesible desde el servidor Drupal (server-to-server) y desde los navegadores (widget) |
| Módulos opcionales | `media` (originales en Media), Drupal Commerce 3 (`webassistant_connector_commerce`) |
| Red | Drupal → API (HTTPS); navegador → API (loader, widget); worker de la plataforma → Drupal (descarga de originales) |

## 2. Preparar la plataforma

En la consola de la plataforma (`/admin/`) o con un paquete de configuración:

1. **Site** con el origen exacto del portal (`https://www.portal.example`, sin barra final) en
   `allowed_origins`, el assistant por defecto y, si hay acciones, las capacidades (`content_forms`,
   `user_profile`, `cart`, `analytics`) y los scopes permitidos en `settings.allowed_widget_scopes`.
2. **Bases de conocimiento** para cada fuente (p. ej. `portal_contenidos`, `portal_documentos`,
   `portal_productos`) asignadas al site o compartidas en el tenant.
3. **Cliente API** del portal con `token:issue`, `ingest:write`, `ingest:read` y `search:read`, limitado al
   site. El secreto se muestra una sola vez: guárdalo en el gestor de secretos del portal.
4. Si se ingieren originales: `WEBASSISTANT_INGEST_ALLOWED_DOWNLOAD_HOSTS` con el host con el que el worker
   llega a Drupal (obligatorio en staging/producción).

## 3. Instalar el módulo

```bash
# Opción A: copiar el módulo
cp -r integraciones/drupal/webassistant_connector web/modules/custom/
# Opción B: repositorio path de Composer
composer config repositories.webassistant path /ruta/integraciones/drupal/webassistant_connector
composer require minsait/webassistant_connector:@dev

drush en webassistant_connector -y
drush en webassistant_connector_documents -y      # originales PDF/DOCX
drush en webassistant_connector_commerce -y       # catálogo y carrito (requiere Commerce)
```

Secretos en `settings.php` (o en variables de entorno, que tienen menos prioridad):

```php
$settings['webassistant_connector'] = [
  'api_base_url' => 'https://asistente.example.com',   // WEBASSISTANT_API_URL
  'api_public_url' => 'https://asistente.example.com', // WEBASSISTANT_API_PUBLIC_URL
  'client_id' => 'wa_portal_prod',                     // WEBASSISTANT_CLIENT_ID
  'client_secret' => getenv('WEBASSISTANT_CLIENT_SECRET'),
  // 'signing_key' => '...',  // WEBASSISTANT_SIGNING_KEY; por defecto se deriva de hash_salt
];
```

La configuración exportable (`drush cex`) nunca contiene el secreto ni la clave de firma. Cambiar
`hash_salt` o la clave de firma cambia los identificadores opacos de usuario: los usuarios dejan de ver sus
conversaciones anteriores.

## 4. Configurar

*Configuración → Servicios web → WebAssistant connector* (`/admin/config/services/webassistant-connector`):

| Pestaña | Qué se configura |
| --- | --- |
| Ajustes | Conexión (URLs, timeouts), site, assistant por defecto, widget (colocación global o por bloque, posición, apertura, contenedor de resultados, idioma, visibilidad por rutas, `dataLayer`) y **Probar conexión** |
| Identidad | Tokens para usuarios autenticados, duración, vínculo al origen, roles, scopes base y tabla **permiso → scope** |
| Sincronización | Prefijo de `source_id` (único por instalación), URL pública base, idioma por defecto, tamaño de lote, intentos, límite por ejecución, reconciliación y retención |
| Fuentes | Qué entidades, bundles, idiomas y campos se envían y a qué base de conocimiento; **Previsualizar** muestra el JSON exacto |
| Estado | Conectividad, credenciales, site/origen, cola, últimos errores y acciones (procesar, reintentar, reconciliar, indexar) |

### 4.1 Permisos y scopes

| Permiso Drupal | Scope de la plataforma | Host actions |
| --- | --- | --- |
| `use webassistant connector` | `chat:use`, `knowledge:read`, `documents:read`, `actions:execute` (scopes base) | Necesario para cualquier host action y para el token autenticado |
| `access content` | `catalog:read` | — |
| `use webassistant content actions` | `content:write` | `content.create`, `content.edit` |
| `use webassistant profile actions` | `profile:read` | `user.profile` |
| `use webassistant cart actions` (Commerce) | `cart:read`, `cart:write` | `cart.*` |
| `use webassistant analytics actions` (Commerce) | `analytics:read` | `analytics.sales_report` |
| `administer webassistant connector` | — | Administración, estado y Drush |

Los scopes solo deciden qué ofrece el asistente: cada host action vuelve a comprobar el acceso de Drupal
(crear o editar ese contenido, ver ese producto, tu propio perfil).

### 4.2 Fuentes

Una fuente define tipo de entidad, bundles, idiomas, base de conocimiento, tipo de documento, modo de ACL y
roles de campo:

| Rol | Uso |
| --- | --- |
| `title` | Título (si no hay, la etiqueta de la entidad) |
| `main_content`, `context` | Texto del documento (texto con formato procesado, cadenas y referencias con profundidad ≤ 2) |
| `metadata`, `filter` | Metadatos (`key` opcional) |
| `image` | `metadata.image_url` absoluta |
| `url` | Sustituye la URL canónica |
| `date` | `updated_at` |
| `acl` | Principales cuando el modo de ACL es `field` |
| `exclusion` | Un valor verdadero excluye la traducción |

Las fuentes nuevas se crean desactivadas: previsualiza un contenido real, revisa los avisos de campos
sensibles o sin acceso y actívala. Dos fuentes activas no pueden cubrir el mismo bundle e idioma en la
misma base de conocimiento.

## 5. Sincronización y cron

- Cada alta, cambio, traducción, publicación, despublicación o borrado de una entidad cubierta se registra
  en la outbox **en la misma transacción** (sin HTTP). La cola `webassistant_connector_dispatch` la procesa.
- Ejecuta el cron del sistema (recomendado cada minuto o cada cinco) y desactiva `automated_cron` en
  producción:
  ```cron
  * * * * * cd /var/www/portal && vendor/bin/drush cron --quiet
  ```
  Para más rendimiento: `drush queue:run webassistant_connector_dispatch --time-limit=50`.
- Carga inicial o tras cambiar una fuente: `drush webassistant:index --process`.
- Reconciliación diaria automática (o `drush webassistant:reconcile`) contra el inventario de la plataforma.

## 6. Comandos Drush

| Comando | Uso |
| --- | --- |
| `drush webassistant:status` (`wa-status`) | Conexión y outbox (`--format=json` para monitorización) |
| `drush webassistant:check` (`wa-check`) | Diagnóstico completo; código de salida ≠ 0 si algo falla |
| `drush webassistant:index [fuente]` (`wa-index`) | Indexación inicial (`--bundle`, `--langcode`, `--process`) |
| `drush webassistant:sync` (`wa-sync`) | Procesa la outbox ahora (`--limit`, `--time`) |
| `drush webassistant:reconcile [kb]` (`wa-reconcile`) | Reconciliación (`--dry-run`) |
| `drush webassistant:retry` (`wa-retry`) | Reintenta eventos `failed`/`dead` (`--status`, `--id`) |
| `drush webassistant:preview <tipo> <id> [idioma]` (`wa-preview`) | JSON canónico que se enviaría |
| `drush webassistant:purge` | Retira de la plataforma todos los documentos de esta instalación |
| `drush webassistant:migrate-legacy` (`wa-migrate-legacy`) | Migra ajustes y bloques de `webassistant_ai` (`--rollback`) |

## 7. Runbooks

| Síntoma | Diagnóstico | Acción |
| --- | --- | --- |
| La cola crece y no baja | *Estado* o `drush wa-status`: eventos `pending`/`failed` antiguos, último éxito lejano | Comprueba el cron; `drush wa-check`; si la API está caída, la cola se suspende sola y reintenta con backoff |
| Eventos `dead` | *Estado* → errores recientes (con `correlation_id`) | Corrige la causa (KB inexistente, documento inválido, permisos del cliente) y `drush wa-retry --status=dead` |
| 401/403 de la plataforma | `drush wa-check`: credenciales o scopes del cliente | Rota el secreto en la consola y actualiza `settings.php`; el token de servidor se renueva solo |
| El widget no aparece | Consola del navegador, *Estado* (bootstrap con el origen) | Origen no registrado en el site, visibilidad, `enabled`, CSP (`script-src`/`connect-src`/`img-src` con la API) |
| El usuario sigue anónimo | Respuesta de `POST /webassistant/token` | Permiso `use webassistant connector`, identidad activada, origen del token = origen de la página (proxy inverso: `reverse_proxy`) |
| Un PDF queda `failed` | Inventario o *Estado* | Host permitido en la plataforma, tamaño ≤ 50 MB, fichero aún publicado; la reconciliación lo reenvía |
| Contenido que debía retirarse sigue respondiendo | `drush wa-preview` y estado remoto | `drush wa-sync`; `drush wa-reconcile`; revisa que la fuente siga activa |
| Cambio de permisos de roles | — | Se detecta solo (época de acceso) y se recalculan las ACL; fuerza con `drush wa-index --process` |
| Reindexado completo | — | `drush wa-index --process` (no re-embebe lo que no ha cambiado) |
| Rollback del conector | — | `enabled: false` en Ajustes (o `drush cset webassistant_connector.settings enabled 0`) y, si venía del legado, `drush wa-migrate-legacy --rollback` |

## 8. Migración desde `webassistant_ai` (DR-07)

1. Instala el conector junto al módulo legado y configúralo (conexión, identidad, fuentes).
2. `drush wa-migrate-legacy --dry-run` muestra qué bloques y ajustes se migrarán.
3. `drush wa-migrate-legacy` crea bloques `webassistant_connector_widget` equivalentes y desactiva los
   antiguos sin borrarlos.
4. Comprueba el portal; `drush wa-migrate-legacy --rollback` vuelve al estado anterior.
5. Cuando no haya tráfico legado, desinstala `webassistant_ai` (y sus datos de uso tras exportarlos si la
   auditoría lo requiere).

## 9. Seguridad

- Secretos fuera de la configuración; tokens de widget temporales, ligados al origen y nunca en el HTML.
- `POST /webassistant/token` exige sesión, permiso y `X-CSRF-Token`, con Flood por usuario.
- `POST /webassistant/host-action/{name}` exige permiso, cabecera `X-WebAssistant-Request`, origen del
  mismo host y CSRF para usuarios autenticados; idempotencia por usuario y sesión; redirecciones internas.
- Los originales se sirven solo con firma HMAC en la ruta y mientras sigan indexados.
- Los identificadores de usuario son HMAC opacos; los eventos enviados a `dataLayer` no llevan datos
  personales.
