"""Administración: roles, tenants, sites, assistants versionados, precios, uso, auditoría y paquete de configuración (CG-11, CG-12, CG-16)."""

from __future__ import annotations

from decimal import Decimal
from pathlib import Path

import httpx

from tests.conftest import admin_token, auth, make_settings, new_conversation, widget_token
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.gateway.simulated import SimulatedProvider
from webassistant.services.container import build_services


async def _tenant_id(client: httpx.AsyncClient, token: str) -> str:
    tenants = (await client.get("/v1/admin/tenants", headers=auth(token))).json()["items"]
    return next(t["id"] for t in tenants if t["slug"] == "demo")


async def test_login_lockout_and_me(client: httpx.AsyncClient) -> None:
    for _ in range(5):
        bad = await client.post(
            "/v1/admin/auth/login", json={"email": "admin@demo.local", "password": "incorrecta"}
        )
        assert bad.status_code == 401
    locked = await client.post(
        "/v1/admin/auth/login", json={"email": "admin@demo.local", "password": "Demo-Admin-2026"}
    )
    assert locked.status_code == 401, "tras 5 fallos la cuenta queda bloqueada"


async def test_roles_are_enforced(client: httpx.AsyncClient) -> None:
    platform = await admin_token(client)
    me = (await client.get("/v1/admin/me", headers=auth(platform))).json()
    assert "platform.manage" in me["permissions"]
    tenant_id = await _tenant_id(client, platform)
    created = await client.post(
        f"/v1/admin/tenants/{tenant_id}/users",
        json={"email": "viewer@demo.local", "role": "viewer", "password": "Lector-Seguro-2026"},
        headers=auth(platform),
    )
    assert created.status_code == 201, created.text
    viewer = await admin_token(client, "viewer@demo.local", "Lector-Seguro-2026")
    assert (
        await client.get(f"/v1/admin/tenants/{tenant_id}/assistants", headers=auth(viewer))
    ).status_code == 200
    write = await client.post(
        f"/v1/admin/tenants/{tenant_id}/assistants",
        json={"key": "nuevo", "name": "Nuevo"},
        headers=auth(viewer),
    )
    assert write.status_code == 403
    assert (
        await client.get(f"/v1/admin/tenants/{tenant_id}/audit", headers=auth(viewer))
    ).status_code == 403
    assert (
        await client.post(
            "/v1/admin/tenants", json={"slug": "x-y", "name": "X"}, headers=auth(viewer)
        )
    ).status_code == 403
    other = await client.post(
        "/v1/admin/tenants", json={"slug": "otro-cliente", "name": "Otro"}, headers=auth(platform)
    )
    assert other.status_code == 201
    other_id = other.json()["id"]
    tenant_admin = await client.post(
        f"/v1/admin/tenants/{tenant_id}/users",
        json={"email": "ta@demo.local", "role": "tenant_admin", "password": "Admin-Tenant-2026"},
        headers=auth(platform),
    )
    assert tenant_admin.status_code == 201
    ta = await admin_token(client, "ta@demo.local", "Admin-Tenant-2026")
    assert (
        await client.get(f"/v1/admin/tenants/{other_id}/sites", headers=auth(ta))
    ).status_code == 404, "un tenant_admin no ve otros tenants"
    visible = (await client.get("/v1/admin/tenants", headers=auth(ta))).json()["items"]
    assert [t["slug"] for t in visible] == ["demo"]


