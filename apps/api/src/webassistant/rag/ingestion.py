"""API de ingesta (CG-07.1, CG-07.2).

Acepta texto autorizado o referencias al documento del portal; hace upsert por
``source_id``, ``version`` y ``content_hash``; propaga cambios de ACL a
PostgreSQL y Qdrant; y gestiona el borrado. Repetir una ingesta no duplica
derivados.
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any
from urllib.parse import urlsplit, urlunsplit

from sqlalchemy import or_

from webassistant import ids
from webassistant.contracts import validate
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode
from webassistant.observability import metrics
from webassistant.queue import JobQueue
from webassistant.rag.indexes import EmbeddingIndexManager
from webassistant.rag.vector_store import ALL_SITES, VectorStore

MAX_BATCH = 100


@dataclass
class IngestOutcome:
    source_id: str
    knowledge_base: str
    status: str
    operation: str
    document_id: str | None = None
    errors: list[dict[str, Any]] | None = None

    def as_dict(self) -> dict[str, Any]:
        data: dict[str, Any] = {
            "source_id": self.source_id,
            "knowledge_base": self.knowledge_base,
            "status": self.status,
            "operation": self.operation,
            "document_id": self.document_id,
        }
        if self.errors:
            data["errors"] = self.errors
        return data


def content_hash_for(doc: dict[str, Any]) -> str:
    provided = str(doc.get("content_hash") or "")
    if provided:
        return provided.removeprefix("sha256:")
    if doc.get("text") is not None:
        basis = doc["text"]
    else:
        reference = doc.get("reference") or {}
        basis = f"{strip_query(str(reference.get('download_url', '')))}|{doc.get('version', '')}"
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()


def strip_query(url: str) -> str:
    """Elimina query y fragmento (posibles credenciales temporales) de una URL."""
    parts = urlsplit(url)
    return urlunsplit((parts.scheme, parts.netloc, parts.path, "", ""))


def _parse_dt(value: Any) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None


class IngestionService:
    def __init__(
        self, db: Database, store: VectorStore, indexes: EmbeddingIndexManager, queue: JobQueue
    ) -> None:
        self.db = db
        self.store = store
        self.indexes = indexes
        self.queue = queue

    async def _resolve_site(
        self, uow: UnitOfWork, public_id: str | None, client_site_id: str | None
    ) -> str | None:
        if not public_id:
            return client_site_id
        site = await uow.sites.by_public_id(public_id)
        if site is None:
            raise AppError(ErrorCode.VALIDATION_ERROR, f"Site desconocido: {public_id}")
        if client_site_id and site.id != client_site_id:
            raise AppError(ErrorCode.FORBIDDEN, "El cliente API no puede ingerir para otro site")
        return site.id

    async def upsert_many(
        self, tenant_id: str, documents: list[dict[str, Any]], *, client_site_id: str | None = None
    ) -> list[IngestOutcome]:
        if len(documents) > MAX_BATCH:
            raise AppError(ErrorCode.PAYLOAD_TOO_LARGE, f"Máximo {MAX_BATCH} documentos por lote")
        outcomes: list[IngestOutcome] = []
        jobs: list[tuple[str, str, str | None]] = []
        payload_updates: list[tuple[str, dict[str, Any]]] = []
        async with UnitOfWork(self.db, tenant_id) as uow:
            for raw in documents:
                outcome, job, payload_update = await self._upsert_one(
                    uow, tenant_id, raw, client_site_id
                )
                outcomes.append(outcome)
                if job:
                    jobs.append(job)
                if payload_update:
                    payload_updates.append(payload_update)
        for document_id, payload in payload_updates:
            for index in await self.indexes.write_targets():
                await self.store.update_document_payload(
                    index.collection, tenant_id, document_id, payload
                )
        for document_id, content_hash, access_token in jobs:
            await self.queue.enqueue(
                "process_document",
                job_id=f"doc:{document_id}:{content_hash[:16]}",
                tenant_id=tenant_id,
                document_id=document_id,
                access_token=access_token,
            )
        for outcome in outcomes:
            metrics.INGEST_DOCUMENTS.labels(outcome.operation).inc()
        return outcomes

    async def _upsert_one(
        self, uow: UnitOfWork, tenant_id: str, raw: dict[str, Any], client_site_id: str | None
    ) -> tuple[
        IngestOutcome, tuple[str, str, str | None] | None, tuple[str, dict[str, Any]] | None
    ]:
        source_id = str(raw.get("source_id", ""))
        kb_key = str(raw.get("knowledge_base", ""))
        errors = validate("canonical-document.v1.schema.json", raw)
        if errors:
            return (
                IngestOutcome(source_id, kb_key, "rejected", "invalid", errors=errors),
                None,
                None,
            )
        kb = await uow.knowledge_bases.by_key(kb_key)
        if kb is None:
            return (
                IngestOutcome(
                    source_id,
                    kb_key,
                    "rejected",
                    "invalid",
                    errors=[
                        {"path": "/knowledge_base", "message": "Base de conocimiento desconocida"}
                    ],
                ),
                None,
                None,
            )
        if client_site_id and kb.site_id and kb.site_id != client_site_id:
            raise AppError(ErrorCode.FORBIDDEN, "La base de conocimiento pertenece a otro site")
        site_id = kb.site_id
        if raw.get("site_id"):
            site_id = await self._resolve_site(uow, raw["site_id"], client_site_id)
            if kb.site_id and site_id != kb.site_id:
                raise AppError(
                    ErrorCode.FORBIDDEN,
                    "El site del documento no coincide con el de la base de conocimiento",
                )
        elif client_site_id and not kb.site_id:
            site_id = client_site_id
        content_hash = content_hash_for(raw)
        reference = raw.get("reference")
        access_token = reference.get("access_token") if reference else None
        sanitized_reference = (
            {
                "download_url": strip_query(reference["download_url"]),
                "media_type": reference["media_type"],
            }
            if reference
            else None
        )
        acl = sorted(set(raw.get("acl") or ["public"]))
        metadata = dict(raw.get("metadata") or {})
        existing = await uow.documents.by_source(kb.id, source_id)
        now = datetime.now(UTC)

        if existing is None:
            document = m.Document(
                id=ids.new_id("document"),
                tenant_id=tenant_id,
                site_id=site_id,
                knowledge_base_id=kb.id,
                source_id=source_id,
                source_type=raw["type"],
                locale=raw.get("locale", kb.default_locale),
                title=raw["title"],
                canonical_url=raw.get("canonical_url"),
                acl=acl,
                version=str(raw["version"]),
                content_hash=content_hash,
                content_format=raw.get("content_format", "text"),
                metadata_=metadata,
                text=raw.get("text"),
                reference=sanitized_reference,
                status="queued",
                source_created_at=_parse_dt(raw.get("created_at")),
                source_updated_at=_parse_dt(raw.get("updated_at")),
            )
            await uow.documents.add(document)
            return (
                IngestOutcome(source_id, kb_key, "queued", "created", document.id),
                (document.id, content_hash, access_token),
                None,
            )

        content_changed = (
            existing.content_hash != content_hash
            or existing.version != str(raw["version"])
            or existing.status in {"failed", "deleted"}
            or existing.content_format != raw.get("content_format", "text")
        )
        payload_changed = (
            existing.acl != acl
            or existing.locale != raw.get("locale", existing.locale)
            or existing.site_id != site_id
            or existing.source_type != raw["type"]
        )
        metadata_changed = (
            existing.metadata_ != metadata
            or existing.title != raw["title"]
            or existing.canonical_url != raw.get("canonical_url")
        )
        existing.title = raw["title"]
        existing.canonical_url = raw.get("canonical_url")
        existing.metadata_ = metadata
        existing.acl = acl
        existing.locale = raw.get("locale", existing.locale)
        existing.site_id = site_id
        existing.source_type = raw["type"]
        existing.source_updated_at = _parse_dt(raw.get("updated_at")) or existing.source_updated_at
        if content_changed:
            existing.version = str(raw["version"])
            existing.content_hash = content_hash
            existing.content_format = raw.get("content_format", "text")
            existing.text = raw.get("text")
            existing.reference = sanitized_reference
            existing.status = "queued"
            existing.error = None
            existing.attempts = 0
            existing.deleted_at = None
            existing.updated_at = now
            return (
                IngestOutcome(source_id, kb_key, "queued", "updated", existing.id),
                (existing.id, content_hash, access_token),
                None,
            )
        if payload_changed:
            payload = {
                "acl": acl,
                "locale": existing.locale,
                "site_id": site_id or ALL_SITES,
                "document_type": existing.source_type,
            }
            await uow.chunks.update_where({"site_id": site_id}, m.Chunk.document_id == existing.id)
            return (
                IngestOutcome(source_id, kb_key, existing.status, "acl_updated", existing.id),
                None,
                (existing.id, payload),
            )
        if metadata_changed:
            return (
                IngestOutcome(source_id, kb_key, existing.status, "metadata_updated", existing.id),
                None,
                None,
            )
        return (
            IngestOutcome(source_id, kb_key, existing.status, "unchanged", existing.id),
            None,
            None,
        )

    async def delete(
        self, tenant_id: str, kb_key: str, source_id: str, *, client_site_id: str | None = None
    ) -> IngestOutcome:
        async with UnitOfWork(self.db, tenant_id) as uow:
            kb = await uow.knowledge_bases.by_key(kb_key)
            if kb is None:
                raise AppError(ErrorCode.NOT_FOUND, "Base de conocimiento no encontrada")
            if client_site_id and kb.site_id and kb.site_id != client_site_id:
                raise AppError(ErrorCode.FORBIDDEN, "La base de conocimiento pertenece a otro site")
            document = await uow.documents.by_source(kb.id, source_id)
            if document is None:
                return IngestOutcome(source_id, kb_key, "deleted", "unchanged")
            document.status = "deleted"
            document.deleted_at = datetime.now(UTC)
            document.text = None
            document.chunk_count = 0
            await uow.chunks.delete_where(m.Chunk.document_id == document.id)
            document_id = document.id
        await self.queue.enqueue(
            "delete_document_vectors", tenant_id=tenant_id, document_id=document_id
        )
        metrics.INGEST_DOCUMENTS.labels("deleted").inc()
        return IngestOutcome(source_id, kb_key, "deleted", "deleted", document_id)

    async def update_acl(
        self, tenant_id: str, kb_key: str, source_id: str, acl: list[str]
    ) -> IngestOutcome:
        async with UnitOfWork(self.db, tenant_id) as uow:
            kb = await uow.knowledge_bases.by_key(kb_key)
            document = await uow.documents.by_source(kb.id, source_id) if kb else None
            if document is None or document.status == "deleted":
                raise AppError(ErrorCode.NOT_FOUND, "Documento no encontrado")
            document.acl = sorted(set(acl))
            document_id, status = document.id, document.status
        for index in await self.indexes.write_targets():
            await self.store.update_document_payload(
                index.collection, tenant_id, document_id, {"acl": sorted(set(acl))}
            )
        return IngestOutcome(source_id, kb_key, status, "acl_updated", document_id)

    async def status(self, tenant_id: str, kb_key: str, source_id: str) -> dict[str, Any]:
        async with UnitOfWork(self.db, tenant_id) as uow:
            kb = await uow.knowledge_bases.by_key(kb_key)
            document = await uow.documents.by_source(kb.id, source_id) if kb else None
            if document is None:
                raise AppError(ErrorCode.NOT_FOUND, "Documento no encontrado")
            return document_view(document, kb_key)

    async def inventory(
        self, tenant_id: str, kb_key: str, *, cursor: str | None = None, limit: int = 500
    ) -> dict[str, Any]:
        """Inventario para conciliación con el portal (DR-05.10): source_id, versión y hash."""
        async with UnitOfWork(self.db, tenant_id) as uow:
            kb = await uow.knowledge_bases.by_key(kb_key)
            if kb is None:
                raise AppError(ErrorCode.NOT_FOUND, "Base de conocimiento no encontrada")
            conditions: list[Any] = [
                m.Document.knowledge_base_id == kb.id,
                m.Document.status != "deleted",
            ]
            if cursor:
                conditions.append(m.Document.source_id > cursor)
            rows = await uow.documents.list(
                *conditions, order_by=m.Document.source_id, limit=limit + 1
            )
        items = [
            {
                "source_id": d.source_id,
                "version": d.version,
                "content_hash": d.content_hash,
                "status": d.status,
                "acl": d.acl,
            }
            for d in rows[:limit]
        ]
        return {
            "items": items,
            "next_cursor": rows[limit - 1].source_id if len(rows) > limit else None,
        }

    async def retry_failed(
        self,
        tenant_id: str,
        *,
        knowledge_base_id: str | None = None,
        document_id: str | None = None,
    ) -> int:
        async with UnitOfWork(self.db, tenant_id) as uow:
            conditions: list[Any] = [
                or_(m.Document.status == "failed", m.Document.status == "queued")
            ]
            if knowledge_base_id:
                conditions.append(m.Document.knowledge_base_id == knowledge_base_id)
            if document_id:
                conditions.append(m.Document.id == document_id)
            documents = await uow.documents.list(*conditions)
            targets = [(d.id, d.content_hash) for d in documents]
            for document in documents:
                document.status = "queued"
                document.error = None
        for doc_id, content_hash in targets:
            await self.queue.enqueue(
                "process_document",
                job_id=f"doc:{doc_id}:{content_hash[:16]}:retry",
                tenant_id=tenant_id,
                document_id=doc_id,
                access_token=None,
            )
        return len(targets)


def document_view(document: m.Document, kb_key: str | None = None) -> dict[str, Any]:
    return {
        "document_id": document.id,
        "source_id": document.source_id,
        "knowledge_base": kb_key,
        "type": document.source_type,
        "title": document.title,
        "locale": document.locale,
        "version": document.version,
        "content_hash": document.content_hash,
        "status": document.status,
        "error": document.error,
        "attempts": document.attempts,
        "chunk_count": document.chunk_count,
        "acl": document.acl,
        "canonical_url": document.canonical_url,
        "indexed_at": document.indexed_at.isoformat() if document.indexed_at else None,
        "updated_at": document.updated_at.isoformat() if document.updated_at else None,
    }
