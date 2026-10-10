"""Administración de un tenant: sites, clientes API, usuarios, políticas y retención (CG-11.1, CG-11.5, CG-11.6)."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, Request

from webassistant import ids
from webassistant.api.routers.admin.common import (
    ConfigReader,
    ServicesDep,
    TenantManager,
    audit_admin,
    serialize,
    tenant_access,
)
from webassistant.api.routers.admin.schemas import (
    AdminUserBody,
    AdminUserPatch,
    ApiClientBody,
    PolicyBody,
    RetentionBody,
    SiteBody,
    SitePatch,
)
from webassistant.api.routers.public import DEFAULT_THEME
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode, validation
from webassistant.security.crypto import hash_secret, password_problems
from webassistant.services.theme import validate_theme

router = APIRouter(prefix="/v1/admin/tenants/{tenant_id}", tags=["admin-tenant"])


async def _assistant_id(uow: UnitOfWork, ref: str | None) -> str | None:
    if not ref:
        return None
    assistant = await uow.assistants.by_id_or_key(ref)
    if assistant is None:
        raise AppError(ErrorCode.VALIDATION_ERROR, f"Assistant desconocido: {ref}")
    return assistant.id


# --- Sites ---------------------------------------------------------------------------


@router.get("/sites", summary="Sites del tenant")
async def list_sites(
    tenant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        return {"items": [serialize(s) for s in await uow.sites.list(order_by=m.Site.public_id)]}


@router.post("/sites", status_code=201, summary="Registrar un site con sus orígenes permitidos")
async def create_site(
    tenant_id: str,
    body: SiteBody,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    validate_theme(body.theme, DEFAULT_THEME)
    async with UnitOfWork(services.db, platform=True) as platform_uow:
        if await platform_uow.sites.by_public_id(body.public_id):
            raise AppError(ErrorCode.CONFLICT, "El identificador público ya existe")
    async with UnitOfWork(services.db, tenant_id) as uow:
        await uow.tenants.require(tenant_id)
        site = m.Site(
            id=ids.new_id("site"),
            tenant_id=tenant_id,
            public_id=body.public_id,
            name=body.name,
            status=body.status,
            access_mode=body.access_mode,
            allowed_origins=body.allowed_origins,
            default_assistant_id=await _assistant_id(uow, body.default_assistant),
            default_locale=body.default_locale,
            languages=body.languages,
            theme=body.theme,
            settings=body.settings,
            capabilities=body.capabilities,
        )
        await uow.sites.add(site)
        data = serialize(site)
    services.sites.invalidate()
    await audit_admin(
        services,
        principal,
        "site.created",
        tenant_id=tenant_id,
        target_type="site",
        target_id=site.id,
        details={"origins": body.allowed_origins},
        request=request,
    )
    return data


@router.patch(
    "/sites/{site_id}", summary="Actualizar un site (dominios, estado, tema, idiomas, assistant)"
)
async def update_site(
    tenant_id: str,
    site_id: str,
    body: SitePatch,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    if body.theme is not None:
        validate_theme(body.theme, DEFAULT_THEME)
    async with UnitOfWork(services.db, tenant_id) as uow:
        site = await uow.sites.require(site_id)
        changes = body.model_dump(exclude_none=True)
        if "default_assistant" in changes:
            site.default_assistant_id = await _assistant_id(uow, changes.pop("default_assistant"))
        for key, value in changes.items():
            setattr(site, key, value)
        data = serialize(site)
    services.sites.invalidate()
    await audit_admin(
        services,
        principal,
        "site.updated",
        tenant_id=tenant_id,
        target_type="site",
        target_id=site_id,
        details={"fields": sorted(body.model_dump(exclude_none=True))},
        request=request,
    )
    return data


# --- Clientes API --------------------------------------------------------------------


@router.get("/api-clients", summary="Clientes server-to-server")
async def list_clients(
    tenant_id: str, principal: TenantManager, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        return {
            "items": [
                serialize(c, exclude={"secret_hash"})
                for c in await uow.api_clients.list(order_by=m.ApiClient.name)
            ]
        }


async def _issue_secret(services: Any, client: m.ApiClient) -> str:
    secret = ids.new_secret(32)
    client.secret_hash = hash_secret(secret)
    client.rotated_at = datetime.now(UTC)
    return secret


@router.post(
    "/api-clients",
    status_code=201,
    summary="Crear cliente API (el secreto se muestra una sola vez)",
)
async def create_client(
    tenant_id: str,
    body: ApiClientBody,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        site_id = None
        if body.site_id:
            site = await uow.sites.by_public_id(body.site_id)
            if site is None:
                raise AppError(ErrorCode.VALIDATION_ERROR, "Site desconocido")
            site_id = site.id
        client = m.ApiClient(
            id=ids.new_id("api_client"),
            tenant_id=tenant_id,
            site_id=site_id,
            name=body.name,
            client_id="wa_" + ids.new_secret(12).replace("-", "").replace("_", "")[:16].lower(),
            secret_hash="",
            scopes=list(body.scopes),
        )
        secret = await _issue_secret(services, client)
        await uow.api_clients.add(client)
        data = serialize(client, exclude={"secret_hash"})
    await audit_admin(
        services,
        principal,
        "api_client.created",
        tenant_id=tenant_id,
        target_type="api_client",
        target_id=client.client_id,
        details={"scopes": body.scopes},
        request=request,
    )
    return {**data, "client_secret": secret}


@router.post("/api-clients/{client_pk}/rotate", summary="Rotar el secreto de un cliente API")
async def rotate_client(
    tenant_id: str,
    client_pk: str,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        client = await uow.api_clients.require(client_pk)
        secret = await _issue_secret(services, client)
        data = serialize(client, exclude={"secret_hash"})
    await audit_admin(
        services,
        principal,
        "api_client.rotated",
        tenant_id=tenant_id,
        target_type="api_client",
        target_id=data["client_id"],
        request=request,
    )
    return {**data, "client_secret": secret}


@router.post("/api-clients/{client_pk}/revoke", summary="Revocar un cliente API")
async def revoke_client(
    tenant_id: str,
    client_pk: str,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        client = await uow.api_clients.require(client_pk)
        client.status = "revoked"
        data = serialize(client, exclude={"secret_hash"})
    await audit_admin(
        services,
        principal,
        "api_client.revoked",
        tenant_id=tenant_id,
        target_type="api_client",
        target_id=data["client_id"],
        request=request,
    )
    return data


# --- Usuarios ------------------------------------------------------------------------


@router.get("/users", summary="Usuarios de administración del tenant")
async def list_users(
    tenant_id: str, principal: TenantManager, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        rows = await uow.admin_users.list(
            m.AdminUser.tenant_id == tenant_id, order_by=m.AdminUser.email
        )
        return {"items": [serialize(u, exclude={"password_hash"}) for u in rows]}


@router.post("/users", status_code=201, summary="Crear usuario de administración")
async def create_user(
    tenant_id: str,
    body: AdminUserBody,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    if body.role == "platform_admin":
        raise AppError(
            ErrorCode.FORBIDDEN, "No se puede crear un platform_admin dentro de un tenant"
        )
    problems = password_problems(body.password)
    if problems:
        raise validation(
            "Contraseña débil", [{"path": "/password", "message": p} for p in problems]
        )
    async with UnitOfWork(services.db, tenant_id) as uow:
        if await uow.admin_users.by_email(body.email):
            raise AppError(ErrorCode.CONFLICT, "El email ya está registrado")
        user = m.AdminUser(
            id=ids.new_id("admin_user"),
            tenant_id=tenant_id,
            email=body.email.lower(),
            name=body.name,
            role=body.role,
            password_hash=hash_secret(body.password),
        )
        await uow.admin_users.add(user)
        data = serialize(user, exclude={"password_hash"})
    await audit_admin(
        services,
        principal,
        "admin_user.created",
        tenant_id=tenant_id,
        target_type="admin_user",
        target_id=user.id,
        details={"role": body.role},
        request=request,
    )
    return data


@router.patch("/users/{user_id}", summary="Cambiar rol o estado de un usuario")
async def update_user(
    tenant_id: str,
    user_id: str,
    body: AdminUserPatch,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        user = await uow.admin_users.first(
            m.AdminUser.id == user_id, m.AdminUser.tenant_id == tenant_id
        )
        if user is None:
            raise AppError(ErrorCode.NOT_FOUND, "Usuario no encontrado")
        for key, value in body.model_dump(exclude_none=True).items():
            setattr(user, key, value)
        data = serialize(user, exclude={"password_hash"})
    await audit_admin(
        services,
        principal,
        "admin_user.updated",
        tenant_id=tenant_id,
        target_type="admin_user",
        target_id=user_id,
        details=body.model_dump(exclude_none=True),
        request=request,
    )
    return data


# --- Políticas, límites y presupuestos -----------------------------------------------------


@router.get("/policies", summary="Límites, presupuestos y alertas")
async def list_policies(
    tenant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        return {"items": [serialize(p) for p in await uow.policies.list()]}


@router.put("/policies", summary="Crear o sustituir la política de tenant o de un assistant")
async def put_policy(
    tenant_id: str,
    body: PolicyBody,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        assistant_id = await _assistant_id(uow, body.assistant)
        row = await uow.policies.first(
            m.TenantPolicy.assistant_id.is_(None)
            if assistant_id is None
            else m.TenantPolicy.assistant_id == assistant_id
        )
        values = body.model_dump(exclude={"assistant"})
        if row is None:
            row = m.TenantPolicy(
                id=ids.new_id("policy"), tenant_id=tenant_id, assistant_id=assistant_id, **values
            )
            await uow.policies.add(row)
        else:
            for key, value in values.items():
                setattr(row, key, value)
        data = serialize(row)
    await audit_admin(
        services,
        principal,
        "policy.updated",
        tenant_id=tenant_id,
        target_type="policy",
        target_id=data["id"],
        details={k: str(v) for k, v in values.items()},
        request=request,
    )
    return data


@router.get("/retention", summary="Política de retención")
async def get_retention(
    tenant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        row = await uow.retention_policies.first()
        return (
            serialize(row)
            if row
            else {
                "conversations_days": 90,
                "payloads_days": 30,
                "audit_days": 365,
                "usage_days": 730,
                "mode": "anonymize",
            }
        )


@router.put("/retention", summary="Configurar la retención")
async def put_retention(
    tenant_id: str,
    body: RetentionBody,
    principal: TenantManager,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        row = await uow.retention_policies.first()
        if row is None:
            row = m.RetentionPolicy(
                id=ids.new_id("policy"), tenant_id=tenant_id, **body.model_dump()
            )
            await uow.retention_policies.add(row)
        else:
            for key, value in body.model_dump().items():
                setattr(row, key, value)
        data = serialize(row)
    await audit_admin(
        services,
        principal,
        "retention.updated",
        tenant_id=tenant_id,
        target_type="retention",
        target_id=tenant_id,
        details=body.model_dump(),
        request=request,
    )
    return data


@router.post("/retention/apply", status_code=202, summary="Aplicar la retención ahora (worker)")
async def apply_retention(
    tenant_id: str, principal: TenantManager, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    await services.queue.enqueue("apply_retention", tenant_id=tenant_id)
    return {"status": "queued"}
