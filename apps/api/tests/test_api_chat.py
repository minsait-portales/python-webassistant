"""API pública: bootstrap, sesiones, conversación, streaming, cancelación y privacidad (CG-03, CG-08, CG-09)."""

from __future__ import annotations

import asyncio
import json

import httpx

from tests.conftest import ORIGIN, auth, new_conversation, user_token, widget_token
from webassistant.contracts import validate


async def test_health(client: httpx.AsyncClient) -> None:
    assert (await client.get("/health/live")).json() == {"status": "ok"}
    ready = await client.get("/health/ready")
    assert ready.status_code == 200, ready.text
    metrics = await client.get("/metrics")
    assert "webassistant_http_requests_total" in metrics.text


async def test_bootstrap_requires_registered_origin(client: httpx.AsyncClient) -> None:
    ok = await client.get("/v1/bootstrap", params={"site_id": "site_demo"})
    assert ok.status_code == 200
    body = ok.json()
    assert body["assistant"]["id"] == "catalogo"
    assert "product_grid@1" in body["capabilities"]["renderers"]
    assert "cart.add" in body["capabilities"]["host_actions"]
    assert "openai_api_key" not in ok.text.lower()
    evil = await client.get(
        "/v1/bootstrap", params={"site_id": "site_demo"}, headers={"Origin": "https://evil.example"}
    )
    assert evil.status_code == 403
    assert evil.json()["code"] == "origin_not_allowed"
    assert evil.headers["content-type"].startswith("application/problem+json")
    unknown = await client.get("/v1/bootstrap", params={"site_id": "nope"})
    assert unknown.status_code == 403


async def test_cors_preflight_only_for_registered_origins(client: httpx.AsyncClient) -> None:
    allowed = await client.options(
        "/v1/sessions",
        headers={
            "Origin": ORIGIN,
            "Access-Control-Request-Method": "POST",
            "Access-Control-Request-Headers": "content-type",
        },
    )
    assert allowed.status_code == 204
    assert allowed.headers["access-control-allow-origin"] == ORIGIN
    denied = await client.options(
        "/v1/sessions",
        headers={"Origin": "https://evil.example", "Access-Control-Request-Method": "POST"},
    )
    assert denied.status_code == 403
    assert "access-control-allow-origin" not in denied.headers


async def test_security_headers_and_problem_json(client: httpx.AsyncClient) -> None:
    response = await client.get("/v1/conversations")
    assert response.status_code == 401
    assert response.headers["x-content-type-options"] == "nosniff"
    assert response.headers["cache-control"] == "no-store"
    assert response.json()["code"] == "unauthorized"
    assert response.headers.get("x-request-id")


async def test_anonymous_session_is_stable_and_isolated(client: httpx.AsyncClient) -> None:
    first = await widget_token(client)
    again = await client.post(
        "/v1/sessions",
        json={"site_id": "site_demo", "anonymous_session": first["anonymous_session"]},
    )
    assert again.status_code == 200
    token_a, token_a2 = first["access_token"], again.json()["access_token"]
    conversation = await new_conversation(client, token_a)
    same_subject = await client.get(f"/v1/conversations/{conversation}", headers=auth(token_a2))
    assert same_subject.status_code == 200
    other = await widget_token(client)
    foreign = await client.get(
        f"/v1/conversations/{conversation}", headers=auth(other["access_token"])
    )
    assert foreign.status_code == 404, "una conversación no puede recuperarse desde otro contexto"


async def test_full_conversation_flow_and_contract(client: httpx.AsyncClient) -> None:
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    response = await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "Organiza una barbacoa para 10 personas"},
        headers=auth(token),
    )
    assert response.status_code == 200, response.text
    body = response.json()
    assert not validate("chat-response.v1.schema.json", body), validate(
        "chat-response.v1.schema.json", body
    )
    assert body["skill"] == "event_product_selection"
    assert body["presentation"]["type"] == "event_selection"
    assert not validate(
        "presentations/event_selection.v1.schema.json", body["presentation"]["data"]
    )
    assert int(body["usage"]["provider_calls"]) >= 2
    assert body["citations"]

    follow = await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "Añade bebidas"},
        headers=auth(token),
    )
    assert follow.status_code == 200
    assert follow.json()["skill"] == "event_product_selection", (
        "el seguimiento conserva la skill de la colección activa"
    )

    off_topic = await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "¿Quién ganó el mundial de 1982?"},
        headers=auth(token),
    )
    off = off_topic.json()
    assert off["keep_presentation"] is True and off["presentation"] is None

    history = (await client.get(f"/v1/conversations/{conversation}", headers=auth(token))).json()
    roles = [m["role"] for m in history["messages"]]
    assert roles == ["user", "assistant"] * 3
    serialized = json.dumps(history, ensure_ascii=False)
    assert "INSTRUCCIONES DE SKILLS" not in serialized
    assert "COLECCIÓN ACTIVA" not in serialized
    assert history["messages"][1]["presentation"]["type"] == "event_selection"


async def test_message_limits(client: httpx.AsyncClient) -> None:
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    too_long = await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "x" * 900},
        headers=auth(token),
    )
    assert too_long.status_code == 422
    assert too_long.json()["code"] == "message_too_long"
    big = await client.post(
        f"/v1/conversations/{conversation}/messages",
        content=b"{" + b" " * 1_100_000 + b"}",
        headers={**auth(token), "content-type": "application/json"},
    )
    assert big.status_code == 413


async def _read_sse(response: httpx.Response) -> list[dict]:
    events = []
    async for line in response.aiter_lines():
        if line.startswith("data: "):
            event = json.loads(line[6:])
            events.append(event)
            if event["type"] in {"run.completed", "run.failed", "run.cancelled"}:
                break
    return events


