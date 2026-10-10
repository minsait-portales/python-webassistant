# Casos de uso (CG-00.2)

Prioridad: **MVP** (imprescindible para el piloto), **V1** (release candidata) y **Evolución**. Se separan
las operaciones de **lectura** de las que tienen **efectos** (requieren permiso, confirmación e
idempotencia). Cada caso indica las pruebas que lo cubren.

## Usuario final (widget)

| ID | Caso | Tipo | Prioridad | Skills / API | Pruebas |
| --- | --- | --- | --- | --- | --- |
| CU-01 | Abrir el asistente, ver bienvenida y sugerencias | Lectura | MVP | `bootstrap`, `sessions` | `chat.test.ts`, E2E teclado |
| CU-02 | Preguntar sobre contenidos editoriales con fuentes | Lectura | MVP | `content_answer` + RAG | `test_api_chat`, E2E sugerencias |
| CU-03 | Buscar productos y ver resultados enriquecidos | Lectura | MVP | `product_results`, `product_recommendations` | `test_skills`, E2E contenedor |
| CU-04 | Comparar, resumir o pedir alternativas de productos | Lectura | V1 | `product_comparison`, `product_summary`, `product_alternatives` | `test_skills` |
| CU-05 | Preparar una compra para un evento (barbacoa) | Lectura | MVP | `event_product_selection` | E2E acción con efectos |
| CU-06 | Consultar documentación técnica con citas verificadas | Lectura | MVP | `document_lookup` | `test_skills`, E2E citas |
| CU-07 | Pedir aclaración cuando la consulta es ambigua | Lectura | V1 | `clarification` | `test_skills`, `test_agent` |
| CU-08 | Añadir al carrito un producto o toda la selección | **Efectos** (host) | MVP | `shopping_cart_add`, `add_selection_to_cart` | `test_api_actions`, E2E acción |
| CU-09 | Ver, actualizar o eliminar productos del carrito | Lectura / **Efectos** | V1 | `shopping_cart_view` (`cart.get/update/remove`) | E2E carrito |
| CU-10 | Ir al pago | **Efectos** (host) | V1 | `payment` | `test_api_actions` |
| CU-11 | Crear o editar contenidos (editores) | **Efectos** (host) | V1 | `content_create`, `content_edit` | `test_api_actions`, E2E sesión autenticada |
| CU-12 | Ver el perfil o informes de ventas (usuarios autorizados) | Lectura (host) | V1 | `user_profile`, `sales_analytics` | `test_skills`, `test_api_actions` |
| CU-13 | Detener una respuesta en curso | Control | MVP | `runs/{id}/cancel` | `test_api_chat` |
| CU-14 | Recuperar la conversación al recargar o tras una desconexión | Lectura | MVP | historial, `Last-Event-ID` | `chat.test.ts`, E2E recarga, `sdk.test.ts` |
| CU-15 | Empezar un chat nuevo / borrar la conversación | **Efectos** (datos propios) | MVP | `reset`, `DELETE conversations` | `test_api_chat`, E2E |
| CU-16 | Recibir una respuesta controlada fuera de ámbito | Lectura | MVP | `out_of_scope` | `test_api_chat`, E2E sugerencias |
| CU-17 | Usar el asistente autenticado y conservar el historial anónimo | Identidad | V1 | `token` + `sessions` | `test_api_chat`, `test_demo`, E2E sesión |

## Portal (integración server-to-server)

| ID | Caso | Tipo | Prioridad | API | Pruebas |
| --- | --- | --- | --- | --- | --- |
| CU-20 | Emitir tokens de widget para usuarios del portal | Identidad | MVP | `POST /v1/token` | `test_isolation_and_ingest`, `test_admin` |
| CU-21 | Publicar, actualizar y borrar documentos (lotes, idempotente) | **Efectos** | MVP | `/v1/ingest/documents` | `test_isolation_and_ingest` |
| CU-22 | Cambiar la ACL de un documento | **Efectos** | V1 | `/v1/ingest/acl` | `test_isolation_and_ingest` |
| CU-23 | Conciliar inventario | Lectura | V1 | `/v1/ingest/inventory` | `test_isolation_and_ingest` |
| CU-24 | Búsqueda semántica server-to-server | Lectura | V1 | `POST /v1/search` | `test_isolation_and_ingest` |
| CU-25 | Ejecutar host actions y escuchar eventos del widget | Integración | MVP | `window.WebAssistant` | `loader.test.ts`, E2E |

## Administración

| ID | Caso | Tipo | Prioridad | Pruebas |
| --- | --- | --- | --- | --- |
| CU-30 | Registrar tenants, sites (orígenes, idiomas, tema) y clientes API | **Efectos** | MVP | `test_admin`, E2E consola |
| CU-31 | Editar, publicar y restaurar versiones de assistants | **Efectos** | MVP | `test_admin`, E2E consola |
| CU-32 | Configurar políticas de agente, presupuestos y límites | **Efectos** | V1 | `test_admin` |
| CU-33 | Supervisar ingesta, reintentar y reindexar | **Efectos** | V1 | `test_admin`, `test_worker` |
| CU-34 | Consultar uso y coste, exportar CSV | Lectura | MVP | `test_admin`, E2E consola |
| CU-35 | Auditar acciones y trazar una conversación | Lectura | V1 | `test_admin` |
| CU-36 | Exportar/importar configuración entre entornos (dry-run) | **Efectos** | V1 | `test_admin`, E2E consola |
| CU-37 | Publicar precios, cambiar de modelo de embeddings y revertir | **Efectos** | V1 | `test_admin` |
| CU-38 | Configurar la retención y aplicarla | **Efectos** | V1 | `test_admin`, `test_worker` |

## Errores (transversales)

Origen no permitido, site desactivado, token caducado (renovación transparente), límite de peticiones,
presupuesto agotado, concurrencia, mensaje demasiado largo, proveedor caído o lento, salida inválida del
modelo (reparación y degradación a texto), herramienta fallida, límite de iteraciones, acción no
disponible o no confirmada, conflicto de idempotencia. Todos devuelven `problem+json` con `code` estable
y `user_message` (ver [integración](../integracion.md#errores)).

## Evolución (fuera de V1)

Conectores específicos por plataforma, voz, adjuntos del usuario, colecciones Qdrant dedicadas para
tenants grandes, evaluación continua de RAG con conjuntos dorados por tenant y paneles de calidad.
