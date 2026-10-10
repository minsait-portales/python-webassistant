"""Dependencias FastAPI: servicios, autenticación por tipo de credencial y límites (CG-03)."""

from __future__ import annotations

import base64
import ipaddress
from collections.abc import Awaitable, Callable
from datetime import UTC, datetime
from typing import Annotated

from fastapi import Depends, Request

from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.observability import context, metrics
from webassistant.security.crypto import verify_secret
from webassistant.security.origins import normalize_origin, origin_allowed
from webassistant.security.principal import (
    AdminPrincipal,
    ServerPrincipal,
    SiteInfo,
    WidgetPrincipal,
)
from webassistant.security.ratelimit import Limit
from webassistant.security.scopes import Permission, has_scopes, role_permissions
from webassistant.services.container import Services


def get_services(request: Request) -> Services:
    services: Services = request.app.state.services
    return services


ServicesDep = Annotated[Services, Depends(get_services)]


def client_ip(request: Request, trusted_proxies: list[str]) -> str:
    peer = request.client.host if request.client else "unknown"
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded and trusted_proxies:
        try:
            peer_ip = ipaddress.ip_address(peer)
            trusted = any(
                peer_ip in ipaddress.ip_network(net, strict=False) for net in trusted_proxies
            )
        except ValueError:
            trusted = False
        if trusted:
            return forwarded.split(",")[0].strip()
    return peer


def request_origin(request: Request) -> str | None:
    origin = request.headers.get("origin")
    if origin and origin != "null":
        return normalize_origin(origin)
    referer = request.headers.get("referer")
    if referer:
        return normalize_origin(referer)
    return None


def bearer_token(request: Request) -> str:
    header = request.headers.get("authorization", "")
    scheme, _, token = header.partition(" ")
    if scheme.lower() != "bearer" or not token:
        raise AppError(
            ErrorCode.UNAUTHORIZED, "Falta el token Bearer", headers={"WWW-Authenticate": "Bearer"}
        )
    return token.strip()


async def rate_limit(services: Services, *limits: Limit, scope: str) -> None:
    try:
        await services.rate_limiter.hit(*limits)
    except AppError:
        metrics.RATE_LIMITED.labels(scope).inc()
        raise


def check_site_origin(site: SiteInfo, origin: str | None) -> None:
    if site.status != "active":
        raise AppError(ErrorCode.SITE_DISABLED, "Site deshabilitado")
    if not origin or not origin_allowed(origin, list(site.allowed_origins)):
        raise AppError(ErrorCode.ORIGIN_NOT_ALLOWED, "Origen no registrado para este site")


async def widget_principal(request: Request, services: ServicesDep) -> WidgetPrincipal:
    claims = services.tokens.decode_widget(bearer_token(request))
    site = await services.sites.by_id(claims.site_id)
    if site is None or site.tenant_id != claims.tenant_id:
        raise AppError(ErrorCode.UNAUTHORIZED, "Site del token no válido")
    if site.status != "active":
        raise AppError(ErrorCode.SITE_DISABLED, "Site deshabilitado")
    origin = request.headers.get("origin")
    if origin:
        normalized = normalize_origin(origin)
        if not origin_allowed(normalized, list(site.allowed_origins)):
            raise AppError(ErrorCode.ORIGIN_NOT_ALLOWED, "Origen no registrado para este site")
        if claims.origin and normalized != claims.origin:
            raise AppError(ErrorCode.ORIGIN_NOT_ALLOWED, "El token está ligado a otro origen")
    settings = services.settings
    ip = client_ip(request, settings.trusted_proxies)
    await rate_limit(
        services,
        Limit("ip", ip, settings.rate_limit_ip_per_minute),
        Limit("site", site.id, settings.rate_limit_site_per_minute),
        Limit("subject", f"{site.id}:{claims.subject}", settings.rate_limit_subject_per_minute * 4),
        scope="widget",
    )
    context.bind(tenant_id=claims.tenant_id, site_id=site.id, subject=claims.subject)
    return WidgetPrincipal(claims=claims, site=site, ip=ip)


