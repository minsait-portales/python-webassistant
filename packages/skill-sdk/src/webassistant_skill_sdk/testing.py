"""Tests de contrato reutilizables para paquetes de skill."""

from __future__ import annotations

from pathlib import Path

from jsonschema import Draft202012Validator

from webassistant_skill_sdk.manifest import ManifestError, load_skill_package

KNOWN_RENDERERS = {
    "text@1",
    "answer@1",
    "product_grid@1",
    "product_comparison@1",
    "product_summary@1",
    "event_selection@1",
    "document_citations@1",
    "clarification@1",
    "steps@1",
    "cart@1",
    "host_action@1",
}


def inspect_skill_package(path: Path) -> tuple[list[str], list[str]]:
    """Devuelve ``(errores, avisos)`` del paquete; sin errores cumple el contrato."""
    try:
        package = load_skill_package(path)
    except ManifestError as exc:
        return list(exc.diagnostics), []
    errors: list[str] = []
    warnings: list[str] = list(package.warnings)
    if package.renderer not in KNOWN_RENDERERS:
        errors.append(f"Renderer desconocido: {package.renderer}")
    if package.input_schema is None:
        warnings.append("Se recomienda declarar input_schema para validar la salida del modelo")
    for name, action in (package.manifest.get("actions") or {}).items():
        if (
            action.get("effects")
            and action.get("confirmation") != "required"
            and action["type"] == "host"
        ):
            warnings.append(f"La acción {name} tiene efectos sin confirmación explícita")
        schema = package.action_schemas.get(name)
        if schema is not None:
            Draft202012Validator.check_schema(schema)
    return errors, warnings


def check_skill_package(path: Path) -> list[str]:
    """Errores de contrato del paquete (lista vacía si es válido)."""
    return inspect_skill_package(path)[0]


def assert_skill_contract(path: Path) -> None:
    errors = check_skill_package(path)
    assert not errors, "\n".join(errors)