async def test_streaming_with_reconnection(client: httpx.AsyncClient) -> None:
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    headers = {**auth(token), "Accept": "text/event-stream"}
    async with client.stream(
        "POST",
        f"/v1/conversations/{conversation}/messages",
        json={"message": "¿Cómo puedo devolver un pedido?"},
        headers=headers,
    ) as response:
        assert response.status_code == 200
        assert response.headers["content-type"].startswith("text/event-stream")
        events = await _read_sse(response)
    types = [e["type"] for e in events]
    assert types[0] == "run.started" and types[-1] == "run.completed"
    assert "reply.delta" in types
    deltas = "".join(e["delta"] for e in events if e["type"] == "reply.delta")
    final = events[-1]["response"]
    assert final["skill"] == "content_answer"
    assert deltas.strip()
    run_id = final["run_id"]
    # Reconexión: con Last-Event-ID se reciben solo los eventos posteriores.
    async with client.stream(
        "GET",
        f"/v1/conversations/{conversation}/runs/{run_id}/events",
        headers={**auth(token), "Last-Event-ID": str(events[-2]["seq"])},
    ) as replay:
        replayed = await _read_sse(replay)
    assert [e["type"] for e in replayed] == ["run.completed"]


async def test_cancel_running_run(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    provider = app.state.services.gateway.providers["simulated"]
    provider.latency_ms = 400
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    task = asyncio.create_task(
        client.post(
            f"/v1/conversations/{conversation}/messages",
            json={"message": "Recomiéndame unas zapatillas"},
            headers=auth(token),
        )
    )
    await asyncio.sleep(0.15)
    runs = list(app.state.services.chat.cancel_tokens)
    assert runs, "el run debe estar en curso"
    cancel = await client.post(
        f"/v1/conversations/{conversation}/runs/{runs[0]}/cancel", headers=auth(token)
    )
    assert cancel.json()["status"] == "cancelling"
    response = await task
    provider.latency_ms = 0
    assert response.status_code == 409
    assert response.json()["code"] == "run_cancelled"
    status = await client.get(
        f"/v1/conversations/{conversation}/runs/{runs[0]}", headers=auth(token)
    )
    assert status.json()["status"] == "cancelled"


async def test_reset_and_privacy_delete(app, client: httpx.AsyncClient) -> None:  # type: ignore[no-untyped-def]
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "¿Cuánto tarda el envío?"},
        headers=auth(token),
    )
    reset = await client.post(
        f"/v1/conversations/{conversation}/reset", json={}, headers=auth(token)
    )
    assert reset.status_code == 201
    new_id = reset.json()["conversation_id"]
    assert new_id != conversation
    archived = await client.post(
        f"/v1/conversations/{conversation}/messages", json={"message": "hola"}, headers=auth(token)
    )
    assert archived.status_code == 409
    listing = (await client.get("/v1/conversations", headers=auth(token))).json()["items"]
    assert {c["status"] for c in listing} == {"active", "archived"}
    deleted = await client.delete(f"/v1/conversations/{conversation}", headers=auth(token))
    assert deleted.status_code == 204
    assert (
        await client.get(f"/v1/conversations/{conversation}", headers=auth(token))
    ).status_code == 404
    from sqlalchemy import select

    from webassistant.db import models as m

    async with app.state.services.db.session(bypass_rls=True) as session:
        messages = (
            (
                await session.execute(
                    select(m.Message).where(m.Message.conversation_id == conversation)
                )
            )
            .scalars()
            .all()
        )
        usage = (
            (await session.execute(select(m.UsageEvent).where(m.UsageEvent.run_id.is_not(None))))
            .scalars()
            .all()
        )
    assert messages == []
    assert all(u.conversation_id != conversation for u in usage), "el uso se conserva anonimizado"


async def test_authenticated_user_token_and_anonymous_linking(client: httpx.AsyncClient) -> None:
    anonymous = await widget_token(client)
    conversation = await new_conversation(client, anonymous["access_token"])
    host_token = await user_token(client, "cliente-7")
    linked = await client.post(
        "/v1/sessions",
        json={
            "site_id": "site_demo",
            "host_token": host_token,
            "anonymous_session": anonymous["anonymous_session"],
        },
    )
    assert linked.status_code == 200
    assert linked.json()["linked_conversations"] == 1
    user_access = linked.json()["access_token"]
    assert (
        await client.get(f"/v1/conversations/{conversation}", headers=auth(user_access))
    ).status_code == 200
    assert (
        await client.get(
            f"/v1/conversations/{conversation}", headers=auth(anonymous["access_token"])
        )
    ).status_code == 404


async def test_token_bound_to_origin(client: httpx.AsyncClient) -> None:
    token = (await widget_token(client))["access_token"]
    other_origin = await client.get(
        "/v1/conversations", headers={**auth(token), "Origin": "http://localhost:8080"}
    )
    assert other_origin.status_code == 403
    assert other_origin.json()["code"] == "origin_not_allowed"


async def test_skill_permissions_follow_user_scopes(client: httpx.AsyncClient) -> None:
    anonymous = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, anonymous)
    response = await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "Quiero editar la página de contacto"},
        headers=auth(anonymous),
    )
    assert response.json()["skill"] != "content_edit", "content_edit requiere content:write"
    editor = await user_token(
        client, "editor-1", roles=["editor"], scopes=["content:write", "catalog:read"]
    )
    conv2 = await new_conversation(client, editor)
    allowed = await client.post(
        f"/v1/conversations/{conv2}/messages",
        json={"message": "Quiero editar la página de contacto"},
        headers=auth(editor),
    )
    assert allowed.json()["skill"] == "content_edit"
    assert allowed.json()["presentation"]["type"] == "host_action"
