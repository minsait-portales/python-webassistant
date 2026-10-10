"""Contratos versionados: OpenAPI publicado sin deriva y esquemas válidos (CG-09.1, CG-09.7)."""

from __future__ import annotations

import json
from pathlib import Path

from jsonschema import Draft202012Validator

from webassistant.api.app import create_app
from webassistant.config import Settings
from webassistant.contracts import schemas_dir
from webassistant.services.container import build_services

REPO = Path(__file__).resolve().parents[3]


def test_all_contract_schemas_are_valid() -> None:
    files = list(schemas_dir().rglob("*.schema.json"))
    assert len(files) >= 20
    for path in files:
        Draft202012Validator.check_schema(json.loads(path.read_text(encoding="utf-8")))


def test_published_openapi_matches_code(tmp_path: Path) -> None:
    settings = Settings(
        database_url=f"sqlite+aiosqlite:///{tmp_path}/o.db", environment="test", jwt_secret="o" * 40
    )
    generated = create_app(settings, build_services(settings)).openapi()
    published = json.loads(
        (REPO / "packages" / "contracts" / "openapi" / "openapi.json").read_text(encoding="utf-8")
    )
    assert generated == published, "OpenAPI desactualizado: ejecuta `webassistant export-openapi`"
    assert generated["openapi"].startswith("3.1")
    assert all(path.startswith(("/v1/", "/health/")) for path in generated["paths"])
