# Registro de decisiones de arquitectura (ADR)

Formato: contexto, decisión, alternativas y consecuencias. Una ADR aceptada solo se sustituye por otra
nueva que la cite. Las decisiones acordadas con los equipos de cada plataforma (CG-17.2) se registran
aquí con el mismo formato.

| ADR | Decisión | Estado |
| --- | --- | --- |
| [0001](0001-stack-python-fastapi.md) | Stack: Python 3.12, FastAPI asíncrona, SQLAlchemy 2, uv | Aceptada |
| [0002](0002-widget-en-la-pagina.md) | Widget montado en la página con Shadow DOM, loader estable y bundle con SRI | Aceptada |
| [0003](0003-aislamiento-multitenant.md) | Aislamiento multi-tenant: RLS forzada, filtros obligatorios en Qdrant y tokens con scopes | Aceptada |
| [0004](0004-streaming-sse.md) | Streaming con SSE reanudable y eventos en Redis | Aceptada |
| [0005](0005-cola-redis-arq.md) | Cola de trabajos con Redis y arq | Aceptada |
| [0006](0006-rag-originales-en-portal.md) | RAG con documentos canónicos; los originales permanecen en el portal | Aceptada |
| [0007](0007-skills-portables.md) | Skills como paquetes portables con contrato y código opcional | Aceptada |
| [0008](0008-gateway-modelos-y-costes.md) | Gateway de modelos con perfiles, precios versionados y políticas | Aceptada |
| [0009](0009-contratos-api-first.md) | Contratos API-first: OpenAPI 3.1, JSON Schema y SDK generados | Aceptada |
| [0010](0010-acciones-confirmadas-idempotentes.md) | Acciones con confirmación, idempotencia y host actions | Aceptada |
| [0011](0011-topologia-piloto-y-produccion.md) | Topología: piloto no HA con Compose y objetivo de producción separado | Aceptada |
| [0012](0012-backups-y-recuperacion.md) | Estrategia de copias y recuperación | Aceptada |
| [0013](0013-responsabilidades-portal-producto.md) | Reparto de responsabilidades entre portal y producto | Aceptada |
