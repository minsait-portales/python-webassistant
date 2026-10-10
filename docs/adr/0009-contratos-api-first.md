# ADR 0009 · Contratos API-first

**Decisión.** OpenAPI 3.1 generado desde la API (`webassistant export-openapi`) y JSON Schema 2020-12
para respuestas de chat, presentaciones, eventos SSE, eventos del widget, host actions, tema,
configuración de assistant, paquete de configuración y manifiesto de skills. Errores en
`application/problem+json` con códigos estables y `user_message`. El SDK TypeScript se genera con
`openapi-typescript`; CI falla si el contrato o los tipos derivan.

**Compatibilidad.** Dentro de `v1` solo se añaden campos opcionales, endpoints y códigos de error; los
renderers se versionan por tipo (`tipo@versión`). Un cambio incompatible exige `v2` y un periodo de
convivencia (ver [integración](../integracion.md#compatibilidad-y-versionado)).
