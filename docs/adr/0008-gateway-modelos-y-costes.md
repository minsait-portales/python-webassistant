# ADR 0008 · Gateway de modelos, precios versionados y políticas

**Contexto.** Hay que medir el coste por entrada, entrada en caché y salida según el modelo, aplicar
presupuestos y tolerar caídas del proveedor.

**Decisión.** Un `ModelGateway` aísla a los proveedores (OpenAI Chat Completions con herramientas y un
proveedor simulado determinista). Aplica perfiles por modelo (p. ej. `gpt-6-luna` sin razonamiento en
llamadas con herramientas), reintentos con jitter, circuit breaker, cancelación, listas de modelos
permitidos, presupuestos diario/mensual con umbral de alerta y límites de concurrencia. Cada llamada
genera un `usage_event` con el precio vigente (tabla versionada con `valid_from`/`valid_to`).

**Consecuencias.** Los costes históricos no cambian al publicar precios nuevos; el proveedor simulado
permite pruebas y ensayos de carga sin coste.