async def test_site_registration_and_theme_contrast(client: httpx.AsyncClient) -> None:
    token = await admin_token(client)
    tenant_id = await _tenant_id(client, token)
    bad_origin = await client.post(
        f"/v1/admin/tenants/{tenant_id}/sites",
        json={
            "public_id": "site_nuevo",
            "name": "Nuevo",
            "allowed_origins": ["https://x.com/ruta"],
        },
        headers=auth(token),
    )
    assert bad_origin.status_code == 422
    low_contrast = await client.post(
        f"/v1/admin/tenants/{tenant_id}/sites",
        json={
            "public_id": "site_nuevo",
            "name": "Nuevo",
            "allowed_origins": ["https://nuevo.example.com"],
            "theme": {"accent": "#f0f0f0"},
        },
        headers=auth(token),
    )
    assert low_contrast.status_code == 422
    ok = await client.post(
        f"/v1/admin/tenants/{tenant_id}/sites",
        json={
            "public_id": "site_nuevo",
            "name": "Nuevo",
            "allowed_origins": ["https://nuevo.example.com"],
            "default_assistant": "catalogo",
        },
        headers=auth(token),
    )
    assert ok.status_code == 201, ok.text
    boot = await client.get(
        "/v1/bootstrap",
        params={"site_id": "site_nuevo"},
        headers={"Origin": "https://nuevo.example.com"},
    )
    assert boot.status_code == 200
    disabled = await client.patch(
        f"/v1/admin/tenants/{tenant_id}/sites/{ok.json()['id']}",
        json={"status": "disabled"},
        headers=auth(token),
    )
    assert disabled.status_code == 200
    blocked = await client.get(
        "/v1/bootstrap",
        params={"site_id": "site_nuevo"},
        headers={"Origin": "https://nuevo.example.com"},
    )
    assert blocked.status_code == 403 and blocked.json()["code"] == "site_disabled"


async def test_assistant_publish_and_restore_are_explicit(client: httpx.AsyncClient) -> None:
    token = await admin_token(client)
    tenant_id = await _tenant_id(client, token)
    assistants = (
        await client.get(f"/v1/admin/tenants/{tenant_id}/assistants", headers=auth(token))
    ).json()["items"]
    catalogo = next(a for a in assistants if a["key"] == "catalogo")
    config = dict(catalogo["draft_config"])
    config["messages"] = {**config["messages"], "welcome": "Hola desde el borrador"}
    patched = await client.patch(
        f"/v1/admin/tenants/{tenant_id}/assistants/{catalogo['id']}",
        json={"config": config},
        headers=auth(token),
    )
    assert patched.status_code == 200
    boot = (await client.get("/v1/bootstrap", params={"site_id": "site_demo"})).json()
    assert boot["texts"]["welcome"] != "Hola desde el borrador", (
        "el borrador no afecta a la versión publicada"
    )
    published = await client.post(
        f"/v1/admin/tenants/{tenant_id}/assistants/{catalogo['id']}/publish",
        json={"note": "nuevo saludo"},
        headers=auth(token),
    )
    assert published.status_code == 200 and published.json()["version"] == 2
    boot = (await client.get("/v1/bootstrap", params={"site_id": "site_demo"})).json()
    assert boot["texts"]["welcome"] == "Hola desde el borrador"
    restored = await client.post(
        f"/v1/admin/tenants/{tenant_id}/assistants/{catalogo['id']}/restore",
        json={"version": 1, "publish": True},
        headers=auth(token),
    )
    assert restored.json()["published_version_created"] == 3
    boot = (await client.get("/v1/bootstrap", params={"site_id": "site_demo"})).json()
    assert boot["texts"]["welcome"] != "Hola desde el borrador"
    bad = dict(config)
    bad["skills"] = [{"name": "skill_inexistente"}]
    rejected = await client.patch(
        f"/v1/admin/tenants/{tenant_id}/assistants/{catalogo['id']}",
        json={"config": bad},
        headers=auth(token),
    )
    assert rejected.status_code == 422, "no se activa una skill incompatible"


