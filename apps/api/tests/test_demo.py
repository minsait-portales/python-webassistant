"""Demo autónoma (CG-16.3): web estática, login simulado del portal y activos sincronizados."""

from __future__ import annotations

import importlib.util
from pathlib import Path

import httpx
import pytest

from tests.conftest import ORIGIN, make_settings
from webassistant.config import Settings
from webassistant.seeds.demo import load_documents

REPO = Path(__file__).resolve().parents[3]


@pytest.fixture
def settings(tmp_path: Path) -> Settings:
    return make_settings(tmp_path, demo_enabled=True)


async def test_demo_site_is_served_with_strict_csp(client: httpx.AsyncClient) -> None:
    page = await client.get("/demo/")
    assert page.status_code == 200
    assert "script-src 'self'" in page.headers["content-security-policy"]
    assert 'src="/widget/v1/loader.js"' in page.text
    image = await client.get("/demo/img/carnes.svg")
    assert image.status_code == 200
    assert image.headers["content-type"].startswith("image/svg+xml")
    pdf = await client.get("/demo/documentos/manual-nova-12.pdf")
    assert pdf.status_code == 200
    assert pdf.content.startswith(b"%PDF-1.4")
    head = await client.head("/demo/catalog.json")
    assert head.status_code == 200


@pytest.mark.parametrize(
    "path",
    [
        "/demo/../pyproject.toml",
        "/demo/%2e%2e/pyproject.toml",
        "/demo/img/../../README.md",
        "/demo/a/b/c/d/e",
    ],
)
async def test_demo_static_paths_cannot_escape(client: httpx.AsyncClient, path: str) -> None:
    response = await client.get(path)
    assert response.status_code == 404


async def test_demo_portal_login_issues_user_token(client: httpx.AsyncClient) -> None:
    login = await client.post("/demo/api/login", json={"profile": "editor"})
    assert login.status_code == 200, login.text
    body = login.json()
    assert "content:write" in body["scopes"]
    session = await client.post(
        "/v1/sessions", json={"site_id": "site_demo", "host_token": body["token"]}
    )
    assert session.status_code == 200, session.text
    assert session.json()["subject_type"] == "user"
    rejected = await client.post("/demo/api/login", json={"profile": "admin"})
    assert rejected.status_code == 422


async def test_demo_portal_login_checks_origin(client: httpx.AsyncClient) -> None:
    response = await client.post(
        "/demo/api/login", json={"profile": "cliente"}, headers={"Origin": "https://evil.example"}
    )
    assert response.status_code == 403


def test_demo_documents_follow_public_base_url() -> None:
    documents = load_documents("https://assistant.example.com/")
    product = next(d for d in documents if d["source_id"] == "p-1001")
    assert product["canonical_url"] == "https://assistant.example.com/demo/producto.html?id=p-1001"
    assert product["metadata"]["image_url"] == "https://assistant.example.com/demo/img/carnes.svg"
    assert all(ORIGIN not in str(d) for d in documents)
    assert load_documents("no-es-una-url")[0]["canonical_url"].startswith(ORIGIN)


def test_demo_static_assets_are_in_sync_with_seeds() -> None:
    spec = importlib.util.spec_from_file_location(
        "generate_demo_site", REPO / "scripts" / "generate_demo_site.py"
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    for path, content in module.build().items():
        assert path.read_bytes() == content, (
            f"{path} desactualizado: ejecuta python scripts/generate_demo_site.py"
        )
