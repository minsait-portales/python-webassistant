# Runbooks (CG-13.5)

Cada runbook indica síntomas, diagnóstico, acciones y verificación. Responsables por defecto: **Operación**
(guardia de la plataforma) y **Arquitecto** (escalado técnico); en el piloto ambos roles los asume el
equipo de desarrollo. Las alertas enlazan a la sección correspondiente.

Comandos de referencia (Compose; en Kubernetes usa `kubectl -n webassistant`):

```bash
docker compose ps                         # estado y salud de los servicios
docker compose logs --since 30m api worker | grep -E '"level": "(error|warning)"'
curl -s localhost:8000/health/ready       # dependencias críticas
```

En la consola: **Inicio** (diagnóstico de dependencias, cola y circuit breakers), **Conversaciones →
traza** (run, llamadas, herramientas y acciones) y **Auditoría**.

<a id="api-caida"></a>

## API caída

**Síntomas**: alerta `WebAssistantApiDown`; el widget no carga o muestra error de conexión.

1. `docker compose ps api` y `docker compose logs --tail 200 api`.
2. Si no arranca por configuración (`Configuración inválida: ...`), corrige la variable indicada.
3. Si `migrate` falló, revisa sus logs; no arranques la API sin migraciones.
4. Si `/health/ready` falla, sigue el runbook de la dependencia afectada (PostgreSQL, Redis o Qdrant).
5. Reinicia: `docker compose up -d api`.

**Verificación**: `/health/ready` correcto, carga de `/widget/v1/loader.js` y un mensaje de prueba.

<a id="worker-caido"></a>

## Worker caído

**Síntomas**: `WebAssistantWorkerDown`; documentos en `queued`; retención no aplicada.

1. `docker compose logs --tail 200 worker`; comprueba Redis (`docker compose exec redis redis-cli ping`).
2. Reinicia: `docker compose up -d worker`. Los trabajos pendientes se retoman (ids deterministas, sin
   duplicados).
3. Consola → Conocimiento → *Reintentar fallidos* si quedaron documentos en `failed`.

<a id="errores-5xx"></a>

## Tasa de errores 5xx

