"""Motor de skills: discovery, matcher, validación, procesadores y presentación (CG-06)."""

from __future__ import annotations

import json
import shutil
from pathlib import Path

import pytest

from webassistant.contracts import load_schema, schemas_dir, validate
from webassistant.skills.matcher import SkillMatcher
from webassistant.skills.registry import SkillRegistry
from webassistant.skills.runtime import SkillRuntime
from webassistant_skill_sdk import RetrievedSource, SkillContext

REPO = Path(__file__).resolve().parents[3]
BUILTIN = REPO / "apps" / "api" / "src" / "webassistant" / "builtin_skills"
EXAMPLE = REPO / "examples" / "skills"


@pytest.fixture(scope="module")
def registry() -> SkillRegistry:
    registry = SkillRegistry(paths=[EXAMPLE])
    registry.discover()
    return registry


def test_all_builtin_skills_are_available(registry: SkillRegistry) -> None:
    names = {d.name for d in registry.available()}
    expected = {
        "default",
        "content_answer",
        "content_create",
        "content_edit",
        "user_profile",
        "clarification",
        "product_results",
        "product_recommendations",
        "product_alternatives",
        "product_comparison",
        "product_summary",
        "event_product_selection",
        "shopping_cart_add",
        "shopping_cart_view",
        "payment",
        "sales_analytics",
        "document_lookup",
    }
    assert expected <= names
    assert "store_hours" in names, "la skill de ejemplo del SDK se carga sin modificar el núcleo"


def test_examples_validate_against_input_schemas(registry: SkillRegistry) -> None:
    for definition in registry.available():
        assert definition.package is not None
        if definition.package.input_schema:
            assert not validate_example(
                definition.package.input_schema, definition.response_example["result"]
            ), definition.name


def validate_example(schema: dict, value: dict) -> list:
    from webassistant.contracts import format_errors, validator_for_schema

    return format_errors(list(validator_for_schema(schema).iter_errors(value)))


def test_invalid_skill_is_disabled_with_diagnostics(tmp_path: Path) -> None:
    broken = tmp_path / "broken_skill"
    shutil.copytree(EXAMPLE / "store_hours", broken)
    manifest = (
        (broken / "skill.yaml")
        .read_text(encoding="utf-8")
        .replace("name: store_hours", "name: broken_skill")
    )
    manifest = manifest.replace("renderer: answer@1", "renderer: hologram@9")
    (broken / "skill.yaml").write_text(manifest, encoding="utf-8")
    bad_example = tmp_path / "bad_example"
    shutil.copytree(EXAMPLE / "store_hours", bad_example)
    (bad_example / "skill.yaml").write_text(
        (EXAMPLE / "store_hours" / "skill.yaml")
        .read_text(encoding="utf-8")
        .replace("name: store_hours", "name: bad_example"),
        encoding="utf-8",
    )
    (bad_example / "examples" / "response.json").write_text(
        json.dumps({"chat_reply": "x", "result": {"answer": 5}}), encoding="utf-8"
    )
    registry = SkillRegistry(paths=[tmp_path], load_builtin=False, load_entry_points=False)
    registry.discover()
    states = {d.name: (d.status, d.diagnostics) for d in registry.all()}
    assert states["broken_skill"][0] == "incompatible"
    assert any("hologram" in d for d in states["broken_skill"][1])
    assert states["bad_example"][0] == "invalid"
    assert registry.get("broken_skill") is None


def test_platform_version_dependency(tmp_path: Path) -> None:
    future = tmp_path / "future_skill"
    shutil.copytree(EXAMPLE / "store_hours", future)
    text = (
        (future / "skill.yaml")
        .read_text(encoding="utf-8")
        .replace("name: store_hours", "name: future_skill")
    )
    (future / "skill.yaml").write_text(
        text.replace('platform: ">=1.0,<2.0"', 'platform: ">=3.0"'), encoding="utf-8"
    )
    registry = SkillRegistry(paths=[tmp_path], load_builtin=False, load_entry_points=False)
    registry.discover()
    assert registry.all()[0].status == "incompatible"


def test_matcher_filters_by_scopes_capabilities_and_tenant(registry: SkillRegistry) -> None:
    matcher = SkillMatcher(registry)
    entries = [
        {"name": n}
        for n in ("default", "product_results", "shopping_cart_view", "sales_analytics", "ghost")
    ]
    result = matcher.match(entries, scopes={"chat:use", "catalog:read"}, site_capabilities=[])
    assert result.names == ["default", "product_results"]
    reasons = {d.name: d.reason for d in result.denied}
    assert "cart:read" in reasons["shopping_cart_view"]
    assert "analytics:read" in reasons["sales_analytics"]
    assert "registro" in reasons["ghost"]
    restricted = matcher.match(
        entries,
        scopes={"chat:use", "catalog:read", "cart:read"},
        tenant_allowed=["default"],
        site_capabilities=["cart"],
    )
    assert restricted.names == ["default"]
    with_cart = matcher.match(
        entries, scopes={"chat:use", "catalog:read", "cart:read"}, site_capabilities=["cart"]
    )
    assert "shopping_cart_view" in with_cart.names


def _ctx(
    skill: str, sources: list[RetrievedSource], selection: list[dict] | None = None
) -> SkillContext:
    return SkillContext(
        skill=skill,
        locale="es",
        tenant_id="t",
        site_id="s",
        subject="u",
        scopes=frozenset({"chat:use", "catalog:read", "cart:write"}),
        sources=sources,
        active_selection=selection or [],
    )


