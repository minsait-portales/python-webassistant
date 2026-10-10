"""Registro y discovery de skills (CG-06.2).

Las skills se cargan desde paquetes registrados por entry point
(``webassistant.skills``) y desde rutas explícitas
(``WEBASSISTANT_SKILL_PATHS``). Al arrancar se comprueban manifiesto,
esquemas, ejemplo, renderer, versión de plataforma, dependencias y handler.
Una skill inválida queda deshabilitada con su diagnóstico.
"""

from __future__ import annotations

import importlib
from collections.abc import Iterable
from dataclasses import dataclass, field
from datetime import UTC, datetime
from importlib.metadata import entry_points
from pathlib import Path
from typing import Any

from webassistant import __version__
from webassistant.contracts import has_presentation_schema
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.observability.logging import get_logger
from webassistant_skill_sdk import (
    ActionHandler,
    ManifestError,
    SkillHandler,
    SkillPackage,
    load_skill_package,
)
from webassistant_skill_sdk.manifest import find_manifest, version_satisfies

logger = get_logger("webassistant.skills")
PLATFORM_VERSION = __version__.split("rc")[0].split("-")[0]


@dataclass
class SkillDefinition:
    package: SkillPackage | None
    source: str
    status: str = "available"
    diagnostics: list[str] = field(default_factory=list)
    handler: SkillHandler = field(default_factory=SkillHandler)
    action_handlers: dict[str, ActionHandler] = field(default_factory=dict)
    name_hint: str = ""

    @property
    def name(self) -> str:
        return self.package.name if self.package else self.name_hint

    @property
    def manifest(self) -> dict[str, Any]:
        return self.package.manifest if self.package else {}

    @property
    def version(self) -> str:
        return self.package.version if self.package else "0.0.0"

    @property
    def available(self) -> bool:
        return self.status == "available" and self.package is not None

    @property
    def requires_rag(self) -> bool:
        return bool(self.manifest.get("requires_rag", True))

    @property
    def renderer(self) -> str:
        return str(self.manifest.get("renderer", "text@1"))

    @property
    def permissions(self) -> list[str]:
        return list(self.manifest.get("permissions", []))

    @property
    def actions(self) -> dict[str, dict[str, Any]]:
        return dict(self.manifest.get("actions") or {})

    @property
    def processors(self) -> list[str]:
        return list(self.manifest.get("processors", []))

    @property
    def response_example(self) -> dict[str, Any]:
        return self.package.response_example if self.package else {}

    @property
    def required_capabilities(self) -> list[str]:
        return list((self.manifest.get("dependencies") or {}).get("capabilities", []))


def _import_object(path: str) -> Any:
    module_name, _, attr = path.partition(":")
    module = importlib.import_module(module_name)
    return getattr(module, attr)


