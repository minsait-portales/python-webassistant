"""Trazas distribuidas con OpenTelemetry (CG-13.2).

Si ``WEBASSISTANT_OTEL_ENABLED`` está activo se exportan spans por OTLP/HTTP;
si no, los spans son no-op y no tienen coste apreciable.
"""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from typing import Any

from opentelemetry import trace

from webassistant.observability import context

_tracer = trace.get_tracer("webassistant")


def setup_tracing(service_name: str, endpoint: str | None, app: Any | None = None) -> None:
    from opentelemetry.exporter.otlp.proto.http.trace_exporter import OTLPSpanExporter
    from opentelemetry.sdk.resources import Resource
    from opentelemetry.sdk.trace import TracerProvider
    from opentelemetry.sdk.trace.export import BatchSpanProcessor

    provider = TracerProvider(resource=Resource.create({"service.name": service_name}))
    exporter = (
        OTLPSpanExporter(endpoint=f"{endpoint.rstrip('/')}/v1/traces")
        if endpoint
        else OTLPSpanExporter()
    )
    provider.add_span_processor(BatchSpanProcessor(exporter))
    trace.set_tracer_provider(provider)
    if app is not None:
        from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor

        FastAPIInstrumentor.instrument_app(app, excluded_urls="health/live,health/ready,metrics")


@contextmanager
def span(name: str, **attributes: Any) -> Iterator[Any]:
    with _tracer.start_as_current_span(name) as current:
        for key, value in {**context.snapshot(), **attributes}.items():
            if value is not None:
                current.set_attribute(f"webassistant.{key}", str(value))
        yield current
