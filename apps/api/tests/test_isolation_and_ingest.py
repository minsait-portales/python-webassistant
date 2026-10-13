"""Ingesta idempotente, ACL, borrado y aislamiento entre tenants (CG-02.3, CG-03, CG-07)."""

from __future__ import annotations

import httpx
import pytest

from tests.conftest import (
    admin_token,
    auth,
    new_conversation,
    server_token,
    user_token,
    widget_token,
)
from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import TenantScopeError, UnitOfWork
from webassistant.rag.search import SearchRequest
from webassistant.rag.vector_store import SearchScope

SECRET_DOC = {
    "source_id": "doc-interno-1",
    "knowledge_base": "ayuda",
    "type": "guide",
    "locale": "es",
    "title": "Procedimiento interno de reembolsos VIP",
    "text": "Los reembolsos VIP se aprueban por el equipo de soporte premium en 24 horas.",
    "acl": ["role:support"],
    "version": "1",
}


async def _search(
    client: httpx.AsyncClient, token: str, query: str, principals: list[str] | None = None
) -> list[str]:
    response = await client.post(
        "/v1/search",
        json={"query": query, "principals": principals or ["public"]},
        headers=auth(token),
    )
    assert response.status_code == 200, response.text
    return [r["source_id"] for r in response.json()["items"]]


async def test_ingest_is_idempotent_and_acl_is_enforced(client: httpx.AsyncClient) -> None:
    token = await server_token(client)
    first = await client.post(
        "/v1/ingest/documents", json={"documents": [SECRET_DOC]}, headers=auth(token)
    )
    assert first.status_code == 202, first.text
    assert first.json()["items"][0]["operation"] == "created"
    repeat = await client.post(
        "/v1/ingest/documents", json={"documents": [SECRET_DOC]}, headers=auth(token)
    )
    assert repeat.json()["items"][0]["operation"] == "unchanged"
    status = (
        await client.get("/v1/ingest/documents/ayuda/doc-interno-1", headers=auth(token))
    ).json()
    assert status["status"] == "ready" and status["chunk_count"] == 1

    assert "doc-interno-1" not in await _search(client, token, "reembolsos VIP soporte premium")
    assert "doc-interno-1" in await _search(
        client, token, "reembolsos VIP soporte premium", ["role:support"]
    )

    acl = await client.put(
        "/v1/ingest/acl/ayuda/doc-interno-1", json={"acl": ["public"]}, headers=auth(token)
    )
    assert acl.json()["operation"] == "acl_updated"
    assert "doc-interno-1" in await _search(client, token, "reembolsos VIP soporte premium")

    changed = await client.post(
        "/v1/ingest/documents",
        json={
            "documents": [
                {
                    **SECRET_DOC,
                    "acl": ["public"],
                    "version": "2",
                    "text": "Nuevo texto sobre reembolsos VIP acelerados.",
                }
            ]
        },
        headers=auth(token),
    )
    assert changed.json()["items"][0]["operation"] == "updated"
    inventory = (await client.get("/v1/ingest/inventory/ayuda", headers=auth(token))).json()
    assert any(
        i["source_id"] == "doc-interno-1" and i["version"] == "2" for i in inventory["items"]
    )

    deleted = await client.delete("/v1/ingest/documents/ayuda/doc-interno-1", headers=auth(token))
    assert deleted.json()["operation"] == "deleted"
    assert "doc-interno-1" not in await _search(client, token, "reembolsos VIP acelerados")


async def test_invalid_documents_are_rejected_individually(client: httpx.AsyncClient) -> None:
    token = await server_token(client)
    response = await client.post(
        "/v1/ingest/documents",
        json={
            "documents": [
                {
                    "source_id": "x",
                    "knowledge_base": "ayuda",
                    "type": "guide",
                    "title": "Sin texto",
                    "version": "1",
                },
                {**SECRET_DOC, "source_id": "ok-1"},
            ]
        },
        headers=auth(token),
    )
    operations = [i["operation"] for i in response.json()["items"]]
    assert operations == ["invalid", "created"]


async def test_credentials_are_not_interchangeable(client: httpx.AsyncClient) -> None:
    widget = (await widget_token(client))["access_token"]
    assert (
        await client.post(
            "/v1/ingest/documents", json={"documents": [SECRET_DOC]}, headers=auth(widget)
        )
    ).status_code == 401
    admin = await admin_token(client)
    assert (await client.get("/v1/conversations", headers=auth(admin))).status_code == 401
    assert (await client.get("/v1/admin/me", headers=auth(widget))).status_code == 401
    server = await server_token(client)
    assert (await client.get("/v1/admin/me", headers=auth(server))).status_code == 401
    bad_secret = await client.post(
        "/v1/token", json={"client_id": "wa_demo_portal", "client_secret": "wrong"}
    )
    assert bad_secret.status_code == 401


