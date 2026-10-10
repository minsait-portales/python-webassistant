"""RAG, uso, auditoría, trazas de soporte y paquete de configuración (CG-11.4, CG-12, CG-16.1/16.2)."""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Request
from fastapi.responses import PlainTextResponse

from webassistant import ids
from webassistant.api.routers.admin.common import (
    AuditReader,
    ConfigReader,
    ConfigWriter,
    ConversationReader,
    KnowledgeReader,
    KnowledgeWriter,
    PayloadReader,
    ServicesDep,
    UsageReader,
    audit_admin,
    serialize,
    tenant_access,
)
from webassistant.api.routers.admin.schemas import KnowledgeBaseBody, KnowledgeBasePatch
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.rag.ingestion import document_view
from webassistant.services.config_package import ConfigPackageService
from webassistant.usage.reports import conversation_trace, report_csv, usage_report

router = APIRouter(prefix="/v1/admin/tenants/{tenant_id}", tags=["admin-governance"])


# --- Bases de conocimiento y documentos --------------------------------------------------


@router.get("/knowledge-bases", summary="Bases de conocimiento con estadísticas de ingesta")
async def list_kbs(
    tenant_id: str, principal: KnowledgeReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        items = []
        for kb in await uow.knowledge_bases.list(order_by=m.KnowledgeBase.key):
            items.append({**serialize(kb), "documents": await uow.documents.status_counts(kb.id)})
        return {"items": items}


@router.post("/knowledge-bases", status_code=201, summary="Crear base de conocimiento")
async def create_kb(
    tenant_id: str,
    body: KnowledgeBaseBody,
    principal: KnowledgeWriter,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        if await uow.knowledge_bases.by_key(body.key):
            raise AppError(ErrorCode.CONFLICT, "Ya existe una base con esa clave")
        site_id = None
        if body.site_id:
            site = await uow.sites.by_public_id(body.site_id)
            if site is None:
                raise AppError(ErrorCode.VALIDATION_ERROR, "Site desconocido")
            site_id = site.id
        kb = m.KnowledgeBase(
            id=ids.new_id("knowledge_base"),
            tenant_id=tenant_id,
            key=body.key,
            name=body.name,
            description=body.description,
            site_id=site_id,
            default_locale=body.default_locale,
            chunking=body.chunking,
        )
        await uow.knowledge_bases.add(kb)
        data = serialize(kb)
    await audit_admin(
        services,
        principal,
        "knowledge_base.created",
        tenant_id=tenant_id,
        target_type="knowledge_base",
        target_id=kb.id,
        request=request,
    )
    return data


@router.patch("/knowledge-bases/{kb_id}", summary="Actualizar base de conocimiento")
async def update_kb(
    tenant_id: str,
    kb_id: str,
    body: KnowledgeBasePatch,
    principal: KnowledgeWriter,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        kb = await uow.knowledge_bases.require(kb_id)
        for key, value in body.model_dump(exclude_none=True).items():
            setattr(kb, key, value)
        data = serialize(kb)
    await audit_admin(
        services,
        principal,
        "knowledge_base.updated",
        tenant_id=tenant_id,
        target_type="knowledge_base",
        target_id=kb_id,
        request=request,
    )
    return data


@router.get("/knowledge-bases/{kb_id}/documents", summary="Documentos, estado de ingesta y errores")
async def list_documents(
    tenant_id: str,
    kb_id: str,
    principal: KnowledgeReader,
    services: ServicesDep,
    status: str | None = None,
    limit: int = 100,
    offset: int = 0,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        kb = await uow.knowledge_bases.require(kb_id)
        conditions = [m.Document.knowledge_base_id == kb.id]
        if status:
            conditions.append(m.Document.status == status)
        rows = await uow.documents.list(
            *conditions, order_by=m.Document.updated_at.desc(), limit=min(limit, 500), offset=offset
        )
        total = await uow.documents.count(*conditions)
        return {
            "items": [document_view(d, kb.key) for d in rows],
            "total": total,
            "stats": await uow.documents.status_counts(kb.id),
        }


@router.post(
    "/knowledge-bases/{kb_id}/retry",
    status_code=202,
    summary="Reintentar documentos fallidos (worker)",
)
async def retry_documents(
    tenant_id: str, kb_id: str, principal: KnowledgeWriter, services: ServicesDep, request: Request
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    count = await services.ingestion.retry_failed(tenant_id, knowledge_base_id=kb_id)
    await audit_admin(
        services,
        principal,
        "knowledge_base.retry",
        tenant_id=tenant_id,
        target_type="knowledge_base",
        target_id=kb_id,
        details={"documents": count},
        request=request,
    )
    return {"queued": count}


@router.post(
    "/knowledge-bases/{kb_id}/documents/{document_id}/reindex",
    status_code=202,
    summary="Reindexar un documento",
)
async def reindex_document(
    tenant_id: str, kb_id: str, document_id: str, principal: KnowledgeWriter, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        document = await uow.documents.first(
            m.Document.id == document_id, m.Document.knowledge_base_id == kb_id
        )
        if document is None:
            raise AppError(ErrorCode.NOT_FOUND, "Documento no encontrado")
        if document.reference and not document.text:
            raise AppError(
                ErrorCode.CONFLICT, "El original está en el portal: vuelve a ingerirlo desde allí"
            )
        document.status = "queued"
    await services.queue.enqueue(
        "process_document", tenant_id=tenant_id, document_id=document_id, access_token=None
    )
    return {"status": "queued"}


@router.post("/reconcile", status_code=202, summary="Conciliar PostgreSQL y Qdrant (worker)")
async def reconcile(
    tenant_id: str, principal: KnowledgeWriter, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    await services.queue.enqueue(
        "reconcile_tenant", job_id=f"reconcile:{tenant_id}", tenant_id=tenant_id
    )
    return {"status": "queued"}


# --- Uso, coste y auditoría ------------------------------------------------------------


@router.get("/usage", summary="Informe de uso y coste")
async def tenant_usage(
    tenant_id: str,
    principal: UsageReader,
    services: ServicesDep,
    group_by: str = "assistant,model",
    date_from: str | None = None,
    date_to: str | None = None,
    assistant: str | None = None,
    model: str | None = None,
    skill: str | None = None,
    status: str | None = None,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        return await usage_report(
            uow,
            group_by=[g for g in group_by.split(",") if g],
            date_from=date_from,
            date_to=date_to,
            filters={
                "assistant": assistant or "",
                "model": model or "",
                "skill": skill or "",
                "status": status or "",
            },
        )


@router.get(
    "/usage.csv", response_class=PlainTextResponse, summary="Exportación CSV del informe de uso"
)
async def tenant_usage_csv(
    tenant_id: str,
    principal: UsageReader,
    services: ServicesDep,
    group_by: str = "day,assistant,model",
    date_from: str | None = None,
    date_to: str | None = None,
) -> PlainTextResponse:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        report = await usage_report(
            uow,
            group_by=[g for g in group_by.split(",") if g],
            date_from=date_from,
            date_to=date_to,
        )
    return PlainTextResponse(
        report_csv(report),
        media_type="text/csv",
        headers={"Content-Disposition": f'attachment; filename="usage-{tenant_id}.csv"'},
    )


@router.get("/audit", summary="Eventos de auditoría")
async def list_audit(
    tenant_id: str,
    principal: AuditReader,
    services: ServicesDep,
    action: str | None = None,
    run_id: str | None = None,
    limit: int = 100,
    offset: int = 0,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        conditions = []
        if action:
            conditions.append(m.AuditEvent.action.startswith(action))
        if run_id:
            conditions.append(m.AuditEvent.run_id == run_id)
        rows = await uow.audit_events.list(
            *conditions,
            order_by=m.AuditEvent.created_at.desc(),
            limit=min(limit, 500),
            offset=offset,
        )
        return {"items": [serialize(e) for e in rows]}


@router.get("/conversations", summary="Conversaciones (metadatos) para soporte")
async def list_conversations(
    tenant_id: str,
    principal: ConversationReader,
    services: ServicesDep,
    limit: int = 50,
    offset: int = 0,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        rows = await uow.conversations.list(
            order_by=m.Conversation.updated_at.desc(), limit=min(limit, 200), offset=offset
        )
        return {
            "items": [serialize(c, exclude={"summary", "context_state", "subject"}) for c in rows]
        }


@router.get(
    "/conversations/{conversation_id}/trace",
    summary="Traza conversación -> run -> llamada -> tool -> acción",
)
async def trace(
    tenant_id: str,
    conversation_id: str,
    principal: ConversationReader,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        data = await conversation_trace(uow, conversation_id)
    await audit_admin(
        services,
        principal,
        "conversation.trace_viewed",
        tenant_id=tenant_id,
        target_type="conversation",
        target_id=conversation_id,
        request=request,
    )
    return data


@router.get(
    "/payloads/{payload_id}", summary="Payload técnico descifrado (acceso restringido y auditado)"
)
async def read_payload(
    tenant_id: str,
    payload_id: str,
    principal: PayloadReader,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        payload = await uow.audit_payloads.require(payload_id)
        content = services.audit.decrypt(payload)
        data = {
            "id": payload.id,
            "kind": payload.kind,
            "run_id": payload.run_id,
            "content": content,
            "expires_at": payload.expires_at.isoformat() if payload.expires_at else None,
        }
    await audit_admin(
        services,
        principal,
        "payload.viewed",
        tenant_id=tenant_id,
        target_type="audit_payload",
        target_id=payload_id,
        request=request,
    )
    return data


# --- Paquete de configuración ------------------------------------------------------------


@router.get("/config/export", summary="Exportar configuración (sin secretos)")
async def export_config(
    tenant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    return await ConfigPackageService(services.db, services.assistants).export_package(tenant_id)


@router.post("/config/import", summary="Importar configuración (idempotente, admite dry_run)")
async def import_config(
    tenant_id: str,
    package: dict[str, Any],
    principal: ConfigWriter,
    services: ServicesDep,
    request: Request,
    dry_run: bool = True,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        tenant = await uow.tenants.require(tenant_id)
        slug = tenant.slug
    if (package.get("tenant") or {}).get("slug") != slug:
        raise AppError(ErrorCode.VALIDATION_ERROR, "El paquete pertenece a otro tenant")
    result = await ConfigPackageService(services.db, services.assistants).import_package(
        package, dry_run=dry_run, actor_id=principal.user_id
    )
    services.sites.invalidate()
    services.assistants.invalidate(tenant_id)
    await audit_admin(
        services,
        principal,
        "config.imported",
        tenant_id=tenant_id,
        target_type="config_package",
        target_id=slug,
        details={"dry_run": dry_run, "changes": len(result["changes"])},
        request=request,
    )
    return result
