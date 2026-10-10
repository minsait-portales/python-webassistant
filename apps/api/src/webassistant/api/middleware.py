"""Middlewares ASGI: correlación, cabeceras seguras, CORS por site, tamaño máximo,
timeouts y métricas HTTP (CG-03.4, CG-13.1, CG-13.3)."""

from __future__ import annotations

import asyncio
import re
import secrets
import time
from collections.abc import Awaitable, Callable

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response
from starlette.types import ASGIApp

from webassistant.errors import AppError, ErrorCode
from webassistant.observability import context, metrics
from webassistant.security.origins import normalize_origin, origin_allowed

_REQUEST_ID_RE = re.compile(r"^[A-Za-z0-9._-]{8,64}$")
CORS_ALLOWED_HEADERS = (
    "Authorization, Content-Type, Idempotency-Key, Last-Event-ID, X-Request-Id, Accept"
)
CORS_EXPOSED_HEADERS = "X-Request-Id, Retry-After, Location"
CORS_PREFIXES = ("/v1/bootstrap", "/v1/sessions", "/v1/conversations", "/v1/actions", "/v1/runs")


def problem_response(error: AppError, request: Request) -> JSONResponse:
    return JSONResponse(
        error.to_problem(correlation_id=context.get("correlation_id"), instance=request.url.path),
        status_code=error.http_status,
        media_type="application/problem+json",
        headers=error.headers,
    )


class RequestContextMiddleware(BaseHTTPMiddleware):
    async def dispatch(
        self, request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        incoming = request.headers.get("x-request-id", "")
        correlation_id = incoming if _REQUEST_ID_RE.match(incoming) else secrets.token_hex(12)
        traceparent = request.headers.get("traceparent", "")
        trace_id = traceparent.split("-")[1] if traceparent.count("-") >= 3 else correlation_id
        tokens = context.bind(correlation_id=correlation_id, trace_id=trace_id)
        started = time.perf_counter()
        try:
            response = await call_next(request)
        finally:
            context.reset(tokens)
        route = request.scope.get("route")
        route_path = getattr(route, "path", "unmatched")
        metrics.HTTP_REQUESTS.labels(route_path, request.method, str(response.status_code)).inc()
        metrics.HTTP_LATENCY.labels(route_path, request.method).observe(
            time.perf_counter() - started
        )
        response.headers["X-Request-Id"] = correlation_id
        return response


class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    def __init__(self, app: ASGIApp, *, hsts: bool) -> None:
        super().__init__(app)
        self.hsts = hsts

    async def dispatch(
        self, request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        response = await call_next(request)
        headers = response.headers
        headers.setdefault("X-Content-Type-Options", "nosniff")
        headers.setdefault("Referrer-Policy", "strict-origin-when-cross-origin")
        headers.setdefault(
            "Permissions-Policy", "camera=(), microphone=(), geolocation=(), payment=()"
        )
        path = request.url.path
        if path.startswith("/widget/"):
            headers["Cross-Origin-Resource-Policy"] = "cross-origin"
        else:
            headers.setdefault("X-Frame-Options", "DENY")
            headers.setdefault("Cross-Origin-Resource-Policy", "same-site")
        if path.startswith(("/admin", "/demo")) and "text/html" in headers.get("content-type", ""):
            headers.setdefault(
                "Content-Security-Policy",
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; "
                "connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            )
        if path.startswith("/v1/") and "cache-control" not in headers:
            headers["Cache-Control"] = "no-store"
        if self.hsts:
            headers.setdefault("Strict-Transport-Security", "max-age=31536000; includeSubDomains")
        return response


class BodySizeLimitMiddleware(BaseHTTPMiddleware):
    def __init__(self, app: ASGIApp, *, default_limit: int, ingest_limit: int) -> None:
        super().__init__(app)
        self.default_limit = default_limit
        self.ingest_limit = ingest_limit

    async def dispatch(
        self, request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        limit = (
            self.ingest_limit
            if request.url.path.startswith(("/v1/ingest", "/v1/admin/config"))
            else self.default_limit
        )
        length = request.headers.get("content-length")
        if length is not None:
            try:
                too_big = int(length) > limit
            except ValueError:
                too_big = True
            if too_big:
                return problem_response(
                    AppError(ErrorCode.PAYLOAD_TOO_LARGE, f"Máximo {limit} bytes"), request
                )
        elif (
            request.method in {"POST", "PUT", "PATCH"}
            and request.headers.get("transfer-encoding") == "chunked"
        ):
            body = await request.body()
            if len(body) > limit:
                return problem_response(
                    AppError(ErrorCode.PAYLOAD_TOO_LARGE, f"Máximo {limit} bytes"), request
                )
        if request.method in {"POST", "PUT", "PATCH"} and "json" in request.headers.get(
            "content-type", ""
        ):
            # PostgreSQL no admite el carácter NUL en text/jsonb: se rechaza en la entrada en lugar
            # de fallar al persistir (mensajes, acciones, ingesta y administración).
            body = await request.body()
            if b"\x00" in body or b"\\u0000" in body:
                return problem_response(
                    AppError(
                        ErrorCode.VALIDATION_ERROR,
                        "El contenido no puede incluir el carácter NUL (U+0000)",
                        user_message="El mensaje contiene caracteres no válidos.",
                    ),
                    request,
                )
        return await call_next(request)


class TimeoutMiddleware(BaseHTTPMiddleware):
    def __init__(self, app: ASGIApp, *, timeout: float) -> None:
        super().__init__(app)
        self.timeout = timeout

    async def dispatch(
        self, request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        try:
            return await asyncio.wait_for(call_next(request), timeout=self.timeout)
        except TimeoutError:
            return problem_response(
                AppError(ErrorCode.SERVICE_UNAVAILABLE, "Tiempo de respuesta agotado", status=504),
                request,
            )


class SiteCorsMiddleware(BaseHTTPMiddleware):
    """CORS explícito: solo orígenes registrados por algún site activo, solo rutas del widget.

    La autorización real (origen ligado al site del token) se comprueba en cada
    petición; este middleware solo gestiona las cabeceras del navegador.
    """

    def __init__(
        self,
        app: ASGIApp,
        *,
        origins_provider: Callable[[], Awaitable[frozenset[str]]],
        max_age: int = 600,
    ) -> None:
        super().__init__(app)
        self.origins_provider = origins_provider
        self.max_age = max_age

    async def dispatch(
        self, request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        origin = request.headers.get("origin")
        path = request.url.path
        if not origin or not path.startswith(CORS_PREFIXES):
            return await call_next(request)
        allowed = origin_allowed(origin, list(await self.origins_provider()))
        is_preflight = (
            request.method == "OPTIONS" and "access-control-request-method" in request.headers
        )
        if is_preflight:
            if not allowed:
                return Response(status_code=403, headers={"Vary": "Origin"})
            return Response(
                status_code=204,
                headers={
                    "Access-Control-Allow-Origin": normalize_origin(origin) or origin,
                    "Access-Control-Allow-Methods": "GET, POST, DELETE, OPTIONS",
                    "Access-Control-Allow-Headers": CORS_ALLOWED_HEADERS,
                    "Access-Control-Max-Age": str(self.max_age),
                    "Vary": "Origin",
                },
            )
        response = await call_next(request)
        if allowed:
            response.headers["Access-Control-Allow-Origin"] = normalize_origin(origin) or origin
            response.headers["Access-Control-Expose-Headers"] = CORS_EXPOSED_HEADERS
        response.headers.setdefault("Vary", "Origin")
        return response