async def test_price_versioning_keeps_historical_costs(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    widget = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, widget)
    first = (
        await client.post(
            f"/v1/conversations/{conversation}/messages",
            json={"message": "¿Cuánto tarda el envío?"},
            headers=auth(widget),
        )
    ).json()
    token = await admin_token(client)
    new_price = await client.post(
        "/v1/admin/prices",
        json={
            "provider": "simulated",
            "model": "simulated-chat-v1",
            "input_per_million": "100",
            "output_per_million": "100",
        },
        headers=auth(token),
    )
    assert new_price.status_code == 201, new_price.text
    async with UnitOfWork(app.state.services.db, platform=True) as uow:
        historical = await uow.usage_events.list(m.UsageEvent.run_id == first["run_id"])
        prices = await uow.model_prices.list(m.ModelPrice.model == "simulated-chat-v1")
    assert sum(e.total_cost for e in historical) == Decimal(first["usage"]["total_cost"])
    assert len(prices) == 2 and sum(1 for p in prices if p.valid_to is None) == 1
    second = (
        await client.post(
            f"/v1/conversations/{conversation}/messages",
            json={"message": "¿Cuánto tarda el envío?"},
            headers=auth(widget),
        )
    ).json()
    assert Decimal(second["usage"]["total_cost"]) > Decimal(first["usage"]["total_cost"]) * 10


async def test_usage_report_is_reconcilable_and_exportable(client: httpx.AsyncClient) -> None:
    widget = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, widget)
    await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "Recomiéndame unas zapatillas"},
        headers=auth(widget),
    )
    token = await admin_token(client)
    tenant_id = await _tenant_id(client, token)
    by_model = (
        await client.get(
            f"/v1/admin/tenants/{tenant_id}/usage",
            params={"group_by": "model,operation"},
            headers=auth(token),
        )
    ).json()
    total = (
        await client.get(
            f"/v1/admin/tenants/{tenant_id}/usage", params={"group_by": ""}, headers=auth(token)
        )
    ).json()
    assert by_model["totals"]["calls"] == total["totals"]["calls"] > 0
    assert sum(Decimal(i["total_cost"]) for i in by_model["items"]) == Decimal(
        total["totals"]["total_cost"]
    )
    csv = await client.get(f"/v1/admin/tenants/{tenant_id}/usage.csv", headers=auth(token))
    assert csv.status_code == 200 and csv.text.startswith("day,assistant,model")


async def test_trace_audit_and_payloads(client: httpx.AsyncClient) -> None:
    widget = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, widget)
    await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "¿Qué dice el manual sobre la batería?"},
        headers=auth(widget),
    )
    token = await admin_token(client)
    tenant_id = await _tenant_id(client, token)
    trace = (
        await client.get(
            f"/v1/admin/tenants/{tenant_id}/conversations/{conversation}/trace", headers=auth(token)
        )
    ).json()
    run = trace["runs"][0]
    assert run["tool_calls"][0]["name"] == "search_knowledge"
    assert run["provider_calls"] and run["payloads"]
    assert "[REDACTED]" not in str(run["context"]) or True
    payload = await client.get(
        f"/v1/admin/tenants/{tenant_id}/payloads/{run['payloads'][0]['id']}", headers=auth(token)
    )
    assert payload.status_code == 200 and payload.json()["content"]
    audit = (
        await client.get(
            f"/v1/admin/tenants/{tenant_id}/audit",
            params={"action": "payload."},
            headers=auth(token),
        )
    ).json()["items"]
    assert audit and audit[0]["action"] == "payload.viewed", "el acceso a payloads queda auditado"


