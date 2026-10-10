"""Validación y construcción de la respuesta estructurada (CG-06.4, CG-09).

Flujo: salida del modelo -> parseo -> sobre (skill/chat_reply/result) ->
``prepare_result`` del handler -> ``input_schema`` de la skill -> procesadores
-> ``build_presentation`` -> esquema del renderer (+ ``output_schema``) ->
acciones y citas. Ningún payload sin validar llega al renderer: si la
presentación no es válida se usa el fallback de texto.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from typing import Any

from webassistant.contracts import (
    format_errors,
    load_schema,
    presentation_schema_name,
    validator_for,
    validator_for_schema,
)
from webassistant.observability.logging import get_logger
from webassistant.skills.matcher import ActiveSkill
from webassistant.skills.processors import PROCESSORS, ProcessingReport, safe_url
from webassistant.text import first_sentence, limit_text, strip_code_fences
from webassistant_skill_sdk import SkillContext

logger = get_logger("webassistant.skills.runtime")

MAX_CHAT_REPLY = 600
FALLBACK_RENDERER = "text@1"


@dataclass
class BuiltResponse:
    skill: str
    chat_reply: str
    presentation: dict[str, Any] | None
    actions: list[dict[str, Any]]
    citations: list[dict[str, Any]]
    keep_presentation: bool = False
    out_of_scope: bool = False
    responsive_button_label: str = ""
    report: ProcessingReport = field(default_factory=ProcessingReport)
    structured_result: dict[str, Any] = field(default_factory=dict)
    fallback_used: bool = False


def parse_model_output(text: str | None) -> dict[str, Any] | None:
    if not text:
        return None
    cleaned = strip_code_fences(text)
    try:
        data = json.loads(cleaned)
    except json.JSONDecodeError:
        match = re.search(r"\{.*\}", cleaned, re.DOTALL)
        if not match:
            return None
        try:
            data = json.loads(match.group(0))
        except json.JSONDecodeError:
            return None
    return data if isinstance(data, dict) else None


def envelope_errors(data: dict[str, Any] | None) -> list[dict[str, Any]]:
    if data is None:
        return [{"path": "/", "message": "La respuesta no es un objeto JSON válido"}]
    errors = []
    if not isinstance(data.get("skill"), str) or not data.get("skill"):
        errors.append({"path": "/skill", "message": "Falta `skill`"})
    if not isinstance(data.get("chat_reply"), str):
        errors.append({"path": "/chat_reply", "message": "`chat_reply` debe ser texto"})
    if not isinstance(data.get("result", {}), dict):
        errors.append({"path": "/result", "message": "`result` debe ser un objeto"})
    return errors


def is_out_of_scope(data: dict[str, Any]) -> bool:
    return bool(data.get("out_of_scope")) and bool(str(data.get("chat_reply", "")).strip())


def has_content(value: Any) -> bool:
    if isinstance(value, dict):
        return any(has_content(v) for v in value.values())
    if isinstance(value, list):
        return any(has_content(v) for v in value)
    if isinstance(value, bool):
        return False
    return value is not None and str(value).strip() != ""


def _renderer_properties(renderer: str) -> set[str]:
    schema = load_schema(presentation_schema_name(renderer))
    return set((schema.get("properties") or {}).keys())


def _clean_none(
    value: Any,
    keep: frozenset[str] = frozenset(
        {"variation_id", "product_id", "page", "rating", "price_amount"}
    ),
) -> Any:
    if isinstance(value, dict):
        return {k: _clean_none(v) for k, v in value.items() if v is not None or k in keep}
    if isinstance(value, list):
        return [_clean_none(v) for v in value]
    return value


class SkillRuntime:
    def validate_result(self, skill: ActiveSkill, result: dict[str, Any]) -> list[dict[str, Any]]:
        schema = skill.definition.package.input_schema if skill.definition.package else None
        if not schema:
            return []
        return format_errors(list(validator_for_schema(schema).iter_errors(result)))

    def prepare(
        self, skill: ActiveSkill, ctx: SkillContext, result: dict[str, Any]
    ) -> dict[str, Any]:
        try:
            prepared = skill.definition.handler.prepare_result(ctx, dict(result))
        except Exception:
            logger.exception("prepare_result falló en la skill %s", skill.name)
            return dict(result)
        return prepared if isinstance(prepared, dict) else dict(result)

    def to_renderer_data(self, skill: ActiveSkill, result: dict[str, Any]) -> dict[str, Any]:
        renderer = skill.definition.renderer
        manifest = skill.definition.manifest
        data = dict(result)
        name = renderer.split("@")[0]
        if name == "text":
            data.setdefault("title", manifest.get("title", ""))
        elif name == "host_action":
            host_actions = [
                n for n, a in skill.definition.actions.items() if a.get("type") == "host"
            ]
            data.setdefault("title", manifest.get("title", ""))
            data.setdefault("action", host_actions[0] if host_actions else "")
            details = []
            for key, value in result.items():
                if (
                    key in {"title", "description", "action", "details"}
                    or isinstance(value, dict | list)
                    or value in (None, "")
                ):
                    continue
                details.append(
                    {"label": key.replace("_", " ").capitalize()[:300], "value": str(value)[:300]}
                )
            data.setdefault("details", details[:10])
        elif name == "cart":
            auto = [n for n, a in skill.definition.actions.items() if a.get("auto")]
            if auto:
                data.setdefault("source_action", auto[0])
            data.setdefault("items", [])
        elif name == "answer":
            data.setdefault("answer", "")
        elif name == "product_grid":
            data.setdefault("items", [])
            item_actions = [
                n for n, a in skill.definition.actions.items() if a.get("target") == "item"
            ]
            if item_actions:
                data["items"] = [{**item, "actions": item_actions} for item in data["items"]]
        allowed = _renderer_properties(renderer)
        return _clean_none({k: v for k, v in data.items() if k in allowed})

    def presentation_errors(self, skill: ActiveSkill, data: dict[str, Any]) -> list[dict[str, Any]]:
        errors = format_errors(
            list(
                validator_for(presentation_schema_name(skill.definition.renderer)).iter_errors(data)
            )
        )
        output_schema = skill.definition.package.output_schema if skill.definition.package else None
        if output_schema:
            errors += format_errors(list(validator_for_schema(output_schema).iter_errors(data)))
        return errors

    def actions_for(
        self, skill: ActiveSkill, result: dict[str, Any], scopes: frozenset[str]
    ) -> list[dict[str, Any]]:
        from webassistant.security.scopes import has_scopes

        actions = []
        package = skill.definition.package
        for name, action in skill.definition.actions.items():
            permission = action.get("permission")
            if permission and not has_scopes(scopes, [permission]):
                continue
            schema = package.action_schemas.get(name, {}) if package else {}
            params: dict[str, Any] = {}
            if action.get("target", "presentation") == "presentation":
                for key in schema.get("properties") or {}:
                    if key in result and result[key] not in (None, ""):
                        params[key] = result[key]
            actions.append(
                {
                    "name": name,
                    "skill": skill.name,
                    "type": action["type"],
                    "label": action["label"],
                    "description": action.get("description", ""),
                    "confirmation": action.get("confirmation", "none"),
                    "effects": bool(action.get("effects", False)),
                    "auto": bool(action.get("auto", False)),
                    "target": action.get("target", "presentation"),
                    "params": params,
                }
            )
        return actions

    @staticmethod
    def citations_for(ctx: SkillContext, report: ProcessingReport) -> list[dict[str, Any]]:
        citations = []
        by_id = {s.source_id: s for s in ctx.sources}
        for source_id in report.used_sources[:8]:
            source = by_id.get(source_id)
            if source is None:
                continue
            citations.append(
                {
                    "source_id": source.source_id,
                    "title": source.title[:300],
                    "url": safe_url(source.url),
                    "snippet": limit_text(source.text, 280),
                    "locale": source.locale,
                    "document_type": source.document_type,
                    "knowledge_base": source.knowledge_base,
                    "score": round(float(source.score), 4),
                }
            )
        return citations

    def chat_reply(
        self, skill: ActiveSkill, ctx: SkillContext, data: dict[str, Any], result: dict[str, Any]
    ) -> str:
        reply = str(data.get("chat_reply") or "").strip()
        if not reply:
            reply = str(skill.definition.manifest.get("chat_reply_fallback") or "")
        if not reply:
            reply = first_sentence(
                str(result.get("description") or result.get("answer") or result.get("intro") or "")
            )
        try:
            reply = skill.definition.handler.build_chat_reply(ctx, result, reply)
        except Exception:
            logger.exception("build_chat_reply falló en la skill %s", skill.name)
        return limit_text(reply or "He preparado la respuesta.", MAX_CHAT_REPLY)

    def build(
        self, skill: ActiveSkill, ctx: SkillContext, data: dict[str, Any], result: dict[str, Any]
    ) -> BuiltResponse:
        """``result`` ya preparado y validado contra ``input_schema``."""
        report = ProcessingReport()
        processed = dict(result)
        for processor_name in skill.definition.processors:
            processor = PROCESSORS.get(processor_name)
            if processor is not None:
                processed = processor(ctx, processed, report)
        try:
            presented = skill.definition.handler.build_presentation(ctx, processed)
        except Exception:
            logger.exception("build_presentation falló en la skill %s", skill.name)
            presented = processed
        presentation_data = self.to_renderer_data(
            skill, presented if isinstance(presented, dict) else processed
        )
        errors = self.presentation_errors(skill, presentation_data)
        chat_reply = self.chat_reply(skill, ctx, data, processed)
        if errors:
            logger.warning("Presentación inválida en %s: %s", skill.name, errors[:3])
            return self.fallback(skill.name, chat_reply, report=report, structured=data)
        renderer_name, _, version = skill.definition.renderer.partition("@")
        return BuiltResponse(
            skill=skill.name,
            chat_reply=chat_reply,
            presentation={
                "type": renderer_name,
                "version": version or "1",
                "data": presentation_data,
            },
            actions=self.actions_for(skill, processed, ctx.scopes),
            citations=self.citations_for(ctx, report),
            responsive_button_label=str(
                skill.definition.manifest.get("responsive_button_label", "")
            ),
            report=report,
            structured_result=data,
        )

    @staticmethod
    def fallback(
        skill_name: str,
        chat_reply: str,
        *,
        title: str = "",
        description: str = "",
        report: ProcessingReport | None = None,
        structured: dict[str, Any] | None = None,
    ) -> BuiltResponse:
        data = {"title": title or "Respuesta", "description": description or chat_reply}
        return BuiltResponse(
            skill=skill_name,
            chat_reply=limit_text(chat_reply, MAX_CHAT_REPLY),
            presentation={"type": "text", "version": "1", "data": data},
            actions=[],
            citations=[],
            report=report or ProcessingReport(),
            structured_result=structured or {},
            fallback_used=True,
        )
