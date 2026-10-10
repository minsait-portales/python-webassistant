"""Versiones del índice vectorial (CG-07.6).

Cada combinación de proveedor, modelo y dimensión vive en su propia colección
de Qdrant. Un reindexado crea una colección nueva en estado ``building``, la
puebla en el worker (con doble escritura de las ingestas nuevas), la valida y
solo entonces pasa a ``active``. La anterior queda ``retired`` para rollback.
"""

from __future__ import annotations

import re
import time
from datetime import UTC, datetime

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode
from webassistant.rag.vector_store import VectorStore


def collection_name(prefix: str, model: str, dimension: int, version: int) -> str:
    slug = re.sub(r"[^a-z0-9]+", "_", model.lower()).strip("_")[:40]
    return f"{prefix}_{slug}_{dimension}_v{version}"


class EmbeddingIndexManager:
    def __init__(self, db: Database, store: VectorStore, prefix: str = "wa_chunks") -> None:
        self.db = db
        self.store = store
        self.prefix = prefix
        self._active: tuple[float, m.EmbeddingIndex | None] = (0.0, None)
        self.cache_seconds = 15.0

    async def _next_version(self, uow: UnitOfWork) -> int:
        return await uow.embedding_indexes.count() + 1

    async def ensure_active(self, provider: str, model: str, dimension: int) -> m.EmbeddingIndex:
        async with UnitOfWork(self.db, platform=True) as uow:
            active = await uow.embedding_indexes.active()
            if active is None:
                active = m.EmbeddingIndex(
                    id=ids.new_id("embedding_index"),
                    provider=provider,
                    model=model,
                    dimension=dimension,
                    collection=collection_name(
                        self.prefix, model, dimension, await self._next_version(uow)
                    ),
                    status="active",
                    activated_at=datetime.now(UTC),
                )
                await uow.embedding_indexes.add(active)
        await self.store.ensure_collection(active.collection, active.dimension)
        self._active = (time.monotonic(), active)
        return active

    async def active(self) -> m.EmbeddingIndex:
        cached_at, index = self._active
        if index is not None and time.monotonic() - cached_at < self.cache_seconds:
            return index
        async with UnitOfWork(self.db, platform=True) as uow:
            index = await uow.embedding_indexes.active()
        if index is None:
            raise AppError(ErrorCode.SERVICE_UNAVAILABLE, "No hay índice vectorial activo")
        await self.store.ensure_collection(index.collection, index.dimension)
        self._active = (time.monotonic(), index)
        return index

    async def building(self) -> list[m.EmbeddingIndex]:
        async with UnitOfWork(self.db, platform=True) as uow:
            return list(
                await uow.embedding_indexes.list(m.EmbeddingIndex.status.in_(["building", "ready"]))
            )

    async def write_targets(self) -> list[m.EmbeddingIndex]:
        """Índices que reciben escrituras: el activo y los que se están construyendo."""
        return [await self.active(), *await self.building()]

    async def create_building(self, provider: str, model: str, dimension: int) -> m.EmbeddingIndex:
        async with UnitOfWork(self.db, platform=True) as uow:
            if await uow.embedding_indexes.first(
                m.EmbeddingIndex.status.in_(["building", "ready"])
            ):
                raise AppError(ErrorCode.CONFLICT, "Ya hay un reindexado en curso")
            index = m.EmbeddingIndex(
                id=ids.new_id("embedding_index"),
                provider=provider,
                model=model,
                dimension=dimension,
                collection=collection_name(
                    self.prefix, model, dimension, await self._next_version(uow)
                ),
                status="building",
            )
            await uow.embedding_indexes.add(index)
        await self.store.ensure_collection(index.collection, dimension)
        return index

    async def activate(self, index_id: str) -> m.EmbeddingIndex:
        async with UnitOfWork(self.db, platform=True) as uow:
            index = await uow.embedding_indexes.require(index_id)
            if index.status not in {"building", "retired", "ready"}:
                raise AppError(
                    ErrorCode.CONFLICT, f"No se puede activar un índice en estado {index.status}"
                )
            now = datetime.now(UTC)
            for current in await uow.embedding_indexes.list(m.EmbeddingIndex.status == "active"):
                current.status = "retired"
                current.retired_at = now
            index.status = "active"
            index.activated_at = now
        self._active = (0.0, None)
        return index

    async def rollback(self) -> m.EmbeddingIndex:
        async with UnitOfWork(self.db, platform=True) as uow:
            previous = await uow.embedding_indexes.first(
                m.EmbeddingIndex.status == "retired", order_by=m.EmbeddingIndex.retired_at.desc()
            )
            if previous is None:
                raise AppError(ErrorCode.CONFLICT, "No hay versión anterior a la que volver")
        return await self.activate(previous.id)

    async def mark_failed(self, index_id: str, reason: str) -> None:
        async with UnitOfWork(self.db, platform=True) as uow:
            index = await uow.embedding_indexes.require(index_id)
            index.status = "failed"
            index.stats = {**index.stats, "error": reason[:500]}
