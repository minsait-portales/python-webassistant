"""Administración de plataforma: tenants, precios versionados, registro de skills e índices vectoriales."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, Request

from webassistant import ids
from webassistant.api.routers.admin.common import (
    AdminDep,
    ConfigReader,
    PlatformAdmin,
    ServicesDep,
    UsageReader,
    audit_admin,
    serialize,
)
from webassistant.api.routers.admin.schemas import PriceBody, ReindexBody, TenantBody, TenantPatch
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode, validation
from webassistant.usage.reports import usage_report

router = APIRouter(prefix="/v1/admin", tags=["admin-platform"])


@router.get("/tenants", summary="Listar tenants visibles")
async def list_tenants(principal: AdminDep, services: ServicesDep) -> dict[str, Any]:
    async with UnitOfWork(
        services.db, principal.tenant_id, platform=principal.tenant_id is None
    ) as uow:
        rows = await uow.tenants.list(order_by=m.Tenant.slug)
        return {"items": [serialize(t) for t in rows]}


@router.post("/tenants", status_code=201, summary="Crear tenant")
async def create_tenant(
    body: TenantBody, principal: PlatformAdmin, services: ServicesDep, request: Request
) -> dict[str, Any]:
    async with UnitOfWork(services.db, platform=True) as uow:
        if await uow.tenants.by_slug(body.slug):
            raise AppError(ErrorCode.CONFLICT, "Ya existe un tenant con ese slug")
        tenant = m.Tenant(
            id=ids.new_id("tenant"),
            slug=body.slug,
            name=body.name,
            status=body.status,
            settings=body.settings,
        )
        await uow.tenants.add(tenant)
        data = serialize(tenant)
    await audit_admin(
        services,
        principal,
        "tenant.created",
        tenant_id=data["id"],
        target_type="tenant",
        target_id=data["id"],
        request=request,
    )
    return data


@router.patch("/tenants/{tenant_id}", summary="Actualizar tenant")
async def update_tenant(
    tenant_id: str,
    body: TenantPatch,
    principal: PlatformAdmin,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    async with UnitOfWork(services.db, platform=True) as uow:
        tenant = await uow.tenants.require(tenant_id)
        changes = body.model_dump(exclude_none=True)
        for key, value in changes.items():
            setattr(tenant, key, value)
        data = serialize(tenant)
    services.sites.invalidate()
    await audit_admin(
        services,
        principal,
        "tenant.updated",
        tenant_id=tenant_id,
        target_type="tenant",
        target_id=tenant_id,
        details={"fields": sorted(changes)},
        request=request,
    )
    return data


@router.get("/prices", summary="Tabla versionada de precios")
async def list_prices(
    principal: ConfigReader, services: ServicesDep, model: str | None = None
) -> dict[str, Any]:
    async with UnitOfWork(services.db, platform=True) as uow:
        conditions = [m.ModelPrice.model == model] if model else []
        rows = await uow.model_prices.list(
            *conditions, order_by=[m.ModelPrice.model, m.ModelPrice.valid_from.desc()]
        )
        return {"items": [serialize(p) for p in rows]}


@router.post(
    "/prices", status_code=201, summary="Publicar un precio nuevo (cierra la vigencia del anterior)"
)
async def create_price(
    body: PriceBody, principal: PlatformAdmin, services: ServicesDep, request: Request
) -> dict[str, Any]:
    valid_from = (
        datetime.fromisoformat(body.valid_from.replace("Z", "+00:00"))
        if body.valid_from
        else datetime.now(UTC)
    )
    if valid_from.tzinfo is None:
        valid_from = valid_from.replace(tzinfo=UTC)
    async with UnitOfWork(services.db, platform=True) as uow:
        current = await uow.model_prices.valid_at(body.provider, body.model, valid_from)
        if current is not None and current.provider == body.provider:
            if current.valid_from >= valid_from:
                raise AppError(
                    ErrorCode.CONFLICT, "Ya existe un precio con vigencia igual o posterior"
                )
            current.valid_to = valid_from
        price = m.ModelPrice(
            id=ids.new_id("price"),
            provider=body.provider,
            model=body.model,
            input_per_million=body.input_per_million,
            cached_per_million=body.cached_per_million,
            output_per_million=body.output_per_million,
            currency=body.currency,
            valid_from=valid_from,
            source=body.source,
            created_by=principal.user_id,
        )
        await uow.model_prices.add(price)
        data = serialize(price)
    services.prices.invalidate()
    await audit_admin(
        services,
        principal,
        "price.created",
        tenant_id=None,
        target_type="model_price",
        target_id=data["id"],
        details={"model": body.model},
        request=request,
    )
    return data


@router.get("/skills", summary="Registro de skills con versión, estado y diagnóstico")
async def list_skills(principal: ConfigReader, services: ServicesDep) -> dict[str, Any]:
    items = []
    for definition in services.registry.all():
        manifest = definition.manifest
        items.append(
            {
                "name": definition.name,
                "version": definition.version,
                "status": definition.status,
                "source": definition.source,
                "title": manifest.get("title", definition.name),
                "group": manifest.get("group", ""),
                "description": manifest.get("description", ""),
                "requires_rag": definition.requires_rag if definition.package else None,
                "renderer": manifest.get("renderer"),
                "permissions": definition.permissions,
                "capabilities": definition.required_capabilities,
                "actions": [
                    {
                        "name": n,
                        "type": a["type"],
                        "confirmation": a.get("confirmation", "none"),
                        "permission": a.get("permission"),
                    }
                    for n, a in definition.actions.items()
                ],
                "dependencies": manifest.get("dependencies", {}),
                "diagnostics": definition.diagnostics,
            }
        )
    return {
        "items": items,
        "discovered_at": services.registry.discovered_at.isoformat()
        if services.registry.discovered_at
        else None,
    }


@router.post("/skills/rescan", summary="Volver a descubrir skills instaladas")
async def rescan_skills(
    principal: PlatformAdmin, services: ServicesDep, request: Request
) -> dict[str, Any]:
    services.registry.discover()
    await services.registry.sync_to_db(services.db)
    services.assistants.invalidate()
    await audit_admin(
        services,
        principal,
        "skills.rescanned",
        tenant_id=None,
        target_type="skills",
        target_id="*",
        request=request,
    )
    return {"available": len(services.registry.available()), "total": len(services.registry.all())}


@router.get("/embedding-indexes", summary="Versiones del índice vectorial")
async def list_indexes(principal: ConfigReader, services: ServicesDep) -> dict[str, Any]:
    async with UnitOfWork(services.db, platform=True) as uow:
        rows = await uow.embedding_indexes.list(order_by=m.EmbeddingIndex.created_at.desc())
        items = [serialize(r) for r in rows]
    for item in items:
        item["points"] = await services.store.count(item["collection"])
    return {"items": items}


@router.post(
    "/embedding-indexes",
    status_code=202,
    summary="Reindexar en una colección nueva (modelo/dimensión)",
)
async def create_index(
    body: ReindexBody, principal: PlatformAdmin, services: ServicesDep, request: Request
) -> dict[str, Any]:
    if body.provider not in services.gateway.providers:
        raise validation(
            "Proveedor de embeddings no configurado",
            [
                {
                    "path": "/provider",
                    "message": f"Disponibles: {', '.join(sorted(services.gateway.providers))}",
                }
            ],
        )
    provider = services.gateway.provider(body.provider)
    index = await services.indexes.create_building(
        body.provider, body.model, provider.embedding_dimension(body.model)
    )
    await services.queue.enqueue(
        "reindex_embeddings",
        job_id=f"reindex:{index.id}",
        index_id=index.id,
        activate=body.activate,
    )
    await audit_admin(
        services,
        principal,
        "embedding_index.reindex",
        tenant_id=None,
        target_type="embedding_index",
        target_id=index.id,
        request=request,
    )
    return serialize(index)


@router.post(
    "/embedding-indexes/{index_id}/activate", summary="Activar una versión validada del índice"
)
async def activate_index(
    index_id: str, principal: PlatformAdmin, services: ServicesDep, request: Request
) -> dict[str, Any]:
    index = await services.indexes.activate(index_id)
    await audit_admin(
        services,
        principal,
        "embedding_index.activated",
        tenant_id=None,
        target_type="embedding_index",
        target_id=index_id,
        request=request,
    )
    return serialize(index)


@router.post("/embedding-indexes/rollback", summary="Volver a la versión anterior del índice")
async def rollback_index(
    principal: PlatformAdmin, services: ServicesDep, request: Request
) -> dict[str, Any]:
    index = await services.indexes.rollback()
    await audit_admin(
        services,
        principal,
        "embedding_index.rollback",
        tenant_id=None,
        target_type="embedding_index",
        target_id=index.id,
        request=request,
    )
    return serialize(index)


@router.get("/usage", summary="Uso global (plataforma) o del tenant del usuario")
async def global_usage(
    principal: UsageReader,
    services: ServicesDep,
    group_by: str = "tenant,model",
    date_from: str | None = None,
    date_to: str | None = None,
) -> dict[str, Any]:
    async with UnitOfWork(
        services.db, principal.tenant_id, platform=principal.tenant_id is None
    ) as uow:
        return await usage_report(
            uow,
            group_by=[g for g in group_by.split(",") if g],
            date_from=date_from,
            date_to=date_to,
        )
