"""Ingesta y búsqueda server-to-server (CG-07.2, CG-09.6).

Usan credenciales de cliente API (Basic) o tokens ``server`` emitidos por
``/v1/token``; nunca aceptan tokens públicos del widget.
"""

from __future__ import annotations

from typing import Annotated, Any

from fastapi import APIRouter, Depends

from webassistant.api.deps import ServicesDep, require_server_scope
from webassistant.api.schemas import AclUpdate, IngestBatch, SearchBody
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.rag.search import SearchRequest
from webassistant.rag.vector_store import SearchScope
from webassistant.security.principal import ServerPrincipal
from webassistant.security.scopes import Scope
from webassistant.usage.recorder import UsageScope

router = APIRouter(prefix="/v1", tags=["ingest"])

IngestWriter = Annotated[ServerPrincipal, Depends(require_server_scope(Scope.INGEST_WRITE))]
IngestReader = Annotated[ServerPrincipal, Depends(require_server_scope(Scope.INGEST_READ))]
SearchReader = Annotated[ServerPrincipal, Depends(require_server_scope(Scope.SEARCH_READ))]


@router.post(
    "/ingest/documents",
    status_code=202,
    summary="Alta/actualización idempotente de documentos (lotes)",
)
async def ingest_documents(
    body: IngestBatch, principal: IngestWriter, services: ServicesDep
) -> dict[str, Any]:
    outcomes = await services.ingestion.upsert_many(
        principal.tenant_id, body.documents, client_site_id=principal.site_id
    )
    await services.audit.record(
        "ingest.batch",
        tenant_id=principal.tenant_id,
        actor_type="api_client",
        actor_id=principal.client_id,
        details={"documents": len(outcomes), "operations": [o.operation for o in outcomes][:100]},
    )
    return {"items": [o.as_dict() for o in outcomes]}


@router.get(
    "/ingest/documents/{knowledge_base}/{source_id:path}",
    summary="Estado de indexación de un documento",
)
async def document_status(
    knowledge_base: str, source_id: str, principal: IngestReader, services: ServicesDep
) -> dict[str, Any]:
    return await services.ingestion.status(principal.tenant_id, knowledge_base, source_id)


@router.delete(
    "/ingest/documents/{knowledge_base}/{source_id:path}",
    summary="Borrar un documento y sus derivados",
)
async def delete_document(
    knowledge_base: str, source_id: str, principal: IngestWriter, services: ServicesDep
) -> dict[str, Any]:
    outcome = await services.ingestion.delete(
        principal.tenant_id, knowledge_base, source_id, client_site_id=principal.site_id
    )
    await services.audit.record(
        "ingest.deleted",
        tenant_id=principal.tenant_id,
        actor_type="api_client",
        actor_id=principal.client_id,
        target_type="document",
        target_id=source_id,
    )
    return outcome.as_dict()


@router.put(
    "/ingest/acl/{knowledge_base}/{source_id:path}",
    summary="Actualizar la ACL de un documento en ambos almacenes",
)
async def update_acl(
    knowledge_base: str,
    source_id: str,
    body: AclUpdate,
    principal: IngestWriter,
    services: ServicesDep,
) -> dict[str, Any]:
    return (
        await services.ingestion.update_acl(
            principal.tenant_id, knowledge_base, source_id, body.acl
        )
    ).as_dict()


@router.get(
    "/ingest/inventory/{knowledge_base}",
    summary="Inventario para conciliación (source_id, versión y hash)",
)
async def inventory(
    knowledge_base: str,
    principal: IngestReader,
    services: ServicesDep,
    cursor: str | None = None,
    limit: int = 500,
) -> dict[str, Any]:
    return await services.ingestion.inventory(
        principal.tenant_id, knowledge_base, cursor=cursor, limit=min(max(limit, 1), 1000)
    )


@router.post("/search", summary="Search API: búsqueda semántica aislada por tenant, site y ACL")
async def search(
    body: SearchBody, principal: SearchReader, services: ServicesDep
) -> dict[str, Any]:
    async with UnitOfWork(services.db, principal.tenant_id) as uow:
        site = None
        if body.site_id:
            site = await uow.sites.by_public_id(body.site_id)
        elif principal.site_id:
            site = await uow.sites.get(principal.site_id)
        if site is None:
            raise AppError(ErrorCode.VALIDATION_ERROR, "Indica un site válido")
        if principal.site_id and site.id != principal.site_id:
            raise AppError(ErrorCode.FORBIDDEN, "El cliente está limitado a otro site")
        conditions = [m.KnowledgeBase.status == "active"]
        if body.knowledge_bases:
            conditions.append(m.KnowledgeBase.key.in_(body.knowledge_bases))
        kbs = [
            kb
            for kb in await uow.knowledge_bases.list(*conditions)
            if kb.site_id in (None, site.id)
        ]
        site_id = site.id
    if not kbs:
        return {"items": []}
    results = await services.search.search(
        SearchRequest(
            scope=SearchScope(
                tenant_id=principal.tenant_id,
                site_id=site_id,
                knowledge_base_ids=tuple(kb.id for kb in kbs),
                principals=tuple(sorted(set(body.principals) | {"public"})),
                locales=(body.locale,) if body.locale else (),
            ),
            query=body.query,
            limit=body.limit,
            min_score=body.min_score,
        ),
        usage_scope=UsageScope(
            tenant_id=principal.tenant_id,
            site_id=site_id,
            subject=principal.client_id,
            store_payloads=False,
        ),
    )
    return {
        "items": [
            {
                "source_id": r.source_id,
                "knowledge_base": r.knowledge_base,
                "title": r.title,
                "snippet": r.text[:500],
                "url": r.url,
                "score": round(r.score, 4),
                "type": r.document_type,
                "locale": r.locale,
                "page": r.page,
                "metadata": r.metadata,
            }
            for r in results
        ]
    }
