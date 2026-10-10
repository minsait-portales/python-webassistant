"""Métricas Prometheus (CG-13.3): latencia, errores, tokens, coste, colas, RAG y acciones."""

from __future__ import annotations

from prometheus_client import CollectorRegistry, Counter, Gauge, Histogram

REGISTRY = CollectorRegistry(auto_describe=True)

_LATENCY_BUCKETS = (0.05, 0.1, 0.25, 0.5, 1, 2, 3, 5, 8, 13, 21, 34, 60)

HTTP_REQUESTS = Counter(
    "webassistant_http_requests_total",
    "Peticiones HTTP por ruta, método y estado",
    ["route", "method", "status"],
    registry=REGISTRY,
)
HTTP_LATENCY = Histogram(
    "webassistant_http_request_duration_seconds",
    "Latencia HTTP por ruta",
    ["route", "method"],
    buckets=_LATENCY_BUCKETS,
    registry=REGISTRY,
)
RUNS = Counter(
    "webassistant_runs_total",
    "Runs finalizados por resultado y skill",
    ["tenant", "assistant", "skill", "status"],
    registry=REGISTRY,
)
RUN_LATENCY = Histogram(
    "webassistant_run_duration_seconds",
    "Duración extremo a extremo de un run",
    ["tenant", "assistant"],
    buckets=_LATENCY_BUCKETS,
    registry=REGISTRY,
)
RUNS_IN_PROGRESS = Gauge("webassistant_runs_in_progress", "Runs en curso", registry=REGISTRY)
PROVIDER_CALLS = Counter(
    "webassistant_provider_calls_total",
    "Llamadas a proveedores de modelos",
    ["provider", "model", "operation", "status"],
    registry=REGISTRY,
)
PROVIDER_LATENCY = Histogram(
    "webassistant_provider_call_duration_seconds",
    "Latencia de llamadas a proveedor",
    ["provider", "model", "operation"],
    buckets=_LATENCY_BUCKETS,
    registry=REGISTRY,
)
TOKENS = Counter(
    "webassistant_tokens_total",
    "Tokens consumidos por tipo",
    ["tenant", "provider", "model", "kind"],
    registry=REGISTRY,
)
COST = Counter(
    "webassistant_cost_total",
    "Coste estimado acumulado (moneda del precio)",
    ["tenant", "provider", "model", "currency"],
    registry=REGISTRY,
)
RAG_SEARCHES = Counter(
    "webassistant_rag_searches_total",
    "Búsquedas RAG por resultado",
    ["tenant", "outcome"],
    registry=REGISTRY,
)
RAG_LATENCY = Histogram(
    "webassistant_rag_search_duration_seconds",
    "Latencia de búsqueda RAG",
    buckets=_LATENCY_BUCKETS,
    registry=REGISTRY,
)
RAG_HITS = Histogram(
    "webassistant_rag_hits",
    "Resultados devueltos por búsqueda RAG",
    buckets=(0, 1, 2, 3, 5, 8, 13, 21),
    registry=REGISTRY,
)
ACTIONS = Counter(
    "webassistant_actions_total",
    "Acciones por tipo y estado",
    ["skill", "action", "type", "status"],
    registry=REGISTRY,
)
INGEST_DOCUMENTS = Counter(
    "webassistant_ingest_documents_total",
    "Documentos procesados por la ingesta",
    ["outcome"],
    registry=REGISTRY,
)
QUEUE_DEPTH = Gauge(
    "webassistant_queue_depth", "Trabajos pendientes en cola", ["queue"], registry=REGISTRY
)
POLICY_REJECTIONS = Counter(
    "webassistant_policy_rejections_total",
    "Rechazos por política (límites, presupuesto, concurrencia)",
    ["tenant", "reason"],
    registry=REGISTRY,
)
RATE_LIMITED = Counter(
    "webassistant_rate_limited_total",
    "Peticiones rechazadas por rate limit",
    ["scope"],
    registry=REGISTRY,
)
CIRCUIT_OPEN = Gauge(
    "webassistant_circuit_open",
    "Circuit breaker abierto (1) por proveedor/modelo",
    ["key"],
    registry=REGISTRY,
)
