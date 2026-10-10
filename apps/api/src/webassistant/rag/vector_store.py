"""Acceso a Qdrant con filtros de aislamiento obligatorios (CG-07.5, CG-07.6).

La colección de Qdrant nunca es el único límite de seguridad: toda consulta
se construye a partir de un :class:`SearchScope` validado por el backend, que
exige tenant, site, bases de conocimiento y principales ACL. No existe un
método de búsqueda sin ese filtro.
"""

from __future__ import annotations

import uuid
from collections.abc import Iterable, Sequence
from dataclasses import dataclass, field
from typing import Any

from qdrant_client import AsyncQdrantClient
from qdrant_client import models as qm

from webassistant.observability.logging import get_logger

logger = get_logger("webassistant.rag.qdrant")

PAYLOAD_INDEXES: dict[str, Any] = {
    "tenant_id": qm.KeywordIndexParams(type=qm.KeywordIndexType.KEYWORD, is_tenant=True),
    "site_id": qm.PayloadSchemaType.KEYWORD,
    "knowledge_base_id": qm.PayloadSchemaType.KEYWORD,
    "locale": qm.PayloadSchemaType.KEYWORD,
    "acl": qm.PayloadSchemaType.KEYWORD,
    "document_id": qm.PayloadSchemaType.KEYWORD,
    "source_id": qm.PayloadSchemaType.KEYWORD,
    "document_type": qm.PayloadSchemaType.KEYWORD,
}

ALL_SITES = "*"
ALL_LOCALES = "*"


@dataclass(frozen=True)
class SearchScope:
    tenant_id: str
    site_id: str
    knowledge_base_ids: tuple[str, ...]
    principals: tuple[str, ...]
    locales: tuple[str, ...] = ()

    def __post_init__(self) -> None:
        if not self.tenant_id or not self.site_id:
            raise ValueError("SearchScope requiere tenant_id y site_id")
        if not self.knowledge_base_ids:
            raise ValueError("SearchScope requiere al menos una base de conocimiento")
        if not self.principals:
            raise ValueError("SearchScope requiere principales ACL")

    def to_filter(self, extra: Sequence[qm.Condition] = ()) -> qm.Filter:
        must: list[qm.Condition] = [
            qm.FieldCondition(key="tenant_id", match=qm.MatchValue(value=self.tenant_id)),
            qm.FieldCondition(key="site_id", match=qm.MatchAny(any=[self.site_id, ALL_SITES])),
            qm.FieldCondition(
                key="knowledge_base_id", match=qm.MatchAny(any=list(self.knowledge_base_ids))
            ),
            qm.FieldCondition(key="acl", match=qm.MatchAny(any=list(self.principals))),
        ]
        if self.locales:
            must.append(
                qm.FieldCondition(key="locale", match=qm.MatchAny(any=[*self.locales, ALL_LOCALES]))
            )
        must.extend(extra)
        return qm.Filter(must=must)


@dataclass
class VectorPoint:
    id: str
    vector: list[float]
    payload: dict[str, Any] = field(default_factory=dict)


@dataclass
class VectorHit:
    id: str
    score: float
    payload: dict[str, Any]


