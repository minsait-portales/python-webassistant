# Modelo de amenazas (CG-00.5)

Metodología STRIDE sobre los activos y fronteras de confianza del producto. Severidad = impacto ×
probabilidad (Alta, Media, Baja). Cada riesgo tiene mitigación implementada, evidencia y responsable.

## Activos

Conversaciones y datos personales; documentos y derivados RAG con ACL; configuración y prompts;
credenciales (clientes API, OpenAI, JWT, clave de cifrado); presupuesto de modelos; acciones con efectos
en el portal; auditoría.

## Fronteras de confianza

1. Navegador / página anfitriona ↔ API (Internet, origen controlado por terceros).
2. Backend del portal ↔ API (server-to-server).
3. API ↔ proveedor de modelos (salida no confiable).
4. API/worker ↔ PostgreSQL, Redis, Qdrant (red privada).
5. Administradores ↔ consola.

## Riesgos y mitigaciones

| ID | Amenaza (STRIDE) | Sev. | Mitigación | Evidencia | Responsable |
| --- | --- | --- | --- | --- | --- |
| T-01 | Lectura de datos de otro tenant (I) | Alta | RLS forzada con rol sin BYPASSRLS, filtros en repositorios, `SearchScope` obligatorio en Qdrant | `test_postgres`, `test_isolation_and_ingest`, sondas de carga | Arquitecto |
| T-02 | Suplantación del widget desde otro origen (S) | Alta | Orígenes registrados por site, CORS por site, token ligado al origen | `test_api_chat`, `test_demo` | Arquitecto |
| T-03 | Robo o reutilización de tokens (S) | Media | JWT cortos (15 min), audiencias separadas, `jti`, rotación con secretos anteriores; el widget no guarda secretos | `test_units` | Arquitecto |
| T-04 | XSS por contenido del modelo o de documentos (T/E) | Alta | DOM seguro sin `innerHTML` (regla ESLint), URLs solo http/https, Shadow DOM, CSP estricta en consola y demo | `dom.test.ts`, `renderers.test.ts`, E2E CSP | Frontend |
| T-05 | Prompt injection (instrucciones en documentos o mensajes) (T/E) | Alta | El modelo no ejecuta acciones: solo propone; validación de esquema; acciones con permisos y confirmación; productos y citas verificados contra fuentes reales; herramientas permitidas por política | `test_agent`, `test_skills`, `test_api_actions` | Arquitecto |
| T-06 | Acción con efectos duplicada o no autorizada (T/R) | Alta | Preparación con permisos y JSON Schema, token de confirmación de un uso, `Idempotency-Key`, auditoría | `test_api_actions` | Arquitecto |
| T-07 | Abuso de coste (bucle o volumen) (D) | Alta | Rate limits por IP/site/usuario, presupuestos con rechazo, límites de iteraciones/herramientas, detección de bucles, concurrencia por usuario | `test_agent`, `test_units`, alertas | Operación |
| T-08 | Denegación de servicio por peticiones grandes (D) | Media | Límite de tamaño de cuerpo, longitud de mensaje, tiempo de petición, descargas con tamaño y tiempo máximos | `test_api_chat` | Arquitecto |
| T-09 | SSRF en la ingesta por URL (I/E) | Alta | Solo https (http en desarrollo), sin redirecciones, lista de hosts obligatoria en staging/producción y, sin lista, bloqueo de destinos privados, loopback y metadatos de nube; tamaño y tiempo máximos. Riesgo residual: *DNS rebinding* entre la comprobación y la conexión cuando no hay lista (mitigado exigiendo lista en producción) | `test_downloads` | Arquitecto |
| T-10 | Filtrado de documentos a usuarios sin permiso (I) | Alta | ACL en payload y en PostgreSQL, propagación de cambios de ACL, principales del token | `test_isolation_and_ingest` | Arquitecto |
| T-11 | Exposición de secretos en logs o respuestas (I) | Alta | Redacción de tokens, claves, correos, teléfonos, tarjetas, IBAN y DNI; secretos con `SecretStr`; hash de secretos de clientes; payloads cifrados | `test_units` | Operación |
| T-12 | Escalada de privilegios en la consola (E) | Alta | Matriz rol→permiso, comprobación por endpoint, separación plataforma/tenant, bloqueo por intentos fallidos, auditoría | `test_admin`, E2E permisos | Arquitecto |
| T-13 | Repudio de cambios de configuración (R) | Media | Auditoría de publicaciones, restauraciones, importaciones, rotaciones y lecturas de payloads | `test_admin` | Operación |
| T-14 | Cadena de suministro (T) | Media | Lockfiles, SRI del bundle, imágenes con versión fija, Dependabot, pip-audit, npm audit, SBOM y procedencia en la release | CI, `release.yml` | Operación |
| T-15 | CSS/JS de la página rompe o espía el widget (T/I) | Media | Shadow DOM con `:host { all: initial !important }`; no se exponen tokens a la página salvo el que el portal entrega | E2E CSS agresivo | Frontend |
| T-16 | Salida del modelo malformada o maliciosa (T) | Media | Validación JSON Schema, procesadores, reparación única y degradación a texto | `test_agent`, `test_skills` | Arquitecto |
| T-19 | Entradas con caracteres de control que rompen la persistencia (D) | Media | Rechazo de cuerpos JSON con NUL (`422`) y limpieza de caracteres de control en textos extraídos | `test_api_chat`, `test_units`, `security_probe.py` | Arquitecto |
| T-17 | Pérdida de datos (D) | Alta | Backups externos cifrados, restauración ensayada, conciliación con Qdrant | `scripts/restore.sh`, [runbooks](../runbooks.md) | Operación |
| T-18 | Acceso a servicios internos (E) | Media | Solo el gateway es público; métricas no expuestas; NetworkPolicies en Kubernetes; API key de Qdrant en producción | `compose.prod.yaml`, `deploy/k8s` | Operación |

## Riesgos aceptados y pendientes

- Un pentest independiente y la auditoría externa de accesibilidad no forman parte del alcance (ver
  documento, sección 10); se recomiendan antes de producción.
- La revisión DAST automatizada cubre la superficie pública; los resultados están en
  [carga y seguridad](../pruebas/carga-y-seguridad.md).