async def _second_tenant(services) -> tuple[str, str]:  # type: ignore[no-untyped-def]
    async with UnitOfWork(services.db, platform=True) as uow:
        tenant = m.Tenant(id=ids.new_id("tenant"), slug="otro", name="Otro cliente")
        await uow.tenants.add(tenant)
    async with UnitOfWork(services.db, tenant.id) as uow:
        site = m.Site(
            id=ids.new_id("site"),
            tenant_id=tenant.id,
            public_id="site_otro",
            name="Otro",
            allowed_origins=["http://localhost:8000"],
        )
        kb = m.KnowledgeBase(
            id=ids.new_id("knowledge_base"), tenant_id=tenant.id, key="ayuda", name="Ayuda del otro"
        )
        await uow.sites.add(site)
        await uow.knowledge_bases.add(kb)
    await services.ingestion.upsert_many(
        tenant.id,
        [
            {
                **SECRET_DOC,
                "source_id": "secreto-otro",
                "acl": ["public"],
                "text": "Información confidencial del otro tenant sobre reembolsos VIP.",
            }
        ],
    )
    return tenant.id, site.id


async def test_vector_search_never_crosses_tenants(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    services = app.state.services
    other_tenant, other_site = await _second_tenant(services)
    token = await server_token(client)
    assert "secreto-otro" not in await _search(
        client, token, "información confidencial reembolsos VIP"
    )
    async with UnitOfWork(services.db, platform=True) as uow:
        demo = await uow.tenants.by_slug("demo")
        demo_site = await uow.sites.by_public_id("site_demo")
    async with UnitOfWork(services.db, demo.id) as uow:
        demo_kbs = tuple(kb.id for kb in await uow.knowledge_bases.list())
    results = await services.search.search(
        SearchRequest(
            SearchScope(demo.id, demo_site.id, demo_kbs, ("public",)),
            "información confidencial reembolsos VIP",
            limit=20,
        )
    )
    assert all(r.source_id != "secreto-otro" for r in results)
    # Aunque se pidieran las KB del otro tenant con el tenant demo, el filtro obligatorio lo impide.
    async with UnitOfWork(services.db, other_tenant) as uow:
        other_kbs = tuple(kb.id for kb in await uow.knowledge_bases.list())
    forged = await services.search.search(
        SearchRequest(
            SearchScope(demo.id, demo_site.id, other_kbs, ("public",)),
            "información confidencial",
            limit=20,
        )
    )
    assert forged == []
    legit = await services.search.search(
        SearchRequest(
            SearchScope(other_tenant, other_site, other_kbs, ("public",)),
            "información confidencial",
            limit=20,
        )
    )
    assert [r.source_id for r in legit] == ["secreto-otro"]


async def test_repositories_never_cross_tenants(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    services = app.state.services
    other_tenant, _ = await _second_tenant(services)
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    async with UnitOfWork(services.db, other_tenant) as uow:
        assert await uow.conversations.get(conversation) is None
        assert await uow.documents.count() == 1
    with pytest.raises(TenantScopeError):
        async with UnitOfWork(services.db) as uow:
            pass
    async with UnitOfWork(services.db, other_tenant) as uow:
        with pytest.raises(Exception):
            await uow.conversations.add(
                m.Conversation(
                    id="conv_x",
                    tenant_id="ten_demo_forjado",
                    site_id="s",
                    assistant_id="a",
                    subject="x",
                    subject_type="anonymous",
                )
            )


async def test_widget_token_of_other_site_cannot_be_used(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    services = app.state.services
    await _second_tenant(services)
    services.sites.invalidate()
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    user = await user_token(client)
    assert (
        await client.get(f"/v1/conversations/{conversation}", headers=auth(user))
    ).status_code == 404


async def test_republished_document_is_processed_again(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    """Borrar y volver a publicar el mismo contenido encola un trabajo nuevo (arq deduplica ids)."""
    services = app.state.services
    job_ids: list[str | None] = []
    original = services.queue.enqueue

    async def capture(job: str, *, job_id: str | None = None, **kwargs):  # type: ignore[no-untyped-def]
        job_ids.append(job_id)
        return await original(job, job_id=job_id, **kwargs)

    services.queue.enqueue = capture
    token = await server_token(client)
    document = {**SECRET_DOC, "source_id": "republicado", "acl": ["public"]}
    first = await client.post(
        "/v1/ingest/documents", json={"documents": [document]}, headers=auth(token)
    )
    assert first.json()["items"][0]["operation"] == "created"
    await client.delete("/v1/ingest/documents/ayuda/republicado", headers=auth(token))
    again = await client.post(
        "/v1/ingest/documents", json={"documents": [document]}, headers=auth(token)
    )
    assert again.json()["items"][0]["operation"] == "updated"
    status = (
        await client.get("/v1/ingest/documents/ayuda/republicado", headers=auth(token))
    ).json()
    assert status["status"] == "ready"
    processing = [job_id for job_id in job_ids if job_id and job_id.startswith("doc:")]
    assert len(processing) == 2 and len(set(processing)) == 2