PRODUCT = RetrievedSource(
    chunk_id="c1",
    source_id="p-1",
    knowledge_base="catalogo",
    title="Producto real",
    text="Descripción real.",
    score=0.9,
    url="https://shop.example.com/p-1",
    document_type="product",
    metadata={
        "product_id": "p-1",
        "variation_id": "v-1",
        "price_amount": 12.5,
        "currency": "EUR",
        "image_url": "javascript:alert(1)",
        "category": "Cat",
    },
)


def test_catalog_processor_drops_invented_items_and_enriches_real_ones(
    registry: SkillRegistry,
) -> None:
    matcher = SkillMatcher(registry)
    skill = matcher.match([{"name": "product_results"}], scopes={"catalog:read", "cart:write"}).get(
        "product_results"
    )
    assert skill is not None
    runtime = SkillRuntime()
    ctx = _ctx("product_results", [PRODUCT])
    result = {
        "intro": "x",
        "items": [
            {"product_id": "p-1", "title": "Producto"},
            {"product_id": "inventado-999", "title": "Falso"},
        ],
    }
    built = runtime.build(
        skill, ctx, {"skill": "product_results", "chat_reply": "Hola", "result": result}, result
    )
    items = built.presentation["data"]["items"]
    assert [i["product_id"] for i in items] == ["p-1"]
    assert items[0]["price"] == "12,50 €"
    assert "image_url" not in items[0], "las URLs no http(s) se descartan"
    assert items[0]["actions"] == ["add_to_cart"]
    assert built.report.dropped_items == ["inventado-999"]
    assert [c["source_id"] for c in built.citations] == ["p-1"]
    assert not validate("presentations/product_grid.v1.schema.json", built.presentation["data"])
    assert {a["name"] for a in built.actions} == {"add_to_cart"}


def test_actions_hidden_without_permission(registry: SkillRegistry) -> None:
    skill = (
        SkillMatcher(registry)
        .match([{"name": "product_results"}], scopes={"catalog:read"})
        .get("product_results")
    )
    runtime = SkillRuntime()
    ctx = SkillContext(
        skill="product_results",
        locale="es",
        tenant_id="t",
        site_id="s",
        subject="u",
        scopes=frozenset({"catalog:read"}),
        sources=[PRODUCT],
    )
    result = {"items": [{"product_id": "p-1"}]}
    built = runtime.build(
        skill, ctx, {"skill": "product_results", "chat_reply": "", "result": result}, result
    )
    assert built.actions == []


def test_document_citations_are_verified(registry: SkillRegistry) -> None:
    skill = (
        SkillMatcher(registry)
        .match([{"name": "document_lookup"}], scopes={"documents:read"})
        .get("document_lookup")
    )
    doc = RetrievedSource(
        chunk_id="c2",
        source_id="manual",
        knowledge_base="docs",
        title="Manual",
        text="La batería tiene 5000 mAh de capacidad.",
        score=0.8,
        url="https://portal/manual.pdf",
        page=3,
        document_type="document",
    )
    runtime = SkillRuntime()
    result = {
        "answer": "5000 mAh",
        "citations": [
            {"document_title": "Manual", "quote": "La batería tiene 5000 mAh"},
            {"document_title": "Inventado", "quote": "Garantía de 50 años para todo"},
        ],
    }
    built = runtime.build(
        skill,
        _ctx("document_lookup", [doc]),
        {"skill": "document_lookup", "chat_reply": "ok", "result": result},
        result,
    )
    data = built.presentation["data"]
    assert len(data["citations"]) == 1
    assert data["citations"][0]["verified"] is True and data["citations"][0]["page"] == 3
    assert data["documents"][0]["url"] == "https://portal/manual.pdf"
    assert built.report.unverified_citations == 1


def test_invalid_presentation_falls_back_to_text(registry: SkillRegistry) -> None:
    skill = (
        SkillMatcher(registry).match([{"name": "clarification"}], scopes=set()).get("clarification")
    )
    runtime = SkillRuntime()
    result = {"question": "x" * 5000}
    built = runtime.build(
        skill,
        _ctx("clarification", []),
        {"skill": "clarification", "chat_reply": "¿Cuál?", "result": result},
        result,
    )
    assert built.fallback_used and built.presentation["type"] == "text"


def test_host_action_presentation_for_drupal_like_skills(registry: SkillRegistry) -> None:
    skill = (
        SkillMatcher(registry)
        .match(
            [{"name": "content_edit"}],
            scopes={"content:write"},
            site_capabilities=["content_forms"],
        )
        .get("content_edit")
    )
    runtime = SkillRuntime()
    result = {"content_title": "Contacto", "content_type": "page", "description": "Abre la edición"}
    built = runtime.build(
        skill,
        _ctx("content_edit", []),
        {"skill": "content_edit", "chat_reply": "ok", "result": result},
        result,
    )
    assert built.presentation["type"] == "host_action"
    assert built.presentation["data"]["action"] == "open_edit_form"
    assert built.actions[0]["params"] == {"content_title": "Contacto", "content_type": "page"}


def test_contract_copies_are_in_sync() -> None:
    sdk_copy = (
        REPO
        / "packages"
        / "skill-sdk"
        / "src"
        / "webassistant_skill_sdk"
        / "schemas"
        / "skill-manifest.v1.schema.json"
    )
    assert json.loads(sdk_copy.read_text()) == load_schema("skill-manifest.v1.schema.json")
    assert schemas_dir().is_dir()


def test_skill_sdk_scaffold_is_valid(tmp_path: Path) -> None:
    from webassistant_skill_sdk.cli import scaffold
    from webassistant_skill_sdk.testing import assert_skill_contract

    path = scaffold("faq_lookup", tmp_path)
    assert_skill_contract(path)
    registry = SkillRegistry(paths=[tmp_path], load_builtin=False, load_entry_points=False)
    registry.discover()
    assert registry.get("faq_lookup") is not None
