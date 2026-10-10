"""SDK de skills: manifiesto, compatibilidad de versiones, validación y CLI (CG-06.7)."""

from __future__ import annotations

from pathlib import Path

import pytest
import yaml

from webassistant_skill_sdk import RetrievedSource, SkillContext, SkillHandler
from webassistant_skill_sdk.cli import main
from webassistant_skill_sdk.manifest import ManifestError, load_skill_package, version_satisfies
from webassistant_skill_sdk.testing import assert_skill_contract, inspect_skill_package

EXAMPLE = Path(__file__).resolve().parents[3] / "examples" / "skills" / "store_hours"


def test_example_skill_is_a_valid_package() -> None:
    package = load_skill_package(EXAMPLE)
    assert package.manifest["name"] == "store_hours"
    assert package.input_schema["type"] == "object"
    assert_skill_contract(EXAMPLE)


@pytest.mark.parametrize(
    ("version", "spec", "expected"),
    [
        ("1.0.0", ">=1.0,<2.0", True),
        ("1.4.2", ">=1.0,<2.0", True),
        ("2.0.0", ">=1.0,<2.0", False),
        ("0.9.0", ">=1.0", False),
        ("1.0.0", None, True),
    ],
)
def test_platform_version_ranges(version: str, spec: str | None, expected: bool) -> None:
    assert version_satisfies(version, spec) is expected


def test_cli_scaffolds_a_valid_skill_and_detects_errors(
    tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    assert main(["new", "horarios_tienda", "--dir", str(tmp_path)]) == 0
    created = tmp_path / "horarios_tienda"
    assert main(["validate", str(created)]) == 0
    assert "✓" in capsys.readouterr().out

    manifest = yaml.safe_load((created / "skill.yaml").read_text(encoding="utf-8"))
    manifest["renderer"] = "html_libre@1"
    manifest.pop("intent")
    (created / "skill.yaml").write_text(
        yaml.safe_dump(manifest, allow_unicode=True), encoding="utf-8"
    )
    errors, _ = inspect_skill_package(created)
    assert errors
    assert main(["validate", str(created)]) == 1
    with pytest.raises(ManifestError):
        load_skill_package(created)


def test_handler_defaults_are_pass_through() -> None:
    ctx = SkillContext(
        skill="store_hours",
        locale="es",
        tenant_id="t",
        site_id="s",
        subject="u",
        scopes=frozenset(),
        sources=[
            RetrievedSource(
                chunk_id="c", source_id="d", knowledge_base="kb", title="T", text="x", score=1.0
            )
        ],
    )
    handler = SkillHandler()
    result = {"answer": "Abrimos a las 10."}
    assert handler.prepare_result(ctx, result) == result
    assert handler.build_chat_reply(ctx, result, "Hola") == "Hola"
    assert ctx.source_by_id("d") is not None