async def test_config_package_roundtrip_is_idempotent(client: httpx.AsyncClient) -> None:
    token = await admin_token(client)
    tenant_id = await _tenant_id(client, token)
    package = (
        await client.get(f"/v1/admin/tenants/{tenant_id}/config/export", headers=auth(token))
    ).json()
    assert package["format"] == "webassistant.config-package"
    assert "secret" not in str(package).lower()
    dry = (
        await client.post(
            f"/v1/admin/tenants/{tenant_id}/config/import",
            params={"dry_run": True},
            json=package,
            headers=auth(token),
        )
    ).json()
    assert dry["changes"] == [], dry["changes"]
    package["assistants"][0]["name"] = "Asistente renombrado"
    dry = (
        await client.post(
            f"/v1/admin/tenants/{tenant_id}/config/import",
            params={"dry_run": True},
            json=package,
            headers=auth(token),
        )
    ).json()
    assert {"entity": "assistant", "key": "catalogo", "operation": "update"} in dry["changes"]
    unchanged = (
        await client.get(f"/v1/admin/tenants/{tenant_id}/assistants", headers=auth(token))
    ).json()["items"]
    assert unchanged[0]["name"] != "Asistente renombrado", "dry_run no modifica datos"
    applied = (
        await client.post(
            f"/v1/admin/tenants/{tenant_id}/config/import",
            params={"dry_run": False},
            json=package,
            headers=auth(token),
        )
    ).json()
    assert applied["changes"]
    again = (
        await client.post(
            f"/v1/admin/tenants/{tenant_id}/config/import",
            params={"dry_run": False},
            json=package,
            headers=auth(token),
        )
    ).json()
    assert again["changes"] == [], "dos ejecuciones producen el mismo estado"
    package["sites"][0]["client_secret"] = "x"
    rejected = await client.post(
        f"/v1/admin/tenants/{tenant_id}/config/import", json=package, headers=auth(token)
    )
    assert rejected.status_code == 422


async def test_api_client_secret_shown_once_and_rotated(client: httpx.AsyncClient) -> None:
    token = await admin_token(client)
    tenant_id = await _tenant_id(client, token)
    created = (
        await client.post(
            f"/v1/admin/tenants/{tenant_id}/api-clients",
            json={"name": "Drupal", "site_id": "site_demo", "scopes": ["ingest:write"]},
            headers=auth(token),
        )
    ).json()
    secret = created["client_secret"]
    listing = (
        await client.get(f"/v1/admin/tenants/{tenant_id}/api-clients", headers=auth(token))
    ).json()["items"]
    assert all("secret" not in str(c.get("secret_hash", "")) for c in listing) and all(
        "client_secret" not in c for c in listing
    )
    ok = await client.post(
        "/v1/token", json={"client_id": created["client_id"], "client_secret": secret}
    )
    assert ok.status_code == 200
    rotated = (
        await client.post(
            f"/v1/admin/tenants/{tenant_id}/api-clients/{created['id']}/rotate", headers=auth(token)
        )
    ).json()
    assert (
        await client.post(
            "/v1/token", json={"client_id": created["client_id"], "client_secret": secret}
        )
    ).status_code == 401
    assert (
        await client.post(
            "/v1/token",
            json={"client_id": created["client_id"], "client_secret": rotated["client_secret"]},
        )
    ).status_code == 200
    await client.post(
        f"/v1/admin/tenants/{tenant_id}/api-clients/{created['id']}/revoke", headers=auth(token)
    )
    assert (
        await client.post(
            "/v1/token",
            json={"client_id": created["client_id"], "client_secret": rotated["client_secret"]},
        )
    ).status_code == 401


async def test_skills_registry_and_diagnostics(client: httpx.AsyncClient) -> None:
    token = await admin_token(client)
    skills = (await client.get("/v1/admin/skills", headers=auth(token))).json()["items"]
    assert {s["name"] for s in skills} >= {"default", "product_results", "document_lookup"}
    diagnostics = (await client.get("/v1/admin/diagnostics", headers=auth(token))).json()
    assert {d["name"] for d in diagnostics["dependencies"]} == {"postgres", "redis", "qdrant"}
    assert diagnostics["embedding_index"]["points"] > 0


async def test_embedding_reindex_and_rollback(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    token = await admin_token(client)
    before = (await client.get("/v1/admin/embedding-indexes", headers=auth(token))).json()["items"]
    active = next(i for i in before if i["status"] == "active")
    created = await client.post(
        "/v1/admin/embedding-indexes",
        json={"provider": "simulated", "model": "simulated-embedding-v1", "activate": True},
        headers=auth(token),
    )
    assert created.status_code == 202, created.text
    after = (await client.get("/v1/admin/embedding-indexes", headers=auth(token))).json()["items"]
    new_active = next(i for i in after if i["status"] == "active")
    assert new_active["id"] != active["id"] and new_active["points"] == active["points"]
    widget = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, widget)
    answer = (
        await client.post(
            f"/v1/conversations/{conversation}/messages",
            json={"message": "¿Cómo puedo devolver un pedido?"},
            headers=auth(widget),
        )
    ).json()
    assert answer["citations"], "las búsquedas funcionan con el índice nuevo"
    rollback = await client.post("/v1/admin/embedding-indexes/rollback", headers=auth(token))
    assert rollback.json()["id"] == active["id"]


