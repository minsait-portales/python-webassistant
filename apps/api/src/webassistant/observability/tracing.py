"""Trazas distribuidas con OpenTelemetry (CG-13.2).

Si ``WEBASSISTANT_OTEL_ENABLED`` está activo se exportan spans por OTLP/HTTP desde la API y el
worker: peticiones HTTP (FastAPI), consultas a PostgreSQL (SQLAlchemy), Redis, llamadas HTTP
salientes (proveedores de modelos, Qdrant, descargas) y spans propios del agente, el gateway de
modelos, el RAG, las acciones y la ingesta. El contexto viaja de la API a los trabajos del worker
con la cabecera W3C ``traceparent``. Sin activar, los spans son no-op y no tienen coste apreciable.
"""

from __future__ import annotations

import functools
from collections.abc import Awaitable, Callable, Iterator
from contextlib import contextmanager
from typing import Any, ParamSpec, TypeVar

from opentelemetry import propagate, trace

from webassistant.observability import context

_tracer = trace.get_tracer("webassistant")
_instrumented: set[str] = set()

P = ParamSpec("P")
R = TypeVar("R")


def setup_tracing(
    service_name: str,
    endpoint: str | None,
    app: Any | None = None,
    engines: list[Any] | None = None,
) -> None:
    from opentelemetry.exporter.otlp.proto.http.trace_exporter import OTLPSpanExporter
    from opentelemetry.sdk.resources import Resource
    from opentelemetry.sdk.trace import TracerProvider
    from opentelemetry.sdk.trace.export import BatchSpanProcessor

    if "provider" not in _instrumented:
        provider = TracerProvider(resource=Resource.create({"service.name": service_name}))
        exporter = (
            OTLPSpanExporter(endpoint=f"{endpoint.rstrip('/')}/v1/traces")
            if endpoint
            else OTLPSpanExporter()
        )
        provider.add_span_processor(BatchSpanProcessor(exporter))
        trace.set_tracer_provider(provider)
        _instrumented.add("provider")
    if "clients" not in _instrumented:
        from opentelemetry.instrumentation.httpx import HTTPXClientInstrumentor
        from opentelemetry.instrumentation.redis import RedisInstrumentor

        HTTPXClientInstrumentor().instrument()
        RedisInstrumentor().instrument()
        _instrumented.add("clients")
    for engine in engines or []:
        instrument_engine(engine)
    if app is not None:
        from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor

        FastAPIInstrumentor.instrument_app(app, excluded_urls="health/live,health/ready,metrics")


def instrument_engine(engine: Any) -> None:
    """Instrumenta un engine de SQLAlchemy (``AsyncEngine.sync_engine``)."""
    from opentelemetry.instrumentation.sqlalchemy import SQLAlchemyInstrumentor

    key = f"engine:{id(engine)}"
    if key not in _instrumented:
        # La instrumentación declara sqlalchemy < 2.1, pero usa eventos de cursor estables en 2.1;
        # test_tracing comprueba que se generan los spans SQL con la versión instalada.
        SQLAlchemyInstrumentor().instrument(
            engine=engine, enable_commenter=False, skip_dep_check=True
        )
        _instrumented.add(key)


@contextmanager
def span(name: str, **attributes: Any) -> Iterator[Any]:
    with _tracer.start_as_current_span(name) as current:
        for key, value in {**context.snapshot(), **attributes}.items():
            if value is not None:
                current.set_attribute(f"webassistant.{key}", str(value))
        yield current


def traced(
    name: str, attributes: Callable[..., dict[str, Any]] | None = None
) -> Callable[[Callable[P, Awaitable[R]]], Callable[P, Awaitable[R]]]:
    """Decorador para corrutinas: abre un span con atributos derivados de los argumentos."""

    def decorator(function: Callable[P, Awaitable[R]]) -> Callable[P, Awaitable[R]]:
        @functools.wraps(function)
        async def wrapper(*args: P.args, **kwargs: P.kwargs) -> R:
            values = attributes(*args, **kwargs) if attributes else {}
            with span(name, **values):
                return await function(*args, **kwargs)

        return wrapper

    return decorator


def inject_context() -> dict[str, str]:
    """Contexto de traza actual en formato W3C para enviarlo a un trabajo en cola."""
    carrier: dict[str, str] = {}
    propagate.inject(carrier)
    return carrier


@contextmanager
def job_span(name: str, carrier: dict[str, str] | None) -> Iterator[Any]:
    """Span del worker enlazado con la traza de la petición que encoló el trabajo."""
    parent = propagate.extract(carrier or {})
    with _tracer.start_as_current_span(
        f"job {name}", context=parent, kind=trace.SpanKind.CONSUMER
    ) as current:
        current.set_attribute("webassistant.job", name)
        yield current
