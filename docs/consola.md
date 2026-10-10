# Manual de la consola de administración (CG-19.3)

La consola está en `https://<dominio>/admin/`. Es accesible con teclado y lector de pantalla y muestra
solo las secciones que permite el rol del usuario. Todas las operaciones quedan en la auditoría.

## Roles

| Rol | Ámbito | Puede |
| --- | --- | --- |
| `platform_admin` | Plataforma | Todo, incluidos tenants, precios, registro de skills, índices vectoriales y diagnóstico |
| `tenant_admin` | Su tenant | Sites, clientes API, usuarios, políticas, retención, assistants, conocimiento, uso, auditoría y trazas |
| `editor` | Su tenant | Assistants (editar y publicar), políticas de agente, conocimiento y uso |
| `auditor` | Su tenant | Lectura de configuración, conocimiento y uso; auditoría, conversaciones y payloads técnicos |
| `viewer` | Su tenant | Lectura de configuración, conocimiento y uso |

Tras cinco intentos de acceso fallidos la cuenta se bloquea temporalmente. Cambia tu contraseña en
**Mi cuenta**.

## Secciones

### Inicio
Tu rol y permisos, el uso de los últimos 30 días y, para `platform_admin`, el estado de PostgreSQL,
Redis y Qdrant, la cola y los circuit breakers.

### Assistants
- **Nuevo assistant**: crea un borrador (clave estable para `data-assistant-id`).
- **Borrador**: configuración JSON validada contra
  [`assistant-config.v1`](../packages/contracts/schemas/assistant-config.v1.schema.json): instrucciones,
  ámbito, modelo (`provider`, `model_id`, `params`), límites (longitud de mensaje, historial, tokens),
  mensajes, skills (con su configuración), bases de conocimiento, sugerencias e historial (`window` o
  `summary`).
- **Publicar**: crea una versión inmutable tras comprobar la compatibilidad de las skills. Los widgets usan
  siempre la versión publicada; cada run registra su versión.
- **Versiones**: ver la configuración congelada y **restaurar** (se publica como versión nueva).
- **Skills de la versión publicada**: disponibilidad, permisos y capacidades requeridas.

### Políticas de agente
Iteraciones y llamadas a herramientas máximas, herramientas permitidas, parámetros de RAG (`amount`,
`max_amount`, `min_score`), intentos de reparación, reintento con RAG forzado, detección de bucles y
ventana de historial. Se aplican al publicar los assistants que las usan.

### Conocimiento
Bases de conocimiento (troceado: `heading`, `paragraph` o `whole`) con el recuento de documentos por
estado. En **Documentos**: filtro por estado, intentos, fragmentos, ACL, fecha de indexación y error;
**Reintentar fallidos** y **Reindexar** por documento. **Conciliar** compara PostgreSQL y Qdrant.

### Sites
Identificador público, orígenes permitidos (CORS), modo de acceso (`public`/`private`), idiomas,
assistant por defecto, capacidades del portal, tema validado (contraste AA) y ajustes
(`allowed_widget_scopes`, `anonymous_scopes`, `link_anonymous_on_login`, `desktop_always_open`,
`result_target_selector`). **Instalación** muestra la etiqueta `<script>` lista para copiar.

### Clientes API
Credenciales server-to-server con permisos (`token:issue`, `ingest:write`, `ingest:read`,
`search:read`) y, opcionalmente, limitadas a un site. El secreto se muestra **una sola vez** al crear o
rotar; solo se guarda su hash. **Revocar** es inmediato.

### Usuarios
Alta de usuarios del tenant con rol y contraseña inicial; cambio de rol y desactivación.

### Políticas y retención
- **Límites, presupuestos y alertas** por tenant o por assistant: modelos permitidos, presupuesto diario y
  mensual, umbral de alerta, tokens máximos, concurrencia (tenant y usuario) y límites de peticiones.
  Al superar un presupuesto las consultas se rechazan con un mensaje controlado.
- **Retención**: días de conservación de conversaciones, payloads técnicos, auditoría y uso; al caducar
  se **anonimiza** o **borra**. El worker la aplica cada noche; **Aplicar ahora** la encola.

### Uso y costes
Filtros por fechas y agrupación (día, assistant, modelo, proveedor, operación, skill, site, estado).
Tokens de entrada, en caché, de salida y de razonamiento, coste según el precio vigente en cada llamada y
latencia media. **Exportar CSV** descarga el mismo informe.

### Conversaciones
Metadatos de conversaciones para soporte. La **traza** muestra mensajes, runs (estado, skill, versión,
iteraciones, RAG, reparación, latencia, tokens y coste), llamadas al modelo, herramientas, acciones y el
contexto enviado. Los **payloads técnicos** (prompts y respuestas) están cifrados: verlos requiere el
permiso `payloads.read` y queda auditado.

### Auditoría
Eventos con actor, acción, objeto, resultado y detalle; filtros por prefijo de acción (`assistant.`,
`action.`, `token.`, `admin.`, `ingest.`, `retention.`…) y por run.

### Exportar / importar
Exporta el paquete de configuración del tenant (sin secretos). Importa uno desde fichero: primero
**Simular importación** muestra los cambios; **Aplicar importación** los ejecuta (idempotente).

### Plataforma (`platform_admin`)
- **Precios**: publicar un precio nuevo cierra la vigencia del anterior; los costes ya registrados no
  cambian.
- **Skills instaladas**: versión, estado, origen, renderer, permisos y diagnóstico; **Volver a descubrir**.
- **Índices vectoriales**: reindexar con otro modelo de embeddings en una colección nueva, **activar**
  cuando esté lista y **volver a la versión anterior** si hace falta.
- **Uso por tenant**.

## Costes y límites: recomendaciones

1. Publica los precios de todos los modelos que se usen antes de habilitarlos.
2. Define un presupuesto mensual por tenant con alerta al 80 % y, para assistants de prueba, uno propio
   más bajo.
3. Mantén `max_output_tokens` acorde al renderer (las respuestas estructuradas rara vez superan 2.000).
4. Revisa semanalmente *Uso y costes* agrupado por skill: una skill con coste alto y pocas conversaciones
   suele indicar consultas que no encuentran fuentes (mejora el contenido o el `min_score`).
