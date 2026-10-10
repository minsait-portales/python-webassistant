# Matriz de paridad con el módulo Drupal `webassistant_ai` (CG-00.1)

Inventario del módulo (`webassistant_ai`, `webassistant_ai_documents`, `webassistant_ai_ecommerce`) y su
destino en la capa genérica: **Conserva** (misma función), **Rediseña** (misma necesidad, otra solución) o
**Elimina** (no aplica fuera de Drupal). No quedan funciones huérfanas: cada fila tiene destino.

## Endpoints y páginas

| Drupal | Destino | Capa genérica |
| --- | --- | --- |
| `/webassistant-ai/chat` | Rediseña | `POST /v1/conversations/{id}/messages` (JSON o SSE) |
| `/webassistant-ai/chat/history` | Conserva | `GET /v1/conversations/{id}` y `GET /v1/conversations` |
| `/webassistant-ai/chat/reset` | Conserva | `POST /v1/conversations/{id}/reset` (+ `DELETE` por privacidad) |
| `/webassistant-ai/skills/{machine_name}/{action}` | Rediseña | `POST /v1/actions/prepare` → `execute` (confirmación + idempotencia) → `result` |
| `/webassistant-ai/cart/add`, `/cart/panel` | Rediseña | Host actions `cart.add`, `cart.add_many`, `cart.get`, `cart.update`, `cart.remove` en la página |
| `/home-ai` (página con el chat) | Rediseña | `loader.js` en cualquier página y web de demo `/demo/` |
| `/admin/config/services/webassistant-ai` (overview, flow, status) | Rediseña | Consola `/admin/` (inicio y diagnóstico) y `GET /v1/admin/diagnostics` |
| `.../settings` | Rediseña | Assistant versionado (mensajes, límites, modelo) + site (tema, idiomas, orígenes) |
| `.../skills`, `.../extensions` | Rediseña | Skills por assistant + registro de skills de plataforma (`/v1/admin/skills`) |
| `.../usage`, `.../usage/{conversation}`, `.../statistics` | Rediseña | Uso y costes con filtros y CSV; traza de conversación; Prometheus/Grafana |
| `.../documents` (+ reglas y borrado) | Rediseña | API de ingesta del portal + bases de conocimiento y estado de documentos en la consola |

## Configuración (`webassistant_ai.settings`)

| Clave Drupal | Destino | Capa genérica |
| --- | --- | --- |
| `welcome_message`, `input_placeholder`, `privacy_notice`, `login_required_message`, `role_denied_chat_message` | Conserva | `messages` del assistant (`welcome`, `input_placeholder`, `privacy_notice`, `login_required`, `access_denied`) |
| `assistant_id` | Rediseña | `data-assistant-id` o assistant por defecto del site |
| `use_minsait_font`, `chat_logo_path`, `toggle_label` | Rediseña | Tema validado del site (`font_family`, `logo_url`, `launcher_label`) con contraste AA |
| `result_target_selector` | Conserva | `data-result-target` o `settings.result_target_selector` del site |
| `header_sticky`, `extra_shift_selectors` | Rediseña | El panel es un drawer fijo en Shadow DOM; `layout.header_offset_selector` en bootstrap |
| `desktop_always_open` | Conserva | `settings.desktop_always_open` del site |
| `admin_currency`, `model_pricing`, `pricing_last_saved` | Rediseña | Tabla de precios versionada por proveedor/modelo con vigencias (`/v1/admin/prices`) |
| `enabled_skills` | Conserva | `skills` del assistant (con configuración por skill) |
| `max_message_length` | Conserva | `limits.max_message_length` del assistant |
| `rate_limit_requests`, `rate_limit_window` | Rediseña | Límites por IP, site, usuario y administrador + políticas por tenant/assistant |

## Permisos

| Drupal | Destino | Capa genérica |
| --- | --- | --- |
| `administer webassistant ai settings` | Rediseña | Roles `platform_admin`, `tenant_admin`, `editor` con permisos `config.*`, `tenant.manage`, `knowledge.*` |
| `view webassistant ai usage` | Rediseña | `usage.read`, `audit.read`, `conversations.read`, `payloads.read` (roles `auditor`, `viewer`) |
| Acceso por rol al chat (`AssistantAccessManager`) | Rediseña | `access_mode` del site + scopes del token de widget (rol del portal → scopes) |

