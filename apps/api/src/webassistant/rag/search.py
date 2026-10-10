"""Búsqueda semántica aislada (CG-07.5).

1. Filtro obligatorio en Qdrant por tenant, site, bases de conocimiento,
   locale y ACL derivados del contexto validado por el backend.
2. Contraste con el registro relacional antes de devolver resultados: se
   excluyen documentos borrados, no listos, de versión obsoleta o cuya ACL
   vigente ya no permite el acceso.
"""

from __future__ import annotations

import time
from dataclasses import dataclass

from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.gateway.gateway import ModelGateway
from webassistant.observability import metrics
from webassistant.rag.indexes import EmbeddingIndexManager
from webassistant.rag.vector_store import SearchScope, VectorStore
from webassistant.usage.recorder import UsageScope, UsageSummary
from webassistant_skill_sdk import RetrievedSource

_PUBLIC_METADATA = {
    "product_id",
    "variation_id",
    "sku",
    "price",
    "price_amount",
    "currency",
    "image_url",
    "image_alt",
    "category",
    "brand",
    "availability",
    "rating",
    "store_name",
    "badges",
    "url",
}


@dataclass
class SearchRequest:
    scope: SearchScope
    query: str
    limit: int = 8
    min_score: float | None = None


class SearchService:
    def __init__(
        self,
        db: Database,
        store: VectorStore,
        indexes: EmbeddingIndexManager,
        gateway: ModelGateway,
    ) -> None:
        self.db = db
        self.store = store
        self.indexes = indexes
        self.gateway = gateway

    async def search(
        self,
        request: SearchRequest,
        *,
        usage_scope: UsageScope | None = None,
        summary: UsageSummary | None = None,
    ) -> list[RetrievedSource]:
        started = time.perf_counter()
        scope = request.scope
        index = await self.indexes.active()
        embedding = await self.gateway.embed(
            index.provider, index.model, [request.query[:2000]], scope=usage_scope, summary=summary
        )
        hits = await self.store.search(
            index.collection,
            embedding.vectors[0],
            scope,
            limit=max(1, request.limit * 2),
            min_score=request.min_score,
        )
        if not hits:
            metrics.RAG_SEARCHES.labels(scope.tenant_id, "empty").inc()
            metrics.RAG_HITS.observe(0)
            metrics.RAG_LATENCY.observe(time.perf_counter() - started)
            return []
        principals = set(scope.principals)
        async with UnitOfWork(self.db, scope.tenant_id) as uow:
            chunks = {c.id: c for c in await uow.chunks.by_ids([h.id for h in hits])}
            document_ids = list({c.document_id for c in chunks.values()})
            documents = (
                {d.id: d for d in await uow.documents.list(m.Document.id.in_(document_ids))}
                if document_ids
                else {}
            )
            kb_keys = {
                kb.id: kb.key
                for kb in await uow.knowledge_bases.list(
                    m.KnowledgeBase.id.in_(list(scope.knowledge_base_ids))
                )
            }
        results: list[RetrievedSource] = []
        seen_chunks: set[str] = set()
        for hit in hits:
            chunk = chunks.get(hit.id)
            if chunk is None or chunk.id in seen_chunks:
                continue
            document = documents.get(chunk.document_id)
            if document is None or document.status != "ready" or document.deleted_at is not None:
                continue
            if chunk.document_version != document.version:
                continue
            if document.knowledge_base_id not in scope.knowledge_base_ids:
                continue
            if document.site_id not in (None, scope.site_id):
                continue
            if not principals.intersection(document.acl or []):
                continue
            seen_chunks.add(chunk.id)
            metadata = {
                k: v for k, v in (document.metadata_ or {}).items() if k in _PUBLIC_METADATA
            }
            results.append(
                RetrievedSource(
                    chunk_id=chunk.id,
                    source_id=document.source_id,
                    knowledge_base=kb_keys.get(document.knowledge_base_id, ""),
                    title=document.title,
                    text=chunk.text,
                    score=hit.score,
                    url=chunk.url or document.canonical_url,
                    locale=document.locale,
                    document_type=document.source_type,
                    page=chunk.page,
                    metadata=metadata,
                )
            )
            if len(results) >= request.limit:
                break
        metrics.RAG_SEARCHES.labels(scope.tenant_id, "hit" if results else "filtered").inc()
        metrics.RAG_HITS.observe(len(results))
        metrics.RAG_LATENCY.observe(time.perf_counter() - started)
        return results
