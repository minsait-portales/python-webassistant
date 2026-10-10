"""Trazas distribuidas (CG-13.2): spans propios y propagación del contexto a los trabajos."""

from __future__ import annotations

import json
import subprocess
import sys
import textwrap

SCRIPT = textwrap.dedent(
    """
    import asyncio, json
    from opentelemetry import trace
    from opentelemetry.sdk.trace import TracerProvider
    from opentelemetry.sdk.trace.export import SimpleSpanProcessor
    from opentelemetry.sdk.trace.export.in_memory_span_exporter import InMemorySpanExporter

    exporter = InMemorySpanExporter()
    provider = TracerProvider()
    provider.add_span_processor(SimpleSpanProcessor(exporter))
    trace.set_tracer_provider(provider)

    from webassistant.observability import context
    from webassistant.observability.tracing import inject_context, job_span, traced

    @traced("model.chat", lambda provider_name, model: {"provider": provider_name, "model": model})
    async def call(provider_name, model):
        return inject_context()

    with context.bound(tenant_id="ten_a", run_id="run_1"):
        carrier = asyncio.run(call("openai", "gpt-6-luna"))
    with job_span("process_document", carrier):
        pass
    from sqlalchemy import create_engine, text
    from webassistant.observability.tracing import instrument_engine
    engine = create_engine("sqlite://")
    instrument_engine(engine)
    with engine.connect() as conn:
        conn.execute(text("SELECT 1"))
    spans = {s.name: s for s in exporter.get_finished_spans()}
    model, job = spans["model.chat"], spans["job process_document"]
    print(json.dumps({
        "carrier": sorted(carrier),
        "same_trace": model.context.trace_id == job.context.trace_id,
        "parent_is_model": job.parent is not None and job.parent.span_id == model.context.span_id,
        "attributes": dict(model.attributes),
        "sql_spans": [n for n in spans if n.startswith("SELECT")],
    }))
    """
)


def test_spans_carry_context_and_link_queue_jobs() -> None:
    output = subprocess.run(
        [sys.executable, "-c", SCRIPT], check=True, capture_output=True, text=True
    ).stdout
    result = json.loads(output.strip().splitlines()[-1])
    assert result["carrier"] == ["traceparent"]
    assert result["same_trace"] is True
    assert result["parent_is_model"] is True
    assert result["attributes"]["webassistant.provider"] == "openai"
    assert result["attributes"]["webassistant.model"] == "gpt-6-luna"
    assert result["attributes"]["webassistant.tenant_id"] == "ten_a"
    assert result["attributes"]["webassistant.run_id"] == "run_1"
    assert result["sql_spans"], "La instrumentación de SQLAlchemy no generó spans"
