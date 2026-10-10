# ADR 0010 · Acciones con confirmación, idempotencia y host actions

**Decisión.** Toda acción se prepara (validación de permisos y parámetros) antes de ejecutarse. Las
acciones con efectos exigen un token de confirmación de un solo uso (2 min) y `Idempotency-Key`. Las
acciones del portal (carrito, formularios, perfil, informes) son **host actions**: el servidor autoriza y
registra; la página ejecuta su handler y devuelve el resultado con el `correlation_id`.

**Consecuencias.** No se duplican efectos ante reintentos; el modelo nunca ejecuta acciones por sí
mismo; los portales mantienen la lógica de negocio que ya poseen.
