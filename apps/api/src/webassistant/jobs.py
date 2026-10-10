"""Trabajos asíncronos compartidos por el worker (arq) y la cola inline."""

from __future__ import annotations

from typing import TYPE_CHECKING, Any

from webassistant.observability import context
from webassistant.observability.logging import get_logger

if TYPE_CHECKING:
    from webassistant.queue import InlineQueue
    from webassistant.services.container import Services

logger = get_logger("webassistant.jobs")


async def process_document(
    services: Services, *, tenant_id: str, document_id: str, access_token: str | None = None
) -> str:
    with context.bound(tenant_id=tenant_id):
        return await services.pipeline.process(tenant_id, document_id, access_token)


async def delete_document_vectors(services: Services, *, tenant_id: str, document_id: str) -> None:
    with context.bound(tenant_id=tenant_id):
        await services.pipeline.delete_vectors(tenant_id, document_id)


async def reconcile_tenant(services: Services, *, tenant_id: str) -> dict[str, int]:
    with context.bound(tenant_id=tenant_id):
        stats = await services.pipeline.reconcile_tenant(tenant_id)
    await services.audit.record(
        "rag.reconciled", tenant_id=tenant_id, actor_type="system", actor_id="worker", details=stats
    )
    return stats


async def reindex_embeddings(
    services: Services, *, index_id: str, activate: bool = False
) -> dict[str, int]:
    try:
        stats = await services.pipeline.reindex_into(index_id)
    except Exception as exc:
        await services.indexes.mark_failed(index_id, str(exc))
        raise
    if activate and stats["points"] >= stats["chunks"]:
        await services.indexes.activate(index_id)
    await services.audit.record(
        "rag.reindexed",
        tenant_id=None,
        actor_type="system",
        actor_id="worker",
        target_type="embedding_index",
        target_id=index_id,
        details=stats,
    )
    return stats


async def apply_retention(
    services: Services, *, tenant_id: str | None = None
) -> list[dict[str, Any]]:
    from webassistant.retention.service import RetentionService

    return await RetentionService(services.db, services.audit).apply(tenant_id)


JOBS: dict[str, Any] = {
    "process_document": process_document,
    "delete_document_vectors": delete_document_vectors,
    "reconcile_tenant": reconcile_tenant,
    "reindex_embeddings": reindex_embeddings,
    "apply_retention": apply_retention,
}


def register_inline_jobs(queue: InlineQueue, services: Services) -> None:
    for name, function in JOBS.items():

        async def handler(_function: Any = function, **kwargs: Any) -> Any:
            return await _function(services, **kwargs)

        queue.register(name, handler)
