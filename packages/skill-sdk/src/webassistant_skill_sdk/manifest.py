"""Carga y validación de paquetes de skill (manifiesto + esquemas + ejemplo)."""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path
from typing import Any

import yaml
from jsonschema import Draft202012Validator

MANIFEST_FILENAMES = ("skill.yaml", "skill.yml")
_SCHEMA_PATH = Path(__file__).parent / "schemas" / "skill-manifest.v1.schema.json"
_SEMVER = re.compile(r"^(\d+)\.(\d+)\.(\d+)")


class ManifestError(ValueError):
    """El paquete de skill no cumple el contrato."""

    def __init__(self, message: str, diagnostics: list[str] | None = None) -> None:
        super().__init__(message)
        self.diagnostics = diagnostics or [message]


@dataclass
class SkillPackage:
    path: Path
    manifest: dict[str, Any]
    input_schema: dict[str, Any] | None
    output_schema: dict[str, Any] | None
    response_example: dict[str, Any]
    action_schemas: dict[str, dict[str, Any]] = field(default_factory=dict)
    checksum: str = ""
    warnings: list[str] = field(default_factory=list)

    @property
    def name(self) -> str:
        return str(self.manifest["name"])

    @property
    def version(self) -> str:
        return str(self.manifest["version"])

    @property
    def requires_rag(self) -> bool:
        return bool(self.manifest.get("requires_rag", True))

    @property
    def renderer(self) -> str:
        return str(self.manifest["renderer"])


@lru_cache(maxsize=1)
def manifest_validator() -> Draft202012Validator:
    schema = json.loads(_SCHEMA_PATH.read_text(encoding="utf-8"))
    return Draft202012Validator(schema)


def _read_json(base: Path, relative: str, label: str) -> dict[str, Any]:
    target = (base / relative).resolve()
    if base.resolve() not in target.parents:
        raise ManifestError(f"{label}: la ruta {relative!r} sale del paquete de la skill")
    if not target.is_file():
        raise ManifestError(f"{label}: no existe {relative!r}")
    try:
        data = json.loads(target.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ManifestError(f"{label}: JSON inválido en {relative!r}: {exc}") from exc
    if not isinstance(data, dict):
        raise ManifestError(f"{label}: {relative!r} debe contener un objeto JSON")
    return data


def find_manifest(path: Path) -> Path | None:
    for filename in MANIFEST_FILENAMES:
        candidate = path / filename
        if candidate.is_file():
            return candidate
    return None


def load_skill_package(path: Path) -> SkillPackage:
    """Carga un paquete y valida manifiesto, esquemas y ejemplo. Lanza ``ManifestError``."""
    path = Path(path)
    manifest_path = find_manifest(path)
    if manifest_path is None:
        raise ManifestError(f"{path}: falta skill.yaml")
    raw = manifest_path.read_bytes()
    try:
        manifest = yaml.safe_load(raw)
    except yaml.YAMLError as exc:
        raise ManifestError(f"{manifest_path}: YAML inválido: {exc}") from exc
    if not isinstance(manifest, dict):
        raise ManifestError(f"{manifest_path}: el manifiesto debe ser un objeto")

    errors = sorted(manifest_validator().iter_errors(manifest), key=lambda e: list(e.absolute_path))
    if errors:
        diagnostics = [
            f"{'/'.join(str(p) for p in error.absolute_path) or '(raíz)'}: {error.message}"
            for error in errors
        ]
        raise ManifestError(f"{manifest_path}: manifiesto inválido", diagnostics)

    if path.name != manifest["name"]:
        raise ManifestError(
            f"{path}: el directorio debe llamarse igual que la skill ({manifest['name']})"
        )

    digest = hashlib.sha256(raw)
    input_schema = output_schema = None
    if manifest.get("input_schema"):
        input_schema = _read_json(path, manifest["input_schema"], "input_schema")
        Draft202012Validator.check_schema(input_schema)
        digest.update(json.dumps(input_schema, sort_keys=True).encode())
    if manifest.get("output_schema"):
        output_schema = _read_json(path, manifest["output_schema"], "output_schema")
        Draft202012Validator.check_schema(output_schema)
        digest.update(json.dumps(output_schema, sort_keys=True).encode())
    example = _read_json(path, manifest["response_example"], "response_example")
    digest.update(json.dumps(example, sort_keys=True).encode())

    example_problems: list[str] = []
    if "chat_reply" not in example or "result" not in example:
        example_problems.append("response_example debe incluir chat_reply y result")
    elif input_schema is not None:
        for error in Draft202012Validator(input_schema).iter_errors(example["result"]):
            path_text = "/".join(str(p) for p in error.absolute_path) or "(raíz)"
            example_problems.append(
                f"response_example.result no cumple input_schema en {path_text}: {error.message}"
            )
    if example_problems:
        raise ManifestError(f"{path}: ejemplo inválido", example_problems)

    action_schemas: dict[str, dict[str, Any]] = {}
    for action_name, action in (manifest.get("actions") or {}).items():
        if action.get("input_schema"):
            schema = _read_json(path, action["input_schema"], f"actions.{action_name}.input_schema")
            Draft202012Validator.check_schema(schema)
            action_schemas[action_name] = schema
            digest.update(json.dumps(schema, sort_keys=True).encode())

    warnings: list[str] = []
    if not manifest.get("examples"):
        warnings.append("Sin frases de ejemplo: la evaluación de enrutado no cubrirá esta skill.")

    return SkillPackage(
        path=path,
        manifest=manifest,
        input_schema=input_schema,
        output_schema=output_schema,
        response_example=example,
        action_schemas=action_schemas,
        checksum=digest.hexdigest(),
        warnings=warnings,
    )


def parse_version(version: str) -> tuple[int, int, int]:
    match = _SEMVER.match(version)
    if not match:
        raise ValueError(f"Versión no SemVer: {version}")
    return int(match.group(1)), int(match.group(2)), int(match.group(3))


def version_satisfies(version: str, spec: str | None) -> bool:
    """Evalúa rangos simples separados por coma: ``>=1.0,<2.0``."""
    if not spec:
        return True
    current = parse_version(version)
    for clause in (part.strip() for part in spec.split(",") if part.strip()):
        match = re.match(r"^(>=|<=|>|<|==|\^|~)?\s*(\d+(?:\.\d+){0,2})$", clause)
        if not match:
            raise ValueError(f"Rango de versión no soportado: {clause}")
        op = match.group(1) or "=="
        parts = [int(x) for x in match.group(2).split(".")]
        while len(parts) < 3:
            parts.append(0)
        target = (parts[0], parts[1], parts[2])
        if op == "^":
            ok = current >= target and current[0] == target[0]
        elif op == "~":
            ok = current >= target and current[:2] == target[:2]
        else:
            ok = {
                ">=": current >= target,
                "<=": current <= target,
                ">": current > target,
                "<": current < target,
                "==": current == target,
            }[op]
        if not ok:
            return False
    return True
