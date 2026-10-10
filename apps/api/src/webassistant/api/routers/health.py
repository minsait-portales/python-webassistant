"""Liveness, readiness, diagnóstico de dependencias y métricas (CG-13.3, CG-13.4)."""

from __future__ import annotations

import asyncio
import time
from typing import Annotated, Any

from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse, Response
from prometheus_client import CONTENT_TYPE_LATEST, generate_latest

from webassistant import __version__
from webassistant.api.deps import ServicesDep, require_permission
from webassistant.errors import AppError, ErrorCode
from webassistant.observability import metrics
from webassistant.security.principal import AdminPrincipal
from webassistant.security.scopes import Permission

router = APIRouter(tags=["operations"])


async def _probe(name: str, check: Any, timeout: float = 3.0) -> dict[str, Any]:
    started = time.perf_counter()
    try:
        result = await asyncio.wait_for(check(), timeout=timeout)
        ok = result is not False
        error = None
    except Exception as exc:
        ok, error = False, type(exc).__name__
    return {
        "name": name,
        "ok": ok,
        "latency_ms": int((time.perf_counter() - started) * 1000),
        "error": error,
    }


async def dependency_report(services: Any) -> list[dict[str, Any]]:
    checks = [
        _probe("postgres", services.db.ping),
        _probe("redis", services.kv.ping),
        _probe("qdrant", services.store.health),
    ]
    return list(await asyncio.gather(*checks))


@router.get("/health/live", summary="Liveness: el proceso responde")
async def live() -> dict[str, str]:
    return {"status": "ok"}


@router.get("/health/ready", summary="Readiness: dependencias críticas disponibles")
async def ready(services: ServicesDep) -> JSONResponse:
    report = await dependency_report(services)
    ok = services.started and all(item["ok"] for item in report)
    return JSONResponse(
        {
            "status": "ready" if ok else "not_ready",
            "checks": [{"name": r["name"], "ok": r["ok"]} for r in report],
        },
        status_code=200 if ok else 503,
    )


@router.get(
    "/v1/admin/diagnostics", summary="Diagnóstico detallado de dependencias (administración)"
)
async def diagnostics(
    services: ServicesDep,
    _: Annotated[AdminPrincipal, Depends(require_permission(Permission.PLATFORM_MANAGE))],
) -> dict[str, Any]:
    report = await dependency_report(services)
    index = await services.indexes.active()
    return {
        "version": __version__,
        "environment": services.settings.environment.value,
        "migration": await services.db.current_revision(),
        "dependencies": report,
        "queue": {"mode": services.settings.queue_mode, "depth": await services.queue.depth()},
        "providers": {
            name: await provider.health() for name, provider in services.gateway.providers.items()
        },
        "circuit_breakers": {k: b.state for k, b in services.gateway.resilience.breakers.items()},
        "embedding_index": {
            "id": index.id,
            "model": index.model,
            "collection": index.collection,
            "points": await services.store.count(index.collection),
        },
        "skills": {
            "available": len(services.registry.available()),
            "total": len(services.registry.all()),
        },
    }


@router.get("/metrics", include_in_schema=False)
async def prometheus_metrics(request: Request, services: ServicesDep) -> Response:
    settings = services.settings
    if not settings.metrics_enabled:
        raise AppError(ErrorCode.NOT_FOUND, "Métricas deshabilitadas")
    if settings.metrics_token:
        expected = f"Bearer {settings.metrics_token.get_secret_value()}"
        if request.headers.get("authorization") != expected:
            raise AppError(ErrorCode.UNAUTHORIZED, "Token de métricas requerido")
    try:
        metrics.QUEUE_DEPTH.labels(settings.queue_name).set(await services.queue.depth())
    except Exception:
        metrics.QUEUE_DEPTH.labels(settings.queue_name).set(-1)
    return Response(generate_latest(metrics.REGISTRY), media_type=CONTENT_TYPE_LATEST)
