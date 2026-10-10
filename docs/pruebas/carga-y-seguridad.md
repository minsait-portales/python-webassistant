# Informe de rendimiento y seguridad (CG-15.10)

Versión evaluada: `1.0.0-rc.1` (imagen `webassistant:local` construida con `docker/Dockerfile`).
Fecha: 10/10/2026. Entorno: stack de Docker Compose del piloto (API, worker, PostgreSQL 16 con rol sin
`BYPASSRLS`, Redis 7, Qdrant 1.19) en un servidor de 4 vCPU y 15 GB de RAM, demo cargada y **proveedor de
IA simulado**. Las medidas no incluyen la latencia de OpenAI ni la del portal anfitrión y **no acreditan
un SLA extremo a extremo de producción**.

## 1. Resumen

| Criterio de aceptación | Objetivo | Resultado | Estado |
| --- | --- | --- | --- |
| p95 de endpoints locales | ≤ 2 s | 0,30 s (peor endpoint: mensaje de chat) | ✅ |
| Respuestas 5xx | < 1 % | 0 de 19.870 (0 %) | ✅ |
| Fugas entre usuarios/tenants | 0 | 0 en 1.540 sondas del ensayo; pruebas negativas en verde | ✅ |
| Hallazgos críticos o altos en SAST | 0 | 0 (bandit, 14.368 líneas) | ✅ |
| Vulnerabilidades en dependencias | 0 críticas/altas | 0 (85 paquetes Python, npm sin avisos) | ✅ |
| Hallazgos altos en DAST (OWASP ZAP) | 0 | 0 en los tres modos (§5.2) | ✅ |
| Pruebas dinámicas propias | Todas | 12/12 | ✅ |

## 2. Perfil de carga

`scripts/loadtest.py`: 20 usuarios virtuales durante 30 minutos (rampa de 20 s), 3–6 s de reflexión
entre interacciones. Cada usuario: bootstrap, sesión anónima, conversación y, en bucle, un mensaje
representativo (10 consultas: productos, evento, devoluciones, envíos, manual técnico, comparación,
carrito, garantía y fuera de ámbito), lectura del historial, acciones del portal (preparar, ejecutar con
`Idempotency-Key` y reportar) en el 30 % de las respuestas con acciones, sondas de aislamiento (lectura
de la conversación de otro usuario) en el 20 % de las iteraciones y cambio de visitante en el 5 %.
Cada usuario usa su propia IP (cabecera `X-Forwarded-For` desde la red del gateway) para que los límites
por IP se comporten como en producción.

## 3. Resultados (ejecución definitiva)

Evidencia: [`evidencias/carga-piloto.json`](evidencias/carga-piloto.json).
19.870 peticiones en 1.805 s (11 peticiones/s sostenidas), 0 errores de servidor, 0 errores de
transporte, 0 respuestas 429.

| Endpoint | Peticiones | p50 | p95 | p99 | Máx. |
| --- | --- | --- | --- | --- | --- |
| Mensaje de chat (agente + RAG + skill, simulado) | 7.584 | 0,199 s | 0,299 s | 0,488 s | 1,529 s |
| Historial | 7.584 | 0,017 s | 0,039 s | 0,064 s | 0,606 s |
| Sonda de aislamiento (debe dar 404) | 1.540 | 0,012 s | 0,031 s | 0,050 s | 0,334 s |
| Preparar acción | 673 | 0,022 s | 0,044 s | 0,065 s | 0,612 s |
| Ejecutar acción | 673 | 0,023 s | 0,051 s | 0,082 s | 0,533 s |
| Resultado de host action | 673 | 0,017 s | 0,042 s | 0,072 s | 0,108 s |
| Bootstrap | 381 | 0,006 s | 0,020 s | 0,033 s | 0,063 s |
| Sesión | 381 | 0,007 s | 0,021 s | 0,081 s | 0,450 s |
| Crear conversación | 381 | 0,014 s | 0,038 s | 0,064 s | 0,073 s |

Skills ejercitadas: `content_answer`, `document_lookup`, `event_product_selection`, `product_comparison`,
`product_recommendations`, `product_results`, `shopping_cart_view` y respuestas fuera de ámbito.

### Incidencias detectadas y corregidas

Una primera ejecución de 30 minutos ([`evidencias/carga-piloto-run1.json`](evidencias/carga-piloto-run1.json))
también superó los criterios (19.857 peticiones, p95 0,26 s, 0 fugas), pero reveló:

1. **Un error de transporte** (`RemoteProtocolError`, 1 de 19.857): carrera entre el *keep-alive* de 5 s
   de Uvicorn y clientes que reutilizan conexiones tras 3–6 s de inactividad. Corrección: `--timeout-keep-alive
   75` en la imagen (debe superar el tiempo de inactividad del balanceador). Segunda ejecución: 0.
2. **Respuestas 429 por el límite del site** (600 peticiones/min) con 20 usuarios activos (~660/min).
   Corrección: límite por defecto de 1.200/min, configurable por tenant y assistant. Segunda ejecución: 0.
3. Un artefacto del propio script (tras un 429 reutilizaba la conversación anterior con un token nuevo y
   recibía 404, que es el comportamiento correcto de aislamiento). Corregido en el script.

## 4. Seguridad estática y de dependencias

