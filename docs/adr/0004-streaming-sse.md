# ADR 0004 · Streaming con Server-Sent Events reanudables

**Contexto.** Las respuestas tardan segundos; el usuario debe ver progreso y poder detener el run. Hay
varias réplicas de API y redes móviles inestables.

**Decisión.** `POST .../messages` con `Accept: text/event-stream` devuelve SSE (`run.started`,
`run.status`, `reply.delta`, `run.completed|failed|cancelled`, `heartbeat`). Los eventos se guardan en
Redis con secuencia; `GET .../runs/{id}/events` con `Last-Event-ID` reanuda desde cualquier réplica. La
cancelación se propaga por Redis. Sin `Accept: text/event-stream` la API responde JSON completo.

**Alternativas.** WebSockets (más complejidad en proxies, balanceadores y CSP); polling (latencia).

**Consecuencias.** El gateway desactiva el buffer (`flush_interval -1`, `proxy-buffering off`) y amplía
los tiempos de lectura. El widget reintenta la reanudación dos veces antes de mostrar error.
