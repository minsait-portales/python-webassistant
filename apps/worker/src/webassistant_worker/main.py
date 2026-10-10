"""Worker asíncrono de WebAssistant (cola arq respaldada por Redis).

Ejecuta la ingesta RAG (extracción, chunking, embeddings y publicación en
Qdrant), borrados, conciliación PostgreSQL/Qdrant, reindexado de embeddings y
la política de retención. Los trabajos son idempotentes y se reintentan con
backoff; tras agotar los intentos el documento queda en ``failed`` y puede
reintentarse desde la consola.

Uso: ``webassistant-worker`` (equivale a ``arq webassistant_worker.main.WorkerSettings``).
"""

from __future__ import annotations

import asyncio
import sys
from typing import Any

from arq import Retry
from arq.connections import RedisSettings
from arq.cron import cron
from arq.worker import Worker

from webassistant.config import get_settings
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.jobs import JOBS
from webassistant.observability.logging import configure_logging, get_logger
from webassistant.observability.tracing import job_span, setup_tracing
from webassistant.services.container import Services, build_services

logger = get_logger("webassistant.worker")

MAX_TRIES = 5
_NON_RETRYABLE = {
    ErrorCode.VALIDATION_ERROR,
    ErrorCode.FORBIDDEN,
    ErrorCode.PAYLOAD_TOO_LARGE,
    ErrorCode.NOT_FOUND,
}


async def startup(ctx: dict[str, Any]) -> None:
    settings = get_settings()
    configure_logging(
        settings.log_level, json_output=settings.log_json, service_name="webassistant-worker"
    )
    services = build_services(settings)
    if settings.otel_enabled:
        from webassistant.observability.tracing import instrument_engine

        instrument_engine(services.db.engine.sync_engine)
    await services.startup(create_schema=services.db.is_sqlite)
    ctx["services"] = services
    logger.info("Worker iniciado (cola %s)", settings.queue_name)


async def shutdown(ctx: dict[str, Any]) -> None:
    services: Services | None = ctx.get("services")
    if services is not None:
        await services.shutdown()


def _wrap(name: str) -> Any:
    function = JOBS[name]

    async def task(ctx: dict[str, Any], **kwargs: Any) -> Any:
        carrier = kwargs.pop("_trace", None)
        try:
            with job_span(name, carrier):
                return await function(ctx["services"], **kwargs)
        except AppError as exc:
            if exc.code in _NON_RETRYABLE or ctx.get("job_try", 1) >= MAX_TRIES:
                logger.error("Trabajo %s fallido sin reintento: %s", name, exc.code.value)
                return {"status": "failed", "code": exc.code.value}
            raise Retry(defer=min(300, 5 * 2 ** ctx.get("job_try", 1))) from exc
        except Exception as exc:
            if ctx.get("job_try", 1) >= MAX_TRIES:
                logger.exception("Trabajo %s agotó los reintentos", name)
                return {"status": "failed", "error": type(exc).__name__}
            raise Retry(defer=min(300, 5 * 2 ** ctx.get("job_try", 1))) from exc

    task.__name__ = name
    task.__qualname__ = name
    return task


async def nightly_retention(ctx: dict[str, Any]) -> Any:
    return await JOBS["apply_retention"](ctx["services"])


async def nightly_reconcile(ctx: dict[str, Any]) -> list[dict[str, Any]]:
    services: Services = ctx["services"]
    async with UnitOfWork(services.db, platform=True) as uow:
        tenant_ids = [t.id for t in await uow.tenants.list()]
    results = []
    for tenant_id in tenant_ids:
        results.append(
            {
                "tenant_id": tenant_id,
                **await JOBS["reconcile_tenant"](services, tenant_id=tenant_id),
            }
        )
    return results


def _redis_settings() -> RedisSettings:
    settings = get_settings()
    if not settings.redis_url:
        raise RuntimeError("El worker necesita WEBASSISTANT_REDIS_URL")
    return RedisSettings.from_dsn(settings.redis_url)


class WorkerSettings:
    functions = [_wrap(name) for name in JOBS]  # noqa: RUF012
    cron_jobs = [  # noqa: RUF012
        cron(nightly_retention, hour={3}, minute={15}, run_at_startup=False),
        cron(nightly_reconcile, hour={4}, minute={0}, run_at_startup=False),
    ]
    on_startup = startup
    on_shutdown = shutdown
    queue_name = get_settings().queue_name
    max_tries = MAX_TRIES
    job_timeout = 900
    keep_result = 3600
    max_jobs = 8
    health_check_interval = 30

    @staticmethod
    def redis_settings() -> RedisSettings:  # pragma: no cover - resuelto en tiempo de arranque
        return _redis_settings()


def check() -> int:
    """Comprobación de salud para contenedores: el worker publica una marca en Redis."""
    from arq.worker import async_check_health

    return asyncio.run(async_check_health(_redis_settings(), queue_name=get_settings().queue_name))


def main() -> None:
    if "--check" in sys.argv[1:]:
        raise SystemExit(check())
    settings = get_settings()
    configure_logging(
        settings.log_level, json_output=settings.log_json, service_name="webassistant-worker"
    )
    if settings.otel_enabled:
        setup_tracing("webassistant-worker", settings.otel_exporter_endpoint)
    if settings.metrics_enabled and settings.worker_metrics_port:
        # Métricas de ingesta, reindexado y proveedores del worker (Prometheus).
        from prometheus_client import start_http_server

        from webassistant.observability.metrics import REGISTRY

        start_http_server(settings.worker_metrics_port, registry=REGISTRY)
    # arq gestiona su propio bucle de eventos y las señales (SIGTERM espera a los trabajos en
    # curso). El Worker debe crearse en el mismo bucle en el que se ejecuta.
    worker = Worker(
        functions=WorkerSettings.functions,
        cron_jobs=WorkerSettings.cron_jobs,
        on_startup=startup,
        on_shutdown=shutdown,
        redis_settings=_redis_settings(),
        queue_name=settings.queue_name,
        max_tries=MAX_TRIES,
        job_timeout=WorkerSettings.job_timeout,
        keep_result=WorkerSettings.keep_result,
        max_jobs=WorkerSettings.max_jobs,
        health_check_interval=WorkerSettings.health_check_interval,
        handle_signals=True,
    )
    worker.run()


if __name__ == "__main__":
    main()
