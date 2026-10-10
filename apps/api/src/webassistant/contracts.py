"""Acceso a los contratos JSON Schema de ``packages/contracts`` (API first).

Los esquemas se buscan, por orden, en ``WEBASSISTANT_CONTRACTS_DIR``, en los
datos empaquetados del wheel (``webassistant/_contracts``) y en el monorepo.
"""

from __future__ import annotations

import json
import os
from functools import lru_cache
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator
from jsonschema.exceptions import ValidationError
from referencing import Registry, Resource

_HERE = Path(__file__).resolve().parent


def _candidate_dirs() -> list[Path]:
    candidates: list[Path] = []
    env_dir = os.environ.get("WEBASSISTANT_CONTRACTS_DIR")
    if env_dir:
        candidates.append(Path(env_dir))
    candidates.append(_HERE / "_contracts" / "schemas")
    for parent in _HERE.parents:
        candidates.append(parent / "packages" / "contracts" / "schemas")
    return candidates


@lru_cache(maxsize=1)
def schemas_dir() -> Path:
    for candidate in _candidate_dirs():
        if (candidate / "chat-response.v1.schema.json").is_file():
            return candidate
    raise FileNotFoundError(
        "No se encuentran los contratos JSON Schema (packages/contracts/schemas)."
    )


@lru_cache(maxsize=1)
def _registry() -> Registry:
    resources: list[tuple[str, Resource[Any]]] = []
    base = schemas_dir()
    for path in base.rglob("*.schema.json"):
        contents = json.loads(path.read_text(encoding="utf-8"))
        resource = Resource.from_contents(contents)
        resources.append((contents["$id"], resource))
    return Registry().with_resources(resources)


@lru_cache(maxsize=128)
def load_schema(relative_name: str) -> dict[str, Any]:
    path = schemas_dir() / relative_name
    return json.loads(path.read_text(encoding="utf-8"))


@lru_cache(maxsize=128)
def validator_for(relative_name: str) -> Draft202012Validator:
    schema = load_schema(relative_name)
    return Draft202012Validator(schema, registry=_registry())


def validator_for_schema(schema: dict[str, Any]) -> Draft202012Validator:
    return Draft202012Validator(schema, registry=_registry())


def presentation_schema_name(renderer: str) -> str:
    """``product_grid@1`` -> ``presentations/product_grid.v1.schema.json``."""
    name, _, version = renderer.partition("@")
    return f"presentations/{name}.v{version or '1'}.schema.json"


def has_presentation_schema(renderer: str) -> bool:
    try:
        return (schemas_dir() / presentation_schema_name(renderer)).is_file()
    except FileNotFoundError:
        return False


def format_errors(errors: list[ValidationError], limit: int = 10) -> list[dict[str, Any]]:
    formatted: list[dict[str, Any]] = []
    for error in sorted(errors, key=lambda e: list(e.absolute_path))[:limit]:
        formatted.append(
            {
                "path": "/" + "/".join(str(p) for p in error.absolute_path),
                "message": error.message[:300],
            }
        )
    return formatted


def validate(relative_name: str, instance: Any) -> list[dict[str, Any]]:
    errors = list(validator_for(relative_name).iter_errors(instance))
    return format_errors(errors)
