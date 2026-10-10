"""Factoría de la aplicación FastAPI."""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from sqlalchemy.exc import IntegrityError
from starlette.exceptions import HTTPException as StarletteHTTPException

from webassistant import __version__
from webassistant.api.middleware import (
    BodySizeLimitMiddleware,
    RequestContextMiddleware,
    SecurityHeadersMiddleware,
    SiteCorsMiddleware,
    TimeoutMiddleware,
    problem_response,
)
from webassistant.api.routers import actions, conversations, health, ingest, public, static
from webassistant.api.routers.admin import assistants as admin_assistants
from webassistant.api.routers.admin import auth as admin_auth
from webassistant.api.routers.admin import governance as admin_governance
from webassistant.api.routers.admin import platform as admin_platform
from webassistant.api.routers.admin import tenant as admin_tenant
from webassistant.config import Settings, get_settings
from webassistant.errors import AppError, ErrorCode
from webassistant.observability.logging import configure_logging, get_logger
from webassistant.services.container import Services, build_services

logger = get_logger("webassistant.api")

DESCRIPTION = """
API v1 de **WebAssistant AI**, capa genérica del producto.

* **Widget** (`/v1/bootstrap`, `/v1/sessions`, `/v1/conversations`, `/v1/actions`): tokens temporales del navegador.
* **Server-to-server** (`/v1/token`, `/v1/ingest`, `/v1/search`): credenciales de cliente API del portal.
* **Administración** (`/v1/admin`): consola con roles `platform_admin`, `tenant_admin`, `editor`, `auditor` y `viewer`.

Los errores siguen `application/problem+json` (RFC 9457) con un `code` propio estable.
"""


def create_app(
    settings: Settings | None = None,
    services: Services | None = None,
    *,
    create_schema: bool | None = None,
) -> FastAPI:
    settings = settings or get_settings()
    configure_logging(
        settings.log_level, json_output=settings.log_json, service_name=settings.service_name
    )
    services = services or build_services(settings)
    auto_schema = create_schema if create_schema is not None else services.db.is_sqlite

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        await services.startup(create_schema=auto_schema)
        if settings.demo_enabled:
            from webassistant.seeds.demo import ensure_demo

            await ensure_demo(services)
        logger.info("WebAssistant API %s lista (%s)", __version__, settings.environment.value)
        yield
        await services.shutdown()

    app = FastAPI(
        title="WebAssistant AI API",
        version=__version__,
        description=DESCRIPTION,
        lifespan=lifespan,
        openapi_url="/v1/openapi.json",
        docs_url="/docs" if not settings.is_production_like else None,
        redoc_url=None,
    )
    app.state.services = services

    app.add_middleware(TimeoutMiddleware, timeout=settings.request_timeout_seconds)
    app.add_middleware(
        BodySizeLimitMiddleware,
        default_limit=settings.max_request_bytes,
        ingest_limit=settings.max_ingest_request_bytes,
    )
    app.add_middleware(SiteCorsMiddleware, origins_provider=services.sites.all_origins)
    app.add_middleware(
        SecurityHeadersMiddleware, hsts=settings.public_base_url.startswith("https://")
    )
    app.add_middleware(RequestContextMiddleware)

    @app.exception_handler(AppError)
    async def app_error_handler(request: Request, exc: AppError) -> JSONResponse:
        if exc.http_status >= 500:
            logger.error("Error %s en %s: %s", exc.code.value, request.url.path, exc.detail)
        return problem_response(exc, request)

    @app.exception_handler(RequestValidationError)
    async def validation_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
        errors = [
            {
                "path": "/" + "/".join(str(p) for p in error.get("loc", [])[1:]),
                "message": str(error.get("msg", ""))[:300],
            }
            for error in exc.errors()[:20]
        ]
        return problem_response(
            AppError(ErrorCode.VALIDATION_ERROR, "Solicitud no válida", errors=errors), request
        )

    @app.exception_handler(IntegrityError)
    async def integrity_handler(request: Request, exc: IntegrityError) -> JSONResponse:
        logger.warning("Conflicto de integridad en %s", request.url.path)
        return problem_response(
            AppError(ErrorCode.CONFLICT, "Conflicto con el estado actual"), request
        )

    @app.exception_handler(StarletteHTTPException)
    async def http_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        code = {
            401: ErrorCode.UNAUTHORIZED,
            403: ErrorCode.FORBIDDEN,
            404: ErrorCode.NOT_FOUND,
            405: ErrorCode.VALIDATION_ERROR,
        }.get(exc.status_code, ErrorCode.INTERNAL_ERROR)
        return problem_response(AppError(code, str(exc.detail), status=exc.status_code), request)

    @app.exception_handler(Exception)
    async def unhandled_handler(request: Request, exc: Exception) -> JSONResponse:
        logger.exception("Error no controlado en %s", request.url.path)
        return problem_response(AppError(ErrorCode.INTERNAL_ERROR, "Error interno"), request)

    for router in (
        health.router,
        public.router,
        conversations.router,
        actions.router,
        ingest.router,
        admin_auth.router,
        admin_platform.router,
        admin_tenant.router,
        admin_assistants.router,
        admin_governance.router,
        static.router,
    ):
        app.include_router(router)

    if settings.otel_enabled:
        from webassistant.observability.tracing import setup_tracing

        setup_tracing(settings.service_name, settings.otel_exporter_endpoint, app)
    return app


def app_factory() -> FastAPI:
    """Punto de entrada para ``uvicorn --factory webassistant.api.app:app_factory``."""
    return create_app()
