"""Pipeline de indexación ejecutado por el worker (CG-07.3).

Orden pensado para conciliar fallos sin asumir una transacción común entre
PostgreSQL y Qdrant: (1) vectores en Qdrant con IDs estables, (2) chunks en
PostgreSQL en una transacción, (3) limpieza de puntos obsoletos, (4) estado
``ready``. Un punto sin chunk relacional nunca se devuelve en búsquedas y la
conciliación lo elimina. Reintentar no duplica nada.
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode
from webassistant.gateway.gateway import ModelGateway
from webassistant.observability import metrics
from webassistant.observability.logging import get_logger
from webassistant.observability.tracing import traced
from webassistant.rag.chunking import ChunkingConfig, chunk_document
from webassistant.rag.extraction import (
    ExtractedText,
    extract_file,
    normalize_inline,
    temporary_download,
)
from webassistant.rag.indexes import EmbeddingIndexManager
from webassistant.rag.vector_store import ALL_SITES, VectorPoint, VectorStore
from webassistant.security.redaction import redact_text, truncate
from webassistant.usage.recorder import UsageScope

logger = get_logger("webassistant.rag.pipeline")

EMBED_BATCH = 32
_TYPE_DEFAULT_STRATEGY = {"product": "whole", "faq": "whole"}


@dataclass
class DownloadSettings:
    allowed_hosts: list[str]
    max_bytes: int
    timeout: float
    temp_dir: Path | None = None
    require_allowlist: bool = False
    allow_http: bool = False


def _anchor_url(base: str | None, anchor: str | None) -> str | None:
    if not base:
        return None
    return f"{base}#{anchor}" if anchor and "#" not in base else base


class IndexingPipeline:
    def __init__(
        self,
        db: Database,
        store: VectorStore,
        indexes: EmbeddingIndexManager,
        gateway: ModelGateway,
        downloads: DownloadSettings,
    ) -> None:
        self.db = db
        self.store = store
        self.indexes = indexes
        self.gateway = gateway
        self.downloads = downloads

    async def _set_status(self, tenant_id: str, document_id: str, **values: Any) -> None:
        async with UnitOfWork(self.db, tenant_id) as uow:
            await uow.documents.update_where(values, m.Document.id == document_id)

    async def _text_for(self, document: m.Document, access_token: str | None) -> ExtractedText:
        if document.text is not None and not document.reference:
            return normalize_inline(document.text, document.content_format)
        if not document.reference:
            raise AppError(ErrorCode.VALIDATION_ERROR, "Documento sin texto ni referencia")
        reference = document.reference
        async with temporary_download(
            reference["download_url"],
            access_token=access_token,
            allowed_hosts=self.downloads.allowed_hosts,
            max_bytes=self.downloads.max_bytes,
            timeout=self.downloads.timeout,
            temp_dir=self.downloads.temp_dir,
            require_allowlist=self.downloads.require_allowlist,
            allow_http=self.downloads.allow_http,
        ) as path:
            return extract_file(path, reference["media_type"])

    @traced(
        "ingest.process_document",
        lambda self, tenant_id, document_id, *_, **__: {
            "tenant_id": tenant_id,
            "document_id": document_id,
        },
    )
    async def process(
        self, tenant_id: str, document_id: str, access_token: str | None = None
    ) -> str:
        async with UnitOfWork(self.db, tenant_id) as uow:
            document = await uow.documents.get(document_id)
            if document is None:
                return "missing"
            if document.status == "deleted":
                await self.delete_vectors(tenant_id, document_id)
                return "deleted"
            kb = await uow.knowledge_bases.require(document.knowledge_base_id)
            document.status = "processing"
            document.attempts += 1
            snapshot: dict[str, Any] = {
                "title": document.title,
                "version": document.version,
                "content_hash": document.content_hash,
                "site_id": document.site_id,
                "locale": document.locale,
                "acl": list(document.acl),
                "source_id": document.source_id,
                "source_type": document.source_type,
                "canonical_url": document.canonical_url,
                "kb_id": kb.id,
                "kb_key": kb.key,
                "chunking": dict(kb.chunking or {}),
            }
            # Copia desacoplada de la sesión para trabajar fuera de la transacción.
            detached = m.Document(
                id=document.id,
                text=document.text,
                reference=document.reference,
                content_format=document.content_format,
            )
        try:
            extracted = await self._text_for(detached, access_token)
            chunking_data = {**snapshot["chunking"]}
            per_type = (chunking_data.pop("by_type", {}) or {}).get(snapshot["source_type"], {})
            chunking_data.update(per_type)
            if (
                "strategy" not in chunking_data
                and snapshot["source_type"] in _TYPE_DEFAULT_STRATEGY
            ):
                chunking_data["strategy"] = _TYPE_DEFAULT_STRATEGY[snapshot["source_type"]]
            config = ChunkingConfig.from_dict(chunking_data)
            drafts = chunk_document(
                title=snapshot["title"],
                text=extracted.text,
                content_format=extracted.content_format,
                config=config,
                pages=extracted.pages or None,
            )
            chunk_rows: list[m.Chunk] = []
            for draft in drafts:
                chunk_id = ids.chunk_point_id(
                    tenant_id,
                    snapshot["kb_id"],
                    snapshot["source_id"],
                    snapshot["version"],
                    draft.position,
                )
                chunk_rows.append(
                    m.Chunk(
                        id=chunk_id,
                        tenant_id=tenant_id,
                        site_id=snapshot["site_id"],
                        knowledge_base_id=snapshot["kb_id"],
                        document_id=document_id,
                        document_version=snapshot["version"],
                        position=draft.position,
                        title=draft.title,
                        heading_path=draft.heading_path,
                        text=draft.text,
                        url=_anchor_url(snapshot["canonical_url"], draft.anchor),
                        page=draft.page,
                        token_count=max(1, len(draft.text) // 4),
                        content_hash=hashlib.sha256(draft.text.encode()).hexdigest(),
                    )
                )
            targets = await self.indexes.write_targets()
            scope = UsageScope(
                tenant_id=tenant_id, site_id=snapshot["site_id"], store_payloads=False
            )
            for index in targets:
                await self._embed_and_upsert(
                    index, chunk_rows, snapshot, document_id, tenant_id, scope
                )
            async with UnitOfWork(self.db, tenant_id) as uow:
                await uow.chunks.delete_where(m.Chunk.document_id == document_id)
                for row in chunk_rows:
                    row.embedding_index_id = targets[0].id
                    uow.session.add(row)
                await uow.flush()
                await uow.documents.update_where(
                    {
                        "status": "ready",
                        "error": None,
                        "chunk_count": len(chunk_rows),
                        "indexed_at": datetime.now(UTC),
                        "embedding_index_id": targets[0].id,
                        "text": extracted.text if detached.reference else detached.text,
                    },
                    m.Document.id == document_id,
                )
            keep = [row.id for row in chunk_rows]
            for index in targets:
                await self.store.delete_document(
                    index.collection, tenant_id, document_id, keep_ids=keep
                )
            metrics.INGEST_DOCUMENTS.labels("indexed").inc()
            return "ready"
        except Exception as exc:
            reason = truncate(redact_text(f"{type(exc).__name__}: {exc}"), 500)
            logger.warning("Indexación fallida de %s: %s", document_id, reason)
            await self._set_status(tenant_id, document_id, status="failed", error=reason)
            metrics.INGEST_DOCUMENTS.labels("failed").inc()
            raise

    async def _embed_and_upsert(
        self,
        index: m.EmbeddingIndex,
        rows: list[m.Chunk],
        snapshot: dict[str, Any],
        document_id: str,
        tenant_id: str,
        scope: UsageScope,
    ) -> None:
        for start in range(0, len(rows), EMBED_BATCH):
            batch = rows[start : start + EMBED_BATCH]
            result = await self.gateway.embed(
                index.provider, index.model, [f"{r.title}\n{r.text}" for r in batch], scope=scope
            )
            points = [
                VectorPoint(
                    id=row.id,
                    vector=vector,
                    payload={
                        "tenant_id": tenant_id,
                        "site_id": snapshot["site_id"] or ALL_SITES,
                        "knowledge_base_id": snapshot["kb_id"],
                        "document_id": document_id,
                        "source_id": snapshot["source_id"],
                        "document_type": snapshot["source_type"],
                        "locale": snapshot["locale"],
                        "acl": snapshot["acl"],
                        "version": snapshot["version"],
                        "position": row.position,
                        "embedding_index_id": index.id,
                    },
                )
                for row, vector in zip(batch, result.vectors, strict=True)
            ]
            await self.store.upsert(index.collection, points)

    async def delete_vectors(self, tenant_id: str, document_id: str) -> None:
        async with UnitOfWork(self.db, platform=True) as uow:
            indexes = await uow.embedding_indexes.list(
                m.EmbeddingIndex.status.in_(["active", "building", "retired"])
            )
            collections = [i.collection for i in indexes]
        for collection in collections:
            await self.store.delete_document(collection, tenant_id, document_id)

    async def reconcile_tenant(self, tenant_id: str) -> dict[str, int]:
        """Compara chunks relacionales y puntos vectoriales; repara diferencias."""
        index = await self.indexes.active()
        stats = {"documents": 0, "missing_points": 0, "orphan_points": 0, "requeued": 0}
        async with UnitOfWork(self.db, tenant_id) as uow:
            documents = await uow.documents.list(m.Document.status.in_(["ready", "deleted"]))
            plan = [(d.id, d.status) for d in documents]
        for document_id, status in plan:
            stats["documents"] += 1
            point_ids = set(
                await self.store.document_point_ids(index.collection, tenant_id, document_id)
            )
            if status == "deleted":
                if point_ids:
                    stats["orphan_points"] += len(point_ids)
                    await self.store.delete_points(index.collection, list(point_ids))
                continue
            async with UnitOfWork(self.db, tenant_id) as uow:
                chunk_ids = {
                    c.id for c in await uow.chunks.list(m.Chunk.document_id == document_id)
                }
            orphans = point_ids - chunk_ids
            missing = chunk_ids - point_ids
            if orphans:
                stats["orphan_points"] += len(orphans)
                await self.store.delete_points(index.collection, list(orphans))
            if missing:
                stats["missing_points"] += len(missing)
                await self._set_status(tenant_id, document_id, status="queued")
                await self.process(tenant_id, document_id)
                stats["requeued"] += 1
        return stats

    async def reindex_into(self, index_id: str) -> dict[str, int]:
        """Puebla un índice en construcción desde los chunks relacionales (todos los tenants)."""
        async with UnitOfWork(self.db, platform=True) as uow:
            index = await uow.embedding_indexes.require(index_id)
            tenants = [t.id for t in await uow.tenants.list()]
        total = 0
        for tenant_id in tenants:
            async with UnitOfWork(self.db, tenant_id) as uow:
                documents = await uow.documents.list(m.Document.status == "ready")
                plan = []
                for document in documents:
                    chunks = list(
                        await uow.chunks.list(
                            m.Chunk.document_id == document.id, order_by=m.Chunk.position
                        )
                    )
                    kb = await uow.knowledge_bases.get(document.knowledge_base_id)
                    plan.append(
                        (
                            document.id,
                            chunks,
                            {
                                "site_id": document.site_id,
                                "kb_id": document.knowledge_base_id,
                                "source_id": document.source_id,
                                "source_type": document.source_type,
                                "locale": document.locale,
                                "acl": list(document.acl),
                                "version": document.version,
                                "kb_key": kb.key if kb else "",
                            },
                        )
                    )
            scope = UsageScope(tenant_id=tenant_id, store_payloads=False)
            for document_id, chunks, snapshot in plan:
                await self._embed_and_upsert(index, chunks, snapshot, document_id, tenant_id, scope)
                total += len(chunks)
        indexed = await self.store.count(index.collection)
        async with UnitOfWork(self.db, platform=True) as uow:
            row = await uow.embedding_indexes.require(index_id)
            row.stats = {
                **row.stats,
                "chunks": total,
                "points": indexed,
                "completed_at": datetime.now(UTC).isoformat(),
            }
            row.status = "ready" if indexed >= total else "failed"
        return {"chunks": total, "points": indexed}