## Skills

| Skill Drupal | Destino | Notas |
| --- | --- | --- |
| `default`, `content_answer`, `content_create`, `content_edit`, `user_profile` | Conserva | Migradas al formato portable; creación/edición y perfil como host actions |
| `document_lookup` (módulo documents) | Conserva | Citas verificadas contra el texto recuperado |
| `clarification`, `event_product_selection`, `payment`, `product_alternatives`, `product_comparison`, `product_recommendations`, `product_results`, `product_summary` (módulo ecommerce) | Conserva | Procesadores que descartan productos inventados y enriquecen con datos reales |
| `product_common` (utilidades PHP compartidas) | Rediseña | Procesadores `catalog_items`, `event_totals` del motor de skills |
| Carrito (rutas `cart/*`) | Rediseña | Skills `shopping_cart_add` y `shopping_cart_view` con host actions |
| Informes de ventas | Rediseña | Skill `sales_analytics` (host action `analytics.sales_report`) |

## Componentes internos

| Drupal | Destino | Capa genérica |
| --- | --- | --- |
| `SkillDiscovery`, `SkillMatcher`, `SkillRuntime`, `SkillHandlerResolver`, `SkillClassLoader` | Rediseña | `SkillRegistry` (descubrimiento y diagnóstico), `SkillMatcher` (scopes, capacidades, tenant), `SkillRuntime` |
| `SkillTemplateRenderer` (Twig) | Rediseña | Presentaciones JSON validadas + renderers del widget `tipo@versión` |
| `SkillActionDispatcher` / `SkillActionHandlerInterface` | Rediseña | `ActionService` + `ActionHandler` del SDK |
| `SkillAwareRagTool` (AI function call) | Conserva | Herramienta `search_knowledge` con la skill como parámetro |
| `SkillPromptSubscriber` | Rediseña | Prompts por descubrimiento progresivo (catálogo → contrato de la skill) |
| `OpenAiLunaToolCompatibilitySubscriber` | Rediseña | Perfil de modelo `gpt-6-luna` en el gateway (sin razonamiento con herramientas) |
| `AgentResponseGuard` | Rediseña | Validación de esquema, una reparación, degradación a texto y límites del agente |
| `AiUsageSubscriber`, `UsageLogger`, `UsageRepository` | Rediseña | `UsageRecorder` + `usage_events` con coste por tipo de token |
| `UsageReportBuilder`, `UsageChartBuilder` | Rediseña | Informes de uso (API/CSV), consola y Grafana |
| `DependencyStatusBuilder` | Rediseña | `/health/ready` y `/v1/admin/diagnostics` |
| `WebAssistantAIBlock` | Rediseña | Etiqueta `<script>` del loader en cualquier plataforma |
| Procesadores `search_api` y colas de extracción (`DocumentExtractionManager`, `PdfTextExtractor`) | Rediseña | Ingesta canónica + extracción PDF/DOCX/HTML en el worker |
| `EmbeddedAssistantFormAjax` | Elimina | Específico de Drupal Form API; sustituido por host actions `content.*` |
| `ThemeHooks`, librerías y plantillas Twig | Elimina | El widget no depende del tema del CMS |
| `webassistant-ai.js` y CSS | Rediseña | Widget TypeScript accesible en Shadow DOM (misma estética Minsait) |
| Fuente ForFuture Sans e imágenes Minsait | Conserva | Logo por defecto y `font_family: forfuture` en el tema |

## Persistencia, RAG y costes

| Drupal | Destino | Capa genérica |
| --- | --- | --- |
| Tablas propias de uso y conversaciones en la BD de Drupal | Rediseña | PostgreSQL propio con RLS por tenant (24 tablas, migraciones Alembic) |
| Search API + servidor vectorial | Rediseña | Qdrant con filtros obligatorios y versiones de embeddings |
| Precios instalados en configuración | Conserva | Importados como precios iniciales (`gpt-6-luna`, `gpt-5.6`, `gpt-5.4-mini`, `gpt-4.1-mini`, embeddings) |
