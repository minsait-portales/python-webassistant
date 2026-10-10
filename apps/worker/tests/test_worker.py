"""El worker expone todos los trabajos y gestiona reintentos sin duplicar derivados."""

from __future__ import annotations

from pathlib import Path
from typing import Any

import pytest
from arq import Retry

from webassistant.config import Settings
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.jobs import JOBS
from webassistant.queue import InlineQueue
from webassistant.seeds.demo import ensure_demo
from webassistant.services.container import build_services


def test_worker_registers_every_job() -> None:
    from webassistant_worker.main import WorkerSettings

    names = {f.__name__ for f in WorkerSettings.functions}
    assert names == set(JOBS)
    assert {c.name for c in WorkerSettings.cron_jobs} >= {
        "cron:nightly_retention",
        "cron:nightly_reconcile",
    }


async def test_retry_policy(monkeypatch: pytest.MonkeyPatch) -> None:
    from webassistant_worker import main

    async def boom(services: Any, **kwargs: Any) -> None:
        raise AppError(ErrorCode.PROVIDER_UNAVAILABLE, "caído")

    async def invalid(services: Any, **kwargs: Any) -> None:
        raise AppError(ErrorCode.VALIDATION_ERROR, "no")

    monkeypatch.setitem(main.JOBS, "process_document", boom)
    task = main._wrap("process_document")
    with pytest.raises(Retry):
        await task({"services": None, "job_try": 1}, tenant_id="t", document_id="d")
    assert (
        await task({"services": None, "job_try": main.MAX_TRIES}, tenant_id="t", document_id="d")
    )["status"] == "failed"
    monkeypatch.setitem(main.JOBS, "process_document", invalid)
    assert (await main._wrap("process_document")({"services": None, "job_try": 1}))[
        "code"
    ] == "validation_error"


async def test_reconcile_repairs_vector_store(tmp_path: Path) -> None:
    settings = Settings(
        database_url=f"sqlite+aiosqlite:///{tmp_path}/w.db",
        environment="test",
        jwt_secret="w" * 40,
        queue_mode="inline",
        qdrant_location=":memory:",
    )
    queue = InlineQueue(run_in_background=False)
    services = build_services(settings, overrides={"queue": queue})
    from webassistant.jobs import register_inline_jobs

    register_inline_jobs(queue, services)
    await services.startup(create_schema=True)
    try:
        result = await ensure_demo(services)
        tenant_id = result["tenant_id"]
        index = await services.indexes.active()
        async with UnitOfWork(services.db, tenant_id) as uow:
            document = await uow.documents.first(m.Document.source_id == "guia-envios")
            chunk_ids = [c.id for c in await uow.chunks.list(m.Chunk.document_id == document.id)]
        await services.store.delete_points(index.collection, chunk_ids[:1])
        stats = await JOBS["reconcile_tenant"](services, tenant_id=tenant_id)
        assert stats["missing_points"] == 1 and stats["requeued"] == 1
        points = await services.store.document_point_ids(index.collection, tenant_id, document.id)
        assert sorted(points) == sorted(chunk_ids)
        again = await services.pipeline.process(tenant_id, document.id)
        assert again == "ready"
        assert sorted(
            await services.store.document_point_ids(index.collection, tenant_id, document.id)
        ) == sorted(chunk_ids), "reprocesar no duplica"
    finally:
        await services.shutdown()