1. Grafana → *Peticiones HTTP por estado*; identifica la ruta.
2. Busca en logs los `correlation_id` de las respuestas 5xx (`"level": "error"`).
3. Errores `provider_*`: ver [proveedor](#proveedor). Errores de base de datos: comprueba conexiones
   (`database_pool_size`) y estado de PostgreSQL. `internal_error`: abre incidencia con la traza.
4. Si comenzó tras un despliegue, aplica el [rollback](#rollback).

<a id="latencia"></a>

## Latencia alta

1. Grafana → *Latencia de runs* y *Llamadas al proveedor*: distingue proveedor, RAG o base de datos.
2. Con trazas activas (Jaeger), abre un run lento y localiza el span dominante (`model.chat`,
   `rag.search`, consultas SQL).
3. Proveedor lento: valora un modelo más rápido en el assistant o reducir `max_output_tokens`.
4. RAG lento: revisa recursos de Qdrant y el tamaño de las colecciones; reduce `rag.max_amount`.
5. Saturación de la API: escala réplicas (producción) o recursos del servidor (piloto).

<a id="proveedor"></a>

## Proveedor de modelos caído o con errores

**Síntomas**: `WebAssistantProviderErrors`, `WebAssistantCircuitOpen`; usuarios reciben "El asistente no
está disponible en este momento".

1. Consola → Inicio: estado de los circuit breakers por modelo.
2. Comprueba la página de estado del proveedor y la cuota (`provider_quota`) o límites
   (`provider_rate_limited`).
3. Mitigación: cambia temporalmente el modelo del assistant a otro con precio publicado y publica la
   versión (rollback desde *Versiones* al terminar).
4. El circuito se cierra solo tras `WEBASSISTANT_CIRCUIT_BREAKER_RESET_SECONDS` si el proveedor responde.

<a id="cola"></a>

## Cola bloqueada

**Síntomas**: `WebAssistantQueueBacklog`; documentos sin indexar.

1. ¿Hay workers vivos? (`docker compose ps worker`). Escala: `docker compose up -d --scale worker=2`.
2. Logs de trabajos fallidos repetidamente (`agotó los reintentos`): suele ser un documento que no se
   puede descargar o extraer; corrígelo en el portal y reinténtalo.
3. Redis sin memoria: revisa `INFO memory`; la política es `noeviction`, no se pierden trabajos.

<a id="ingesta"></a>

## Fallos de ingesta

1. Consola → Conocimiento → documentos con estado `failed` y su error.
2. `Host de descarga no permitido` / `Destino de descarga no público`: añade el host del portal a
   `WEBASSISTANT_INGEST_ALLOWED_DOWNLOAD_HOSTS`.
3. `Descarga fallida: HTTP 401/403`: el portal debe enviar un `access_token` válido en la referencia.
4. `El documento supera el tamaño máximo`: ajusta `WEBASSISTANT_INGEST_MAX_DOWNLOAD_BYTES` o divide el
   documento.
5. Tras corregir: *Reintentar fallidos* o *Reindexar* el documento.

<a id="indice-inconsistente"></a>

## Índice inconsistente (PostgreSQL ↔ Qdrant)

**Síntomas**: búsquedas sin resultados para documentos `ready`, o resultados de documentos borrados.

1. Consola → Conocimiento → *Conciliar PostgreSQL y Qdrant* (también se ejecuta cada noche).
2. Compara el inventario del portal (`GET /v1/ingest/inventory/{kb}`) con sus fuentes y reenvía los
   documentos que falten.
3. Si la colección está dañada, crea una reindexación (Plataforma → Índices vectoriales) y actívala al
   terminar; la anterior queda como rollback.

<a id="presupuesto"></a>

## Coste anómalo o presupuesto agotado

**Síntomas**: `WebAssistantBudgetRejections`; usuarios ven "El asistente ha alcanzado el límite de uso configurado".

1. Consola → Uso y costes, agrupando por assistant, skill y día; identifica el origen del pico.
2. Auditoría y conversaciones: ¿abuso de un usuario, bucle de agente, integración que reintenta?
3. Mitigación: reduce límites del sujeto o del site, restringe modelos permitidos o el presupuesto del
   assistant afectado (Políticas y retención). Revoca el cliente API si el abuso viene de él.
4. Si es consumo legítimo, ajusta el presupuesto con la aprobación del responsable económico.

<a id="recuperacion"></a>

## Recuperación desde copias externas

Sigue [despliegue §4.2](despliegue.md#42-restauración-orden). Registra la hora de la última copia
utilizada (RPO real) y el tiempo hasta reabrir el tráfico (RTO real) en el informe del incidente.

<a id="rollback"></a>

## Rollback de una versión

1. Vuelve a la etiqueta de imagen anterior y `docker compose ... up -d --wait` (ver
   [despliegue §2.4](despliegue.md#24-actualización-y-rollback)).
2. Si la versión aplicó migraciones incompatibles, restaura la copia previa al despliegue.
3. Cambios de configuración funcional: consola → Assistants → *Restaurar* la versión anterior.

<a id="seguridad"></a>

## Seguridad: sospecha de fuga o credencial comprometida

1. Revoca o rota el cliente API afectado; rota `WEBASSISTANT_JWT_SECRET` (con el anterior en
   `WEBASSISTANT_JWT_PREVIOUS_SECRETS` si no quieres cerrar sesiones; sin él para invalidarlas todas).
2. Consulta Auditoría (prefijos `token.`, `action.`, `admin.`, `payload.`) y los logs por `correlation_id`.
3. Desactiva el site afectado si hay abuso desde un origen concreto.
4. Notifica según el procedimiento de incidentes de seguridad del cliente.
