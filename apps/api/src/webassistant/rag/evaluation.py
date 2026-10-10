"""Evaluación de calidad de recuperación (CG-07.7).

Dataset YAML::

    knowledge_bases: [catalogo, ayuda]
    cases:
      - question: "¿Cómo devuelvo un pedido?"
        expected_sources: [guia-devoluciones]
        principals: [public]          # opcional
        forbidden_sources: [doc-privado]  # opcional: nunca deben aparecer

Métricas: hit rate@k, recall@k, MRR y apariciones de fuentes prohibidas
(debe ser 0: ausencia de fuentes no autorizadas o inventadas).
"""

from __future__ import annotations

from typing import Any

from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.rag.search import SearchRequest
from webassistant.rag.vector_store import SearchScope


async def evaluate(
    services: Any, dataset: dict[str, Any], *, tenant_slug: str, site_public_id: str, k: int = 5
) -> dict[str, Any]:
    async with UnitOfWork(services.db, platform=True) as uow:
        tenant = await uow.tenants.by_slug(tenant_slug)
        if tenant is None:
            raise AppError(ErrorCode.NOT_FOUND, "Tenant no encontrado")
        site = await uow.sites.by_public_id(site_public_id)
        if site is None or site.tenant_id != tenant.id:
            raise AppError(ErrorCode.NOT_FOUND, "Site no encontrado")
    async with UnitOfWork(services.db, tenant.id) as uow:
        keys = dataset.get("knowledge_bases") or []
        conditions = [m.KnowledgeBase.key.in_(keys)] if keys else []
        kb_ids = tuple(kb.id for kb in await uow.knowledge_bases.list(*conditions))
    cases = dataset.get("cases") or []
    hits = 0
    recall_total = 0.0
    reciprocal = 0.0
    forbidden_hits = 0
    details = []
    for case in cases:
        results = await services.search.search(
            SearchRequest(
                scope=SearchScope(
                    tenant_id=tenant.id,
                    site_id=site.id,
                    knowledge_base_ids=kb_ids,
                    principals=tuple(case.get("principals") or ["public"]),
                ),
                query=case["question"],
                limit=k,
            )
        )
        found = [r.source_id for r in results]
        expected = set(case.get("expected_sources") or [])
        forbidden = set(case.get("forbidden_sources") or [])
        rank = next((i + 1 for i, source in enumerate(found) if source in expected), None)
        if rank:
            hits += 1
            reciprocal += 1 / rank
        recall_total += len(expected & set(found)) / len(expected) if expected else 1.0
        forbidden_found = sorted(forbidden & set(found))
        forbidden_hits += len(forbidden_found)
        details.append(
            {
                "question": case["question"],
                "expected": sorted(expected),
                "found": found,
                "rank": rank,
                "forbidden_found": forbidden_found,
            }
        )
    total = max(1, len(cases))
    return {
        "cases": len(cases),
        "k": k,
        "hit_rate": hits / total,
        "recall": recall_total / total,
        "mrr": reciprocal / total,
        "forbidden_hits": forbidden_hits,
        "details": details,
    }