WidgetDep = Annotated[WidgetPrincipal, Depends(widget_principal)]


async def authenticate_client(
    services: Services, client_id: str, client_secret: str
) -> ServerPrincipal:
    async with UnitOfWork(services.db, platform=True) as uow:
        client = await uow.api_clients.by_client_id(client_id)
        if (
            client is None
            or client.status != "active"
            or not verify_secret(client_secret, client.secret_hash)
        ):
            raise AppError(ErrorCode.UNAUTHORIZED, "Credenciales de cliente no válidas")
        if client.expires_at and client.expires_at < datetime.now(UTC):
            raise AppError(ErrorCode.UNAUTHORIZED, "Credenciales de cliente caducadas")
        tenant = await uow.tenants.get(client.tenant_id)
        if tenant is None or tenant.status != "active":
            raise AppError(ErrorCode.FORBIDDEN, "Tenant suspendido")
        client.last_used_at = datetime.now(UTC)
        return ServerPrincipal(
            client.client_id, client.tenant_id, client.site_id, frozenset(client.scopes)
        )


async def server_principal(request: Request, services: ServicesDep) -> ServerPrincipal:
    header = request.headers.get("authorization", "")
    scheme, _, value = header.partition(" ")
    if scheme.lower() == "basic":
        try:
            decoded = base64.b64decode(value).decode("utf-8")
            client_id, _, client_secret = decoded.partition(":")
        except (ValueError, UnicodeDecodeError) as exc:
            raise AppError(ErrorCode.UNAUTHORIZED, "Cabecera Basic no válida") from exc
        principal = await authenticate_client(services, client_id, client_secret)
    else:
        claims = services.tokens.decode_server(bearer_token(request))
        principal = ServerPrincipal(
            claims.client_id, claims.tenant_id, claims.site_id, claims.scopes
        )
    await rate_limit(
        services,
        Limit("client", principal.client_id, services.settings.rate_limit_site_per_minute),
        scope="server",
    )
    context.bind(tenant_id=principal.tenant_id)
    return principal


ServerDep = Annotated[ServerPrincipal, Depends(server_principal)]


def require_server_scope(*scopes: str) -> Callable[[ServerPrincipal], Awaitable[ServerPrincipal]]:
    async def dependency(principal: ServerDep) -> ServerPrincipal:
        if not has_scopes(principal.scopes, scopes):
            raise AppError(ErrorCode.FORBIDDEN, "Faltan scopes: " + ", ".join(scopes))
        return principal

    return dependency


async def admin_principal(request: Request, services: ServicesDep) -> AdminPrincipal:
    claims = services.tokens.decode_admin(bearer_token(request))
    async with UnitOfWork(services.db, platform=True) as uow:
        user = await uow.admin_users.get(claims.user_id)
        if user is None or user.status != "active":
            raise AppError(ErrorCode.UNAUTHORIZED, "Usuario administrador no válido")
        role, tenant_id = user.role, user.tenant_id
    await rate_limit(
        services,
        Limit("admin", claims.user_id, services.settings.rate_limit_admin_per_minute),
        scope="admin",
    )
    context.bind(tenant_id=tenant_id)
    return AdminPrincipal(
        user_id=claims.user_id,
        tenant_id=tenant_id,
        role=role,
        email=claims.email,
        permissions=frozenset(p.value for p in role_permissions(role)),
    )


AdminDep = Annotated[AdminPrincipal, Depends(admin_principal)]


def require_permission(
    permission: Permission,
) -> Callable[[AdminPrincipal], Awaitable[AdminPrincipal]]:
    async def dependency(principal: AdminDep) -> AdminPrincipal:
        if permission.value not in principal.permissions:
            raise AppError(ErrorCode.FORBIDDEN, f"Permiso requerido: {permission.value}")
        return principal

    return dependency


def tenant_access(principal: AdminPrincipal, tenant_id: str) -> None:
    """Un admin de tenant solo opera sobre su tenant; el de plataforma, sobre cualquiera."""
    if principal.tenant_id is not None and principal.tenant_id != tenant_id:
        raise AppError(ErrorCode.NOT_FOUND, "Tenant no encontrado")
