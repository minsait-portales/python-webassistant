# Requisitos no funcionales (CG-00.3)

Valores propuestos para el **piloto no HA**; los de producción se acuerdan con infraestructura antes de
promover la release ([release](../release.md)). Cada requisito indica cómo se mide.

## Rendimiento y carga (perfil de CG-15.10)

| Requisito | Objetivo piloto | Medición |
| --- | --- | --- |
| Usuarios concurrentes | 20 durante 30 min, 3–6 s entre interacciones | `scripts/loadtest.py` |
| p95 de endpoints locales (bootstrap, sesión, historial, acciones y chat con proveedor simulado) | ≤ 2 s | informe de carga |
| Respuestas 5xx | < 1 % | informe de carga |
| Fugas entre usuarios/tenants | 0 | sondas del ensayo + pruebas negativas |
| Primer evento SSE | ≤ 1 s tras el envío (sin contar el proveedor) | métricas `run_duration` / E2E |
| Tamaño del widget | loader ≤ 8 KB, bundle ≤ 120 KB sin comprimir | CI (`manifest.json`) |

Las medidas excluyen la latencia de OpenAI y del portal; no acreditan un SLA extremo a extremo.

## Disponibilidad y recuperación

| Requisito | Piloto no HA | Producción (propuesta) |
| --- | --- | --- |
| Disponibilidad | Mejor esfuerzo; punto único de fallo aceptado | ≥ 99,5 % mensual con API ≥ 2 réplicas en nodos distintos |
| RPO (pérdida máxima) | 24 h (copia diaria fuera del host) | ≤ 1 h (PITR de PostgreSQL + snapshots horarios de Qdrant) |
| RTO (recuperación) | 4 h en un servidor limpio | ≤ 1 h |
| Copias | Diarias, cifradas, 30 días, fuera del host | Gestionadas, cifradas, 35 días, otra región |
| Ensayo de restauración | Antes de la release y trimestral | Antes de producción y semestral |

La restauración del piloto se ha ensayado con `scripts/restore.sh` (ver [despliegue](../despliegue.md)).

## Seguridad y privacidad

- Cero hallazgos críticos o altos abiertos en SAST (bandit), dependencias (pip-audit, npm audit) y DAST.
- TLS en el gateway; HSTS en staging y producción; secretos fuera del repositorio y de las imágenes.
- Tokens de widget de 15 min, de servidor de 10 min y de administración de 1 h; rotación sin corte.
- Datos personales: el widget no envía PII a métricas ni eventos; los logs redactan correos, teléfonos,
  tokens y tarjetas; los payloads técnicos se cifran y su lectura queda auditada.
- Retención por defecto: conversaciones 90 días, payloads 30, auditoría 365, uso 730 (anonimizar).

## Accesibilidad

WCAG 2.1 AA en widget y consola: cero incidencias críticas o serias automatizables (axe) y revisión manual
con teclado, lector de pantalla, zoom 200–400 % y reducción de movimiento ([accesibilidad](../accesibilidad.md)).

## Compatibilidad

- Navegadores: dos últimas versiones de Chrome, Edge, Firefox y Safari (escritorio y móvil). El bundle se
  compila para Chrome/Edge 92, Firefox 90 y Safari 15.
- Idiomas: español e inglés en el widget; los textos de negocio se configuran por assistant.
- Formatos documentales: texto, Markdown, HTML, PDF y DOCX (extracción en el worker).

## Volumen documental del piloto

Hasta 50.000 documentos y 500.000 fragmentos por tenant, con documentos de hasta 50 MB descargados desde
hosts permitidos. Por encima, colección dedicada por tenant y dimensionamiento de Qdrant específico.

## Coste

Coste por llamada con entrada, entrada en caché y salida según el modelo; presupuestos diario y mensual por
tenant o assistant con alerta al 80 % y rechazo controlado al superarlos.

## Regiones

Despliegue en la región acordada con el cliente; los datos (PostgreSQL, Qdrant, Redis, backups) no salen
de ella. El proveedor de modelos se configura por entorno (OpenAI o endpoint compatible corporativo).