class SkillRegistry:
    def __init__(
        self,
        *,
        paths: Iterable[Path] = (),
        load_builtin: bool = True,
        load_entry_points: bool = True,
        platform_version: str = PLATFORM_VERSION,
    ) -> None:
        self.paths = [Path(p) for p in paths]
        self.load_builtin = load_builtin
        self.load_entry_points = load_entry_points
        self.platform_version = platform_version
        self._skills: dict[str, SkillDefinition] = {}
        self.discovered_at: datetime | None = None

    # --- Discovery -----------------------------------------------------------------

    def _candidate_dirs(self) -> list[tuple[Path, str]]:
        candidates: list[tuple[Path, str]] = []
        seen_builtin = False
        if self.load_entry_points:
            for ep in entry_points(group="webassistant.skills"):
                if ep.name == "builtin":
                    seen_builtin = True
                    if not self.load_builtin:
                        continue
                try:
                    provided = ep.load()()
                except Exception as exc:
                    logger.error("No se pudo cargar el entry point de skills %s: %s", ep.name, exc)
                    continue
                for path in provided:
                    candidates.append((Path(path), f"entry_point:{ep.name}"))
        if self.load_builtin and not seen_builtin:
            from webassistant.builtin_skills import skill_paths

            candidates.extend((p, "entry_point:builtin") for p in skill_paths())
        for base in self.paths:
            if not base.exists():
                logger.warning("Ruta de skills inexistente: %s", base)
                continue
            if find_manifest(base):
                candidates.append((base, f"path:{base}"))
                continue
            for child in sorted(base.iterdir()):
                if child.is_dir() and find_manifest(child):
                    candidates.append((child, f"path:{base}"))
        return candidates

    def discover(self) -> dict[str, SkillDefinition]:
        skills: dict[str, SkillDefinition] = {}
        for path, source in self._candidate_dirs():
            definition = self._load(path, source)
            name = definition.name
            if name in skills:
                existing = skills[name]
                definition.status = "invalid"
                definition.diagnostics.append(
                    f"Nombre duplicado: ya registrada desde {existing.source} (versión {existing.version})."
                )
                skills[f"{name}@{source}"] = definition
                continue
            skills[name] = definition
        self._check_dependencies(skills)
        self._skills = skills
        self.discovered_at = datetime.now(UTC)
        for definition in skills.values():
            if not definition.available:
                logger.warning(
                    "Skill %s deshabilitada: %s", definition.name, "; ".join(definition.diagnostics)
                )
        return skills

    def _load(self, path: Path, source: str) -> SkillDefinition:
        try:
            package = load_skill_package(path)
        except ManifestError as exc:
            return SkillDefinition(
                None, source, "invalid", list(exc.diagnostics), name_hint=path.name
            )
        except Exception as exc:
            return SkillDefinition(
                None, source, "invalid", [f"Error inesperado: {exc}"], name_hint=path.name
            )
        definition = SkillDefinition(package, source)
        definition.diagnostics.extend(package.warnings)
        manifest = package.manifest
        if not has_presentation_schema(package.renderer):
            definition.status = "incompatible"
            definition.diagnostics.append(f"Renderer desconocido: {package.renderer}")
        platform_spec = (manifest.get("dependencies") or {}).get("platform")
        try:
            if not version_satisfies(self.platform_version, platform_spec):
                definition.status = "incompatible"
                definition.diagnostics.append(
                    f"Requiere plataforma {platform_spec}; versión actual {self.platform_version}"
                )
        except ValueError as exc:
            definition.status = "invalid"
            definition.diagnostics.append(str(exc))
        if manifest.get("handler"):
            try:
                handler_cls = _import_object(manifest["handler"])
                handler = handler_cls()
                if not isinstance(handler, SkillHandler):
                    raise TypeError("el handler debe heredar de SkillHandler")
                definition.handler = handler
            except Exception as exc:
                definition.status = "invalid"
                definition.diagnostics.append(f"Handler no cargable ({manifest['handler']}): {exc}")
        for action_name, action in (manifest.get("actions") or {}).items():
            if action["type"] == "internal":
                if not action.get("handler"):
                    definition.status = "invalid"
                    definition.diagnostics.append(
                        f"La acción interna {action_name} necesita handler"
                    )
                    continue
                try:
                    handler_obj = _import_object(action["handler"])()
                    if not isinstance(handler_obj, ActionHandler):
                        raise TypeError("debe heredar de ActionHandler")
                    definition.action_handlers[action_name] = handler_obj
                except Exception as exc:
                    definition.status = "invalid"
                    definition.diagnostics.append(
                        f"Handler de acción {action_name} no cargable: {exc}"
                    )
        return definition

    @staticmethod
    def _check_dependencies(skills: dict[str, SkillDefinition]) -> None:
        changed = True
        while changed:
            changed = False
            for definition in skills.values():
                if not definition.available:
                    continue
                for dependency in (definition.manifest.get("dependencies") or {}).get("skills", []):
                    target = skills.get(dependency)
                    if target is None or not target.available:
                        definition.status = "incompatible"
                        definition.diagnostics.append(
                            f"Depende de la skill no disponible: {dependency}"
                        )
                        changed = True

    # --- Consulta ------------------------------------------------------------------

    def get(self, name: str) -> SkillDefinition | None:
        definition = self._skills.get(name)
        return definition if definition and definition.available else None

    def all(self) -> list[SkillDefinition]:
        return sorted(self._skills.values(), key=lambda d: d.name)

    def available(self) -> list[SkillDefinition]:
        return [d for d in self.all() if d.available]

    async def sync_to_db(self, db: Database) -> None:
        async with UnitOfWork(db, platform=True) as uow:
            existing = {s.name: s for s in await uow.skills.list()}
            processed: set[str] = set()
            for definition in self.all():
                name = definition.name
                if name in processed:
                    continue
                processed.add(name)
                row = existing.get(name)
                if row is None:
                    row = m.Skill(
                        name=name,
                        version=definition.version,
                        status=definition.status,
                        source=definition.source,
                    )
                    uow.session.add(row)
                row.version = definition.version
                row.status = definition.status
                row.source = definition.source[:500]
                row.manifest = definition.manifest
                row.diagnostics = list(definition.diagnostics)
                row.checksum = definition.package.checksum if definition.package else ""
                row.discovered_at = self.discovered_at or datetime.now(UTC)
            names = {d.name for d in self.all()}
            for name, row in existing.items():
                if name not in names:
                    row.status = "missing"
                    row.diagnostics = ["La skill ya no está instalada."]
