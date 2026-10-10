"""Selección de skills ofrecidas al modelo (CG-06.3).

Solo se proporcionan al modelo las skills activas en la versión publicada del
assistant, disponibles en el registro, permitidas para el tenant, cubiertas
por los scopes del usuario y compatibles con las capacidades del site.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from webassistant.security.scopes import missing_scopes
from webassistant.skills.registry import SkillDefinition, SkillRegistry

_REFERENCE_RE = re.compile(r"[^a-z0-9_]+")


@dataclass
class ActiveSkill:
    definition: SkillDefinition
    config: dict[str, Any] = field(default_factory=dict)

    @property
    def name(self) -> str:
        return self.definition.name


@dataclass
class SkillDenial:
    name: str
    reason: str


@dataclass
class MatchResult:
    active: list[ActiveSkill]
    denied: list[SkillDenial]

    def get(self, name: str | None) -> ActiveSkill | None:
        reference = sanitize_reference(name or "")
        for skill in self.active:
            if skill.name == reference:
                return skill
        return None

    @property
    def names(self) -> list[str]:
        return [s.name for s in self.active]


def sanitize_reference(reference: str) -> str:
    return _REFERENCE_RE.sub("_", reference.strip().lower()).strip("_")


class SkillMatcher:
    def __init__(self, registry: SkillRegistry) -> None:
        self.registry = registry

    def match(
        self,
        assistant_skills: list[dict[str, Any]],
        *,
        scopes: frozenset[str] | set[str],
        tenant_allowed: list[str] | None = None,
        site_capabilities: list[str] | None = None,
    ) -> MatchResult:
        active: list[ActiveSkill] = []
        denied: list[SkillDenial] = []
        capabilities = set(site_capabilities or [])
        for entry in assistant_skills:
            name = sanitize_reference(str(entry.get("name", "")))
            if not name or not entry.get("enabled", True):
                continue
            definition = self.registry.get(name)
            if definition is None:
                denied.append(SkillDenial(name, "no disponible en el registro"))
                continue
            if tenant_allowed is not None and name not in tenant_allowed:
                denied.append(SkillDenial(name, "no permitida para el tenant"))
                continue
            missing = missing_scopes(scopes, definition.permissions)
            if missing:
                denied.append(SkillDenial(name, "faltan scopes: " + ", ".join(missing)))
                continue
            missing_caps = [c for c in definition.required_capabilities if c not in capabilities]
            if missing_caps:
                denied.append(SkillDenial(name, "el site no declara: " + ", ".join(missing_caps)))
                continue
            active.append(ActiveSkill(definition, dict(entry.get("config") or {})))
        return MatchResult(active, denied)