class VectorStore:
    def __init__(self, client: AsyncQdrantClient) -> None:
        self.client = client
        self._known: set[str] = set()

    @classmethod
    def connect(
        cls, *, url: str | None, api_key: str | None, location: str | None, timeout: float = 10.0
    ) -> VectorStore:
        if url:
            client = AsyncQdrantClient(url=url, api_key=api_key, timeout=int(timeout))
        else:
            client = AsyncQdrantClient(location=location or ":memory:")
        return cls(client)

    async def ensure_collection(self, name: str, dimension: int) -> None:
        if name in self._known:
            return
        if not await self.client.collection_exists(name):
            await self.client.create_collection(
                name,
                vectors_config=qm.VectorParams(size=dimension, distance=qm.Distance.COSINE),
                hnsw_config=qm.HnswConfigDiff(payload_m=16, m=0),
            )
            for field_name, schema in PAYLOAD_INDEXES.items():
                try:
                    await self.client.create_payload_index(name, field_name, field_schema=schema)
                except Exception as exc:  # índice ya existente o modo local
                    logger.debug("Índice de payload %s no creado: %s", field_name, exc)
            logger.info("Colección de Qdrant creada: %s (dim=%s)", name, dimension)
        self._known.add(name)

    async def delete_collection(self, name: str) -> None:
        if await self.client.collection_exists(name):
            await self.client.delete_collection(name)
        self._known.discard(name)

    async def upsert(self, collection: str, points: Iterable[VectorPoint]) -> int:
        batch = [qm.PointStruct(id=p.id, vector=p.vector, payload=p.payload) for p in points]
        if not batch:
            return 0
        for start in range(0, len(batch), 128):
            await self.client.upsert(collection, points=batch[start : start + 128], wait=True)
        return len(batch)

    @staticmethod
    def _document_filter(tenant_id: str, document_id: str) -> qm.Filter:
        return qm.Filter(
            must=[
                qm.FieldCondition(key="tenant_id", match=qm.MatchValue(value=tenant_id)),
                qm.FieldCondition(key="document_id", match=qm.MatchValue(value=document_id)),
            ]
        )

    async def delete_document(
        self, collection: str, tenant_id: str, document_id: str, *, keep_ids: Sequence[str] = ()
    ) -> None:
        if not await self.client.collection_exists(collection):
            return
        flt = self._document_filter(tenant_id, document_id)
        if keep_ids:
            flt.must_not = [qm.HasIdCondition(has_id=list(keep_ids))]
        await self.client.delete(
            collection, points_selector=qm.FilterSelector(filter=flt), wait=True
        )

    async def delete_points(self, collection: str, ids: Sequence[str]) -> None:
        if ids and await self.client.collection_exists(collection):
            await self.client.delete(
                collection, points_selector=qm.PointIdsList(points=list(ids)), wait=True
            )

    async def update_document_payload(
        self, collection: str, tenant_id: str, document_id: str, payload: dict[str, Any]
    ) -> None:
        if not await self.client.collection_exists(collection):
            return
        await self.client.set_payload(
            collection,
            payload=payload,
            points=qm.FilterSelector(filter=self._document_filter(tenant_id, document_id)),
            wait=True,
        )

    async def search(
        self,
        collection: str,
        vector: list[float],
        scope: SearchScope,
        *,
        limit: int,
        min_score: float | None = None,
        extra: Sequence[qm.Condition] = (),
    ) -> list[VectorHit]:
        if not await self.client.collection_exists(collection):
            return []
        response = await self.client.query_points(
            collection,
            query=vector,
            query_filter=scope.to_filter(extra),
            limit=limit,
            with_payload=True,
            score_threshold=min_score,
        )
        return [
            VectorHit(id=str(p.id), score=float(p.score), payload=dict(p.payload or {}))
            for p in response.points
        ]

    async def document_point_ids(
        self, collection: str, tenant_id: str, document_id: str
    ) -> list[str]:
        if not await self.client.collection_exists(collection):
            return []
        ids: list[str] = []
        offset = None
        while True:
            points, offset = await self.client.scroll(
                collection,
                scroll_filter=self._document_filter(tenant_id, document_id),
                limit=256,
                offset=offset,
                with_payload=False,
                with_vectors=False,
            )
            ids.extend(str(p.id) for p in points)
            if offset is None:
                break
        return ids

    async def count(self, collection: str, tenant_id: str | None = None) -> int:
        if not await self.client.collection_exists(collection):
            return 0
        flt = None
        if tenant_id:
            flt = qm.Filter(
                must=[qm.FieldCondition(key="tenant_id", match=qm.MatchValue(value=tenant_id))]
            )
        return int((await self.client.count(collection, count_filter=flt, exact=True)).count)

    async def retrieve_vectors(self, collection: str, ids: Sequence[str]) -> dict[str, list[float]]:
        if not ids or not await self.client.collection_exists(collection):
            return {}
        points = await self.client.retrieve(
            collection, ids=list(ids), with_vectors=True, with_payload=False
        )
        result: dict[str, list[float]] = {}
        for point in points:
            vector = point.vector
            if isinstance(vector, list):
                result[str(point.id)] = [float(v) for v in vector]  # type: ignore[arg-type]
        return result

    async def snapshot(self, collection: str) -> str | None:
        """Crea un snapshot de la colección (backups externos, CG-13.5)."""
        try:
            description = await self.client.create_snapshot(collection, wait=True)
        except Exception as exc:
            logger.error("No se pudo crear el snapshot de %s: %s", collection, exc)
            return None
        return description.name if description else None

    async def health(self) -> bool:
        try:
            await self.client.get_collections()
        except Exception:
            return False
        return True

    async def close(self) -> None:
        await self.client.close()


def is_valid_point_id(value: str) -> bool:
    try:
        uuid.UUID(value)
    except ValueError:
        return False
    return True
