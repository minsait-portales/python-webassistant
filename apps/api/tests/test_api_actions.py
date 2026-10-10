"""Acciones: preparación, confirmación, idempotencia y host actions (CG-05.5, CG-06.5, CG-09.5)."""

from __future__ import annotations

import httpx

from tests.conftest import auth, new_conversation, widget_token


async def _cart_proposal_context(client: httpx.AsyncClient) -> tuple[str, str, str, dict]:
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    response = await client.post(
        f"/v1/conversations/{conversation}/messages",
        json={"message": "Recomiéndame unas zapatillas para empezar a correr"},
        headers=auth(token),
    )
    body = response.json()
    item = body["presentation"]["data"]["items"][0]
    return token, conversation, body["run_id"], item


async def test_confirmed_host_action_is_idempotent(client: httpx.AsyncClient) -> None:
    token, conversation, run_id, item = await _cart_proposal_context(client)
    prepare = await client.post(
        "/v1/actions/prepare",
        json={
            "conversation_id": conversation,
            "run_id": run_id,
            "skill": "product_recommendations",
            "action": "add_to_cart",
            "params": {
                "product_id": item["product_id"],
                "variation_id": item["variation_id"],
                "quantity": 2,
            },
        },
        headers=auth(token),
    )
    assert prepare.status_code == 200, prepare.text
    proposal = prepare.json()
    assert proposal["requires_confirmation"] is True and proposal["confirmation_token"]
    execution_id = proposal["execution_id"]

    missing_key = await client.post(
        f"/v1/actions/{execution_id}/execute",
        json={"confirmation_token": proposal["confirmation_token"]},
        headers=auth(token),
    )
    assert (
        missing_key.status_code == 428 and missing_key.json()["code"] == "idempotency_key_required"
    )
    no_confirmation = await client.post(
        f"/v1/actions/{execution_id}/execute",
        json={},
        headers={**auth(token), "Idempotency-Key": "key-0000001"},
    )
    assert (
        no_confirmation.status_code == 428
        and no_confirmation.json()["code"] == "confirmation_required"
    )

    headers = {**auth(token), "Idempotency-Key": "click-123456"}
    first = await client.post(
        f"/v1/actions/{execution_id}/execute",
        json={"confirmation_token": proposal["confirmation_token"]},
        headers=headers,
    )
    assert first.status_code == 200, first.text
    host_action = first.json()["host_action"]
    assert host_action["name"] == "cart.add"
    assert host_action["params"]["quantity"] == 2
    assert host_action["correlation_id"] == execution_id
    second = await client.post(
        f"/v1/actions/{execution_id}/execute",
        json={"confirmation_token": proposal["confirmation_token"]},
        headers=headers,
    )
    assert second.status_code == 200
    assert second.json()["replayed"] is True, "un doble clic no duplica la operación"

    report = await client.post(
        f"/v1/actions/{execution_id}/result",
        json={"status": "succeeded", "result": {"cart_count": 2}},
        headers=auth(token),
    )
    assert report.json()["status"] == "succeeded"
    again = await client.post(
        f"/v1/actions/{execution_id}/result", json={"status": "failed"}, headers=auth(token)
    )
    assert again.json()["status"] == "succeeded"


async def test_confirmation_token_cannot_be_reused_for_other_params(
    client: httpx.AsyncClient,
) -> None:
    token, conversation, run_id, item = await _cart_proposal_context(client)
    first = (
        await client.post(
            "/v1/actions/prepare",
            json={
                "conversation_id": conversation,
                "run_id": run_id,
                "skill": "product_recommendations",
                "action": "add_to_cart",
                "params": {"product_id": item["product_id"], "quantity": 1},
            },
            headers=auth(token),
        )
    ).json()
    second = (
        await client.post(
            "/v1/actions/prepare",
            json={
                "conversation_id": conversation,
                "run_id": run_id,
                "skill": "product_recommendations",
                "action": "add_to_cart",
                "params": {"product_id": item["product_id"], "quantity": 50},
            },
            headers=auth(token),
        )
    ).json()
    hijack = await client.post(
        f"/v1/actions/{second['execution_id']}/execute",
        json={"confirmation_token": first["confirmation_token"]},
        headers={**auth(token), "Idempotency-Key": "hijack-0001"},
    )
    assert hijack.status_code == 403 and hijack.json()["code"] == "confirmation_invalid"


async def test_action_validation_and_permissions(client: httpx.AsyncClient) -> None:
    token, conversation, run_id, item = await _cart_proposal_context(client)
    invalid = await client.post(
        "/v1/actions/prepare",
        json={
            "conversation_id": conversation,
            "skill": "product_recommendations",
            "action": "add_to_cart",
            "params": {"quantity": 1000},
        },
        headers=auth(token),
    )
    assert invalid.status_code == 422
    undeclared = await client.post(
        "/v1/actions/prepare",
        json={
            "conversation_id": conversation,
            "skill": "product_recommendations",
            "action": "delete_everything",
            "params": {},
        },
        headers=auth(token),
    )
    assert undeclared.status_code == 404
    not_allowed = await client.post(
        "/v1/actions/prepare",
        json={
            "conversation_id": conversation,
            "skill": "sales_analytics",
            "action": "show_sales_report",
            "params": {"range": "month", "focus": "revenue"},
        },
        headers=auth(token),
    )
    assert not_allowed.status_code == 404, "una skill no activa para el usuario no expone acciones"
    other = (await widget_token(client))["access_token"]
    foreign = await client.post(
        "/v1/actions/prepare",
        json={
            "conversation_id": conversation,
            "skill": "product_recommendations",
            "action": "add_to_cart",
            "params": {"product_id": item["product_id"]},
        },
        headers=auth(other),
    )
    assert foreign.status_code == 404


async def test_auto_action_without_effects_needs_no_key(client: httpx.AsyncClient) -> None:
    token = (await widget_token(client))["access_token"]
    conversation = await new_conversation(client, token)
    body = (
        await client.post(
            f"/v1/conversations/{conversation}/messages",
            json={"message": "Enséñame el carrito"},
            headers=auth(token),
        )
    ).json()
    assert body["presentation"]["type"] == "cart"
    assert body["presentation"]["data"]["source_action"] == "get_cart"
    auto = next(a for a in body["actions"] if a["name"] == "get_cart")
    assert auto["auto"] is True and auto["confirmation"] == "none"
    proposal = (
        await client.post(
            "/v1/actions/prepare",
            json={
                "conversation_id": conversation,
                "run_id": body["run_id"],
                "skill": "shopping_cart_view",
                "action": "get_cart",
                "params": {},
            },
            headers=auth(token),
        )
    ).json()
    executed = await client.post(
        f"/v1/actions/{proposal['execution_id']}/execute", json={}, headers=auth(token)
    )
    assert executed.status_code == 200
    assert executed.json()["host_action"]["name"] == "cart.get"
