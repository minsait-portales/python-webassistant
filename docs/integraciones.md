# Matriz de integración por plataforma (CG-17.5)

**Estado: propuesta técnica** para revisar con los equipos de cada plataforma (CG-17.2). El conector de
Drupal 11 ya está implementado como referencia en [`integraciones/drupal`](../integraciones/drupal/README.md). Los acuerdos
cerrados se registran como ADR y se reflejan en esta tabla. Los conectores específicos (módulos, plugins,
extensiones) quedan fuera del núcleo y los mantiene cada equipo; el producto ofrece la API v1, el SDK, el
widget y estos contratos.

## Piezas comunes que implementa cada plataforma

1. **Instalación del widget**: la etiqueta `<script>` con `data-site-id` en las plantillas (o un bloque o
   componente que la genere).
2. **Endpoint de token** en el backend del portal: autentica al usuario y llama a `POST /v1/token`
   (`grant_type=widget_token`) con un identificador opaco, roles y grupos; la página lo entrega con
   `WebAssistant.setToken(fn)`.
3. **Sincronización de contenidos**: publica documentos canónicos con `POST /v1/ingest/documents`
   al crear, actualizar, despublicar o cambiar permisos, y concilia con el inventario.
4. **Host actions** opcionales (carrito, formularios, perfil, informes) registradas en la página.
5. **Eventos** opcionales para analítica (`WebAssistant.on`).

## Propuesta por plataforma

| Aspecto | Drupal | Liferay | Adobe Experience Manager | SharePoint Online | WordPress |
| --- | --- | --- | --- | --- | --- |
| Instalación | Bloque o `hook_page_attachments` con la etiqueta | Fragmento o *theme contributor* | Componente/cliente lib en la plantilla de página | SPFx Application Customizer | Plugin con `wp_enqueue_script` (atributos con filtro `script_loader_tag`) |
| Identidad (token) | Ruta propia que usa la sesión de Drupal | Endpoint REST/OSGi con el usuario de Liferay | Servlet de Sling con el usuario de AEM | Azure Function con el token de Entra ID del usuario | Endpoint REST con `wp_get_current_user` |
| Roles → scopes | Roles de Drupal | Roles y organizaciones | Grupos de AEM | Grupos de Entra ID / SharePoint | Roles de WordPress |
| Fuente de contenidos | Nodos y medios (Search API o hooks de entidad) | Web content y Documents & Media (listeners de modelo) | Páginas y Assets (eventos de replicación) | Páginas y bibliotecas (Microsoft Graph, *delta query*) | Entradas, páginas y medios (`save_post`, `delete_post`) |
| Originales PDF/DOCX | URL de descarga temporal firmada | URL de Documents & Media con token | URL de Assets con token de servicio | URL de Graph con token de aplicación | URL de medios (privados con token) |
| ACL | Acceso de nodo / grupos | Permisos de recurso | ACL de JCR | Permisos de elemento | Estado de publicación y roles |
| Host actions típicas | Commerce (carrito), formularios de nodo | Commerce, formularios | Formularios, perfil | Formularios de lista, perfil | WooCommerce (carrito), formularios |
| Responsable del conector | Equipo Drupal | Equipo Liferay | Equipo AEM | Equipo Microsoft 365 | Equipo WordPress |
| Estado | **Conector implementado**: [`integraciones/drupal`](../integraciones/drupal/README.md) (Drupal 11.2+) | Pendiente | Pendiente | Pendiente | Pendiente |

## Decisiones abiertas

| Tema | Pregunta | Propietario | Fecha objetivo |
| --- | --- | --- | --- |
| Identidad | ¿Se aceptan identificadores opacos (hash) en lugar del id interno del usuario? | Seguridad + equipos | Por acordar |
| Sincronización | ¿Eventos en tiempo real o lotes programados con conciliación diaria? | Equipos de plataforma | Por acordar |
| Originales | ¿Cada portal puede emitir URLs de descarga temporales firmadas? | Equipos de plataforma | Por acordar |
| Entornos | Correspondencia de sites y tenants por entorno (desarrollo, preproducción, producción) | Operación | Por acordar |
| Soporte | Canal y responsables de incidencias que crucen portal y producto | PM | Por acordar |
