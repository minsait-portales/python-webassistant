# Guía de skills (CG-19.3)

Una **skill** enseña al assistant a resolver un tipo de petición (buscar productos, responder desde
contenidos, citar documentos, preparar una compra…) con una salida estructurada que el widget sabe
pintar. Las skills son **paquetes portables**: se añaden, actualizan o retiran sin modificar el núcleo.

## 1. Cómo se usa una skill en un run

1. El assistant habilita una lista de skills (con configuración por skill). El motor filtra las que el
   usuario puede usar según sus **scopes**, las **capacidades** del site (`cart`, `content_forms`…) y las
   restricciones del tenant.
2. El modelo recibe un **catálogo** breve (nombre, intención, cuándo usarla, ejemplos) y elige una
   (*descubrimiento progresivo*); después recibe el **contrato** de la elegida: esquema de entrada, ejemplo
   de respuesta y reglas.
3. Si la skill `requires_rag`, el modelo debe llamar a la herramienta `search_knowledge` con la skill como
   parámetro; si no lo hace, el agente reintenta forzando la búsqueda.
4. La salida se valida contra `schemas/input.json` (una reparación como máximo), pasa por los
   **procesadores** del núcleo y por el **handler** opcional, y se convierte en una presentación
   `renderer@versión` validada contra su esquema. Si algo falla, se degrada a texto.
5. Las **acciones** declaradas se ofrecen solo si el token del usuario tiene el permiso.

## 2. Estructura del paquete

```
mi_skill/
├── skill.yaml                 # manifiesto (obligatorio)
├── schemas/input.json         # JSON Schema de la salida del modelo (result)
├── schemas/output.json        # opcional: restricciones adicionales de la presentación
├── schemas/actions/<acción>.json   # parámetros de cada acción
├── examples/response.json     # ejemplo completo {skill, chat_reply, result}
└── handler.py                 # opcional: lógica Python (webassistant-skill-sdk)
```

### 2.1 Manifiesto (`skill.yaml`)

Validado con [`skill-manifest.v1`](../packages/contracts/schemas/skill-manifest.v1.schema.json):

| Campo | Obligatorio | Descripción |
| --- | --- | --- |
| `api_version` | Sí | `webassistant.ai/v1` |
| `name`, `version`, `title`, `description` | Sí | Identificador (`[a-z][a-z0-9_]`), SemVer, título y descripción |
| `intent` | Sí | Cuándo debe elegirla el modelo (una frase) |
| `when_to_use`, `examples`, `keywords` | No | Casos, frases de ejemplo y palabras clave para la selección |
| `requires_rag` | Sí | Si necesita búsqueda en la base de conocimiento |
| `permissions` | No | Scopes que el usuario debe tener (`catalog:read`, `cart:write`…) |
| `input_schema`, `output_schema` | No | Rutas a los esquemas |
| `response_example` | Sí | Ruta al ejemplo de respuesta |
| `renderer` | Sí | Presentación del widget: `text@1`, `answer@1`, `product_grid@1`, `product_comparison@1`, `product_summary@1`, `event_selection@1`, `document_citations@1`, `clarification@1`, `steps@1`, `cart@1`, `host_action@1` |
| `processors` | No | Post-procesadores del núcleo (ver §3) |
| `handler` | No | `modulo:Clase` que implementa `SkillHandler` |
| `chat_reply_fallback`, `responsive_button_label` | No | Texto del chat si el modelo no lo da y etiqueta del botón de resultado |
| `out_of_scope_allowed` | No | Permite respuestas fuera del ámbito del assistant |
| `actions` | No | Acciones (§4) |
| `dependencies` | No | `platform: ">=1.0,<2.0"` y `capabilities` del site requeridas |

## 3. Procesadores del núcleo

| Procesador | Qué hace |
| --- | --- |
| `catalog_items` | Conserva solo productos que existen en las fuentes recuperadas y los enriquece (precio formateado, imagen y URL seguras, disponibilidad, variación) |
| `event_totals` | Calcula totales de una selección por categorías |
| `content_sources` | Enlaza las fuentes de contenido realmente usadas |
| `source_citations` | Añade las citas de las fuentes usadas a la respuesta |
| `document_citations` | Verifica que cada cita literal aparece en el texto recuperado y marca `verified` |
| `clarification_options` | Normaliza las opciones de una pregunta aclaratoria |

## 4. Acciones

```yaml
actions:
  add_to_cart:
    type: host                 # host: la ejecuta la página; internal: la ejecuta un handler del servidor
    label: Añadir al carrito
    host_action: cart.add      # nombre que recibe window.WebAssistant.registerHostAction
    permission: cart:write
    effects: true              # modifica estado: exige Idempotency-Key
    confirmation: required     # diálogo de confirmación antes de ejecutar
    target: item               # botón por producto (item) o de la presentación (presentation)
    auto: false                # true: se ejecuta al pintar (solo lectura, p. ej. cargar el carrito)
    input_schema: schemas/actions/add_to_cart.json
```

Las acciones `internal` declaran `handler: modulo:Clase` con un `ActionHandler`
(`async def execute(ctx, params) -> ActionResult`). Las acciones de presentación reciben como parámetros
los campos del resultado que coinciden con su esquema (y la lista de productos de la selección cuando el
esquema tiene `items`).

## 5. Handler Python opcional

```python
from webassistant_skill_sdk import SkillContext, SkillHandler


class Handler(SkillHandler):
    def prepare_result(self, ctx: SkillContext, result: dict) -> dict:
        # ctx.sources: fragmentos recuperados (RetrievedSource); ctx.locale, ctx.scopes, ctx.config
        return result

    def build_presentation(self, ctx: SkillContext, result: dict) -> dict:
        return result

    def build_chat_reply(self, ctx: SkillContext, result: dict, chat_reply: str) -> str:
        return chat_reply
```

El handler no recibe credenciales ni accede a la base de datos: trabaja sobre el resultado y las fuentes
ya autorizadas.

## 6. Crear, validar e instalar

```bash
uv run webassistant-skill new horarios_tienda --dir ./mis-skills    # genera el esqueleto
uv run webassistant-skill validate ./mis-skills/horarios_tienda     # manifiesto, esquemas y ejemplo
```

En pruebas de Python, `assert_skill_contract(ruta)` falla si el paquete no es válido.

Instalación sin tocar el núcleo, por cualquiera de estas vías:

1. **Directorio**: `WEBASSISTANT_SKILL_PATHS=/opt/webassistant/skills` (en Compose, monta el directorio
   en API y worker). Ejemplo: [`examples/skills/store_hours`](../examples/skills/store_hours).
2. **Paquete Python** con *entry point* `webassistant.skills` que devuelva las rutas de sus paquetes.

Después: consola → Plataforma → *Volver a descubrir*; la skill aparece en el registro con su estado y
diagnóstico (una skill inválida o incompatible se desactiva sin afectar a las demás). Añádela al
borrador del assistant (`skills: [{name: horarios_tienda, config: {...}}]`) y publica: la publicación
comprueba que todas las skills están disponibles.

## 7. Buenas prácticas

- Esquemas cerrados (`additionalProperties: false`) y con longitudes máximas.
- `intent` y `when_to_use` concretos: son lo que el modelo usa para elegir.
- Nunca confíes en datos del modelo para acciones: valídalos en el esquema de la acción y, si son
  productos o documentos, usa los procesadores que los contrastan con las fuentes.
- Prueba la skill con el proveedor simulado y con un modelo real antes de publicarla.