| Herramienta | Alcance | Resultado | Evidencia |
| --- | --- | --- | --- |
| bandit 1.8.6 | API, worker y SDK de skills (14.368 líneas) | 0 hallazgos. Se corrigió un uso de SHA-1 en el proveedor simulado y un `assert` funcional; 6 falsos positivos justificados en línea (`# nosec` con motivo) | [`sast-bandit.json`](evidencias/sast-bandit.json) |
| ruff (reglas `S`/bandit) | Todo el código Python | Sin hallazgos | CI |
| pip-audit 2.9.0 | 85 dependencias Python de producción (`uv.lock`) | Sin vulnerabilidades conocidas | [`dependencias-python.json`](evidencias/dependencias-python.json) |
| npm audit | Dependencias Node | 0 vulnerabilidades | [`dependencias-node.json`](evidencias/dependencias-node.json) |
| ESLint | Widget, consola y SDK | Prohíbe `innerHTML`/`outerHTML`/`eval`; sin infracciones | CI |
| gitleaks | Historial del repositorio | En CI | CI |

## 5. Seguridad dinámica

### 5.1 Pruebas propias del producto

`scripts/security_probe.py` contra el stack: [`seguridad-dinamica.json`](evidencias/seguridad-dinamica.json).

| Comprobación | Resultado |
| --- | --- |
| Las 74 operaciones protegidas del contrato OpenAPI (80 en total) rechazan peticiones sin token | ✅ |
| Un token de widget no sirve en administración ni en ingesta (401) | ✅ |
| Un token sin firma (`alg: none`) se rechaza | ✅ |
| Un token de widget no se acepta desde otro origen (403) | ✅ |
| CORS no autoriza orígenes no registrados; bootstrap los rechaza | ✅ |
| Cabeceras de seguridad (CSP, `nosniff`, `X-Frame-Options`, `Referrer-Policy`) en consola y demo | ✅ |
| Respuestas `/v1` con `Cache-Control: no-store` | ✅ |
| Entradas hostiles (SQL, XSS, plantillas, recorrido de rutas, prompt injection, caracteres de control, JSON roto, 2 MB) sin 5xx ni trazas internas | ✅ |
| Sin *path traversal* en activos estáticos | ✅ |

Estas pruebas detectaron un **defecto real**: un mensaje con el carácter NUL (`U+0000`) provocaba un
error 500 al persistir en PostgreSQL. Corrección: la API rechaza con `422 validation_error` cualquier
cuerpo JSON con NUL y la extracción de originales elimina los caracteres de control. Prueba de regresión
en `test_api_chat::test_message_limits` y `test_units`.

### 5.2 OWASP ZAP

`scripts/dast.sh` ejecuta `zap-api-scan` 2.16.1 (escaneo pasivo y activo a partir del OpenAPI de la API
v1, 106 reglas) sin credenciales, con el token del portal (ingesta y búsqueda) y con el token de
administración. Criterio: cero alertas de riesgo alto; las de riesgo bajo se revisan una a una.
Se versiona un resumen por modo (recuentos, alertas y ejemplos); los informes completos de ZAP (HTML y
JSON, unos 10 MB por modo) se generan en `<directorio>/zap/` y el CI los conserva como artefacto
`dast-zap`.

| Modo | Altas | Medias | Bajas | Informativas | Evidencia |
| --- | --- | --- | --- | --- | --- |
| Sin credenciales | 0 | 0 | 0 | 3 | [`dast-anon.json`](evidencias/dast-anon.json) |
| Portal (server-to-server) | 0 | 0 | 0 | 3 | [`dast-server.json`](evidencias/dast-server.json) |
| Administración (tras correcciones) | 0 | 0 | 0 | 3 | [`dast-admin.json`](evidencias/dast-admin.json) |

La primera pasada en modo administración (0 altas, 0 medias) registró dos alertas de riesgo bajo:

1. **Respuestas 5xx con parámetros manipulados** (44 instancias): `GET /v1/admin/usage` (y los informes
   de uso del tenant) devolvían 500 con fechas no válidas, y `POST /v1/admin/embedding-indexes` devolvía
   503 con un proveedor inexistente. **Corregido**: las fechas se validan (`422 validation_error` con la
   ruta del campo y comprobación de periodo) y el proveedor debe estar configurado (`422`). Prueba de
   regresión `test_admin::test_invalid_admin_inputs_return_validation_errors`.
2. **Timestamp Disclosure** (1 instancia): falso positivo por los dígitos de un importe decimal en el
   informe de uso; se ignora con justificación en [`.zap/rules.tsv`](../../.zap/rules.tsv).

La segunda pasada, sobre un stack limpio con la imagen corregida, terminó sin alertas de riesgo bajo o
superior (106 reglas superadas).

Las alertas informativas corresponden al *fuzzing* del User-Agent, a respuestas 4xx esperadas ante
entradas inválidas y a la identificación del endpoint de autenticación.

## 6. Aislamiento entre tenants

- Pruebas negativas automatizadas (SQLite y PostgreSQL real con RLS forzada y rol sin `BYPASSRLS`):
  lectura, escritura, búsqueda y acciones entre tenants, sites, usuarios y ACL —
  `test_isolation_and_ingest.py`, `test_postgres.py` (117/117 pruebas en PostgreSQL).
- Ensayo de carga: 1.540 lecturas cruzadas de conversaciones, todas rechazadas (404).
- Resultado: **cero lecturas o acciones no autorizadas**.

## 7. Decisión

CG-15.10 **superado** para el piloto no HA: se cumplen los criterios de rendimiento, errores,
aislamiento y seguridad, y los defectos encontrados durante el ensayo (keep-alive, límite por site,
carácter NUL y validación de entradas de administración) están corregidos con pruebas de regresión.
Riesgos aceptados: no se ha realizado un pentest independiente (fuera de alcance) y las medidas usan el
proveedor simulado.