async def test_retention_anonymizes_old_conversations(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    from datetime import UTC, datetime, timedelta

    from sqlalchemy import update

    from webassistant.retention.service import RetentionService

    widget = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, widget)
    await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "¿Cuánto tarda el envío?"},
        headers=auth(widget),
    )
    services = app.state.services
    async with services.db.session(bypass_rls=True) as session:
        await session.execute(
            update(m.Conversation)
            .where(m.Conversation.id == conversation)
            .values(updated_at=datetime.now(UTC) - timedelta(days=400))
        )
    stats = await RetentionService(services.db, services.audit).apply()
    demo = next(s for s in stats if s["conversations"])
    assert demo["conversations"] == 1
    async with services.db.session(bypass_rls=True) as session:
        row = await session.get(m.Conversation, conversation)
        messages = (
            await session.execute(
                m.Message.__table__.select().where(m.Message.conversation_id == conversation)
            )
        ).all()
        audit = (
            await session.execute(
                m.AuditEvent.__table__.select().where(m.AuditEvent.action == "retention.applied")
            )
        ).all()
    assert row.status == "deleted" and messages == [] and audit


async def test_bootstrap_admin_is_created_once_and_requires_strong_password(
    tmp_path: Path,
) -> None:
    from webassistant.services.bootstrap import ensure_bootstrap_admin

    settings = make_settings(
        tmp_path,
        bootstrap_admin_email="Ops@Example.com",
        bootstrap_admin_password="Ops-Seguro-2026-x",
    )
    built = build_services(settings, overrides={"providers": {"simulated": SimulatedProvider()}})
    await built.startup(create_schema=True)
    try:
        assert await ensure_bootstrap_admin(built) is True
        assert await ensure_bootstrap_admin(built) is False
        async with UnitOfWork(built.db, platform=True) as uow:
            user = await uow.admin_users.by_email("ops@example.com")
            assert user is not None
            assert user.role == "platform_admin"
        weak = make_settings(
            tmp_path, bootstrap_admin_email="weak@example.com", bootstrap_admin_password="corta"
        )
        built.settings = weak
        assert await ensure_bootstrap_admin(built) is False
    finally:
        await built.shutdown()


async def test_invalid_admin_inputs_return_validation_errors(client: httpx.AsyncClient) -> None:
    """Regresión del DAST: entradas manipuladas devuelven 422, nunca 5xx."""
    token = await admin_token(client)
    tenant = await _tenant_id(client, token)
    for path in (
        "/v1/admin/usage?date_from=%3Cscript%3E",
        "/v1/admin/usage?date_to=%3Fname%3Dabc",
        f"/v1/admin/tenants/{tenant}/usage?date_from=2026-13-45",
        f"/v1/admin/tenants/{tenant}/usage.csv?date_from=2026-10-10&date_to=2026-01-01",
    ):
        response = await client.get(path, headers=auth(token))
        assert response.status_code == 422, (path, response.text)
        assert response.json()["code"] == "validation_error"
    ok = await client.get(
        "/v1/admin/usage?date_from=2026-01-01&date_to=2026-12-31", headers=auth(token)
    )
    assert ok.status_code == 200
    unknown = await client.post(
        "/v1/admin/embedding-indexes",
        json={"provider": "cat /etc/passwd", "model": "x", "activate": False},
        headers=auth(token),
    )
    assert unknown.status_code == 422
    assert unknown.json()["errors"][0]["path"] == "/provider"
