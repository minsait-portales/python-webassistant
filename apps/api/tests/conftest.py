from __future__ import annotations

import os
from collections.abc import AsyncIterator
from pathlib import Path
from typing import Any

import httpx
import pytest

os.environ.setdefault("WEBASSISTANT_LOG_JSON", "false")
os.environ.setdefault("WEBASSISTANT_LOG_LEVEL", "WARNING")

from webassistant.api.app import create_app
from webassistant.config import Settings
from webassistant.gateway.simulated import SimulatedProvider
from webassistant.queue import InlineQueue
from webassistant.seeds.demo import DEV_CLIENT_ID, DEV_CLIENT_SECRET, ensure_demo
from webassistant.services.container import Services, build_services

ORIGIN = "http://localhost:8000"


def make_settings(tmp_path: Path, **overrides: Any) -> Settings:
    url = (
        os.environ.get("WEBASSISTANT_TEST_DATABASE_URL")
        or f"sqlite+aiosqlite:///{tmp_path}/test.db"
    )
    values: dict[str, Any] = {
        "database_url": url,
        "environment": "test",
        "jwt_secret": "test-secret-0123456789-0123456789-abcdef",
        "qdrant_location": ":memory:",
        "queue_mode": "inline",
        "rate_limit_ip_per_minute": 10_000,
        "rate_limit_site_per_minute": 10_000,
        "rate_limit_subject_per_minute": 10_000,
        "public_base_url": ORIGIN,
    }
    values.update(overrides)
    return Settings(**values)


@pytest.fixture
def settings(tmp_path: Path) -> Settings:
    return make_settings(tmp_path)


async def _reset_postgres(services: Services) -> None:
    if services.db.is_postgres:
        await services.db.drop_all()
        await services.db.create_all()


@pytest.fixture
async def services(settings: Settings) -> AsyncIterator[Services]:
    queue = InlineQueue(run_in_background=False)
    built = build_services(
        settings, overrides={"queue": queue, "providers": {"simulated": SimulatedProvider()}}
    )
    from webassistant.jobs import register_inline_jobs

    register_inline_jobs(queue, built)
    await _reset_postgres(built)
    yield built


@pytest.fixture
async def app(services: Services) -> AsyncIterator[Any]:
    application = create_app(services.settings, services, create_schema=True)
    async with application.router.lifespan_context(application):
        await ensure_demo(services)
        yield application


@pytest.fixture
async def client(app: Any) -> AsyncIterator[httpx.AsyncClient]:
    transport = httpx.ASGITransport(app=app)
    async with httpx.AsyncClient(
        transport=transport, base_url=ORIGIN, headers={"Origin": ORIGIN}
    ) as http:
        yield http


async def widget_token(client: httpx.AsyncClient, site_id: str = "site_demo") -> dict[str, Any]:
    response = await client.post("/v1/sessions", json={"site_id": site_id})
    assert response.status_code == 200, response.text
    return response.json()


def auth(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


async def new_conversation(client: httpx.AsyncClient, token: str) -> str:
    response = await client.post("/v1/conversations", json={}, headers=auth(token))
    assert response.status_code == 201, response.text
    return str(response.json()["conversation_id"])


async def server_token(client: httpx.AsyncClient) -> str:
    response = await client.post(
        "/v1/token",
        json={
            "grant_type": "client_credentials",
            "client_id": DEV_CLIENT_ID,
            "client_secret": DEV_CLIENT_SECRET,
        },
    )
    assert response.status_code == 200, response.text
    return str(response.json()["access_token"])


async def admin_token(
    client: httpx.AsyncClient, email: str = "admin@demo.local", password: str = "Demo-Admin-2026"
) -> str:
    response = await client.post(
        "/v1/admin/auth/login", json={"email": email, "password": password}
    )
    assert response.status_code == 200, response.text
    return str(response.json()["access_token"])


async def user_token(
    client: httpx.AsyncClient,
    user_id: str = "user-42",
    roles: list[str] | None = None,
    scopes: list[str] | None = None,
) -> str:
    response = await client.post(
        "/v1/token",
        json={
            "grant_type": "widget_token",
            "client_id": DEV_CLIENT_ID,
            "client_secret": DEV_CLIENT_SECRET,
            "site_id": "site_demo",
            "origin": ORIGIN,
            "user": {"id": user_id, "roles": roles or ["customer"]},
            "scopes": scopes or [],
        },
    )
    assert response.status_code == 200, response.text
    return str(response.json()["access_token"])
