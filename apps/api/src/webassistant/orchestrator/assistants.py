"""Configuración versionada de Assistant y política de agente (CG-05.1, CG-05.2, CG-11.2).

El borrador se edita libremente; publicar crea una versión inmutable con la
política de agente congelada y las skills habilitadas (``assistant_skills``).
Restaurar copia una versión anterior al borrador (y puede publicarla), por lo
que publicar es explícito y reversible.
"""

from __future__ import annotations

import copy
import time
from dataclasses import dataclass, field
from typing import Any

from webassistant import ids
from webassistant.contracts import validate
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode, validation

DEFAULT_AGENT_POLICY: dict[str, Any] = {
    "max_iterations": 4,
    "max_tool_calls": 6,
    "allowed_tools": ["search_knowledge"],
    "rag": {"amount": 6, "max_amount": 10, "min_score": 0.2},
    "repair_attempts": 1,
    "force_rag_retry": True,
    "loop_detection": True,
    "history_window": 8,
}

DEFAULT_ASSISTANT_CONFIG: dict[str, Any] = {
    "instructions": "Eres el asistente del sitio. Ayudas a encontrar información, productos y a completar tareas.",
    "scope_description": "",
    "default_locale": "es",
    "locales": ["es"],
    "model": {"provider": "simulated", "model_id": "simulated-chat-v1", "params": {}},
    "limits": {
        "max_message_length": 800,
        "max_history_messages": 8,
        "max_context_tokens": 24000,
        "max_output_tokens": 2000,
    },
    "messages": {
        "welcome": "Bienvenido a tu nueva manera de navegar. ¿Qué necesitas?",
        "input_placeholder": "Escribe tu consulta...",
        "privacy_notice": "Al enviar una consulta aceptas que se procese para generar la respuesta del asistente. "
        "No incluyas datos personales, confidenciales o de pago.",
        "login_required": "Para usar el asistente debes iniciar sesión.",
        "access_denied": "No tienes permisos para usar este asistente.",
        "fallback": "La consulta es demasiado exigente para resolverla de una sola vez. Prueba a concretarla un poco más.",
        "errors": {},
    },
    "skills": [{"name": "default", "enabled": True}],
    "knowledge_bases": [],
    "suggestions": [],
    "history": {"strategy": "window", "window_messages": 8},
}


def merge_config(base: dict[str, Any], override: dict[str, Any]) -> dict[str, Any]:
    result = copy.deepcopy(base)
    for key, value in (override or {}).items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = merge_config(result[key], value)
        else:
            result[key] = copy.deepcopy(value)
    return result


@dataclass
class PublishedAssistant:
    assistant_id: str
    tenant_id: str
    key: str
    name: str
    version: int
    config: dict[str, Any]
    agent_policy: dict[str, Any]
    skills: list[dict[str, Any]] = field(default_factory=list)

    @property
    def model_provider(self) -> str:
        return str(self.config["model"].get("provider", "simulated"))

    @property
    def model_id(self) -> str:
        return str(self.config["model"]["model_id"])

    @property
    def messages(self) -> dict[str, Any]:
        return dict(self.config.get("messages") or {})

    @property
    def limits(self) -> dict[str, Any]:
        return dict(self.config.get("limits") or {})


class AssistantService:
    def __init__(self, db: Database, cache_seconds: float = 10.0) -> None:
        self.db = db
        self.cache_seconds = cache_seconds
        self._cache: dict[tuple[str, str], tuple[float, PublishedAssistant]] = {}

    def invalidate(self, tenant_id: str | None = None) -> None:
        if tenant_id is None:
            self._cache.clear()
            return
        for key in [k for k in self._cache if k[0] == tenant_id]:
            self._cache.pop(key, None)

    async def published(self, tenant_id: str, assistant_ref: str) -> PublishedAssistant:
        cache_key = (tenant_id, assistant_ref)
        cached = self._cache.get(cache_key)
        if cached and time.monotonic() - cached[0] < self.cache_seconds:
            return cached[1]
        async with UnitOfWork(self.db, tenant_id) as uow:
            assistant = await uow.assistants.by_id_or_key(assistant_ref)
            if (
                assistant is None
                or assistant.status == "archived"
                or assistant.published_version is None
            ):
                raise AppError(ErrorCode.NOT_FOUND, "Assistant no disponible")
            version = await uow.assistant_versions.by_number(
                assistant.id, assistant.published_version
            )
            if version is None:
                raise AppError(ErrorCode.NOT_FOUND, "Versión publicada no encontrada")
            skills = await uow.assistant_skills.list(
                m.AssistantSkill.assistant_version_id == version.id
            )
            published = PublishedAssistant(
                assistant_id=assistant.id,
                tenant_id=tenant_id,
                key=assistant.key,
                name=assistant.name,
                version=version.version,
                config=merge_config(DEFAULT_ASSISTANT_CONFIG, version.config),
                agent_policy=merge_config(DEFAULT_AGENT_POLICY, version.agent_policy),
                skills=[
                    {"name": s.skill_name, "enabled": s.enabled, "config": s.config} for s in skills
                ],
            )
        self._cache[cache_key] = (time.monotonic(), published)
        self._cache[(tenant_id, published.assistant_id)] = (time.monotonic(), published)
        return published

    @staticmethod
    def validate_config(config: dict[str, Any]) -> dict[str, Any]:
        merged = merge_config(DEFAULT_ASSISTANT_CONFIG, config)
        errors = validate("assistant-config.v1.schema.json", merged)
        if errors:
            raise validation("Configuración de assistant inválida", errors)
        return merged

    async def publish(
        self, uow: UnitOfWork, assistant: m.Assistant, *, actor_id: str, note: str = ""
    ) -> m.AssistantVersion:
        config = self.validate_config(assistant.draft_config)
        policy = dict(DEFAULT_AGENT_POLICY)
        if assistant.agent_config_id:
            agent = await uow.agent_configs.get(assistant.agent_config_id)
            if agent is not None:
                policy = merge_config(DEFAULT_AGENT_POLICY, agent.policy)
        number = await uow.assistant_versions.latest_number(assistant.id) + 1
        version = m.AssistantVersion(
            id=ids.new_id("assistant_version"),
            tenant_id=assistant.tenant_id,
            assistant_id=assistant.id,
            version=number,
            config=config,
            agent_policy=policy,
            note=note[:500],
            created_by=actor_id,
        )
        await uow.assistant_versions.add(version)
        for entry in config.get("skills") or []:
            await uow.assistant_skills.add(
                m.AssistantSkill(
                    id=ids.new_id("assistant_skill"),
                    tenant_id=assistant.tenant_id,
                    assistant_id=assistant.id,
                    assistant_version_id=version.id,
                    skill_name=str(entry["name"]),
                    skill_version=str(entry.get("version", "")),
                    enabled=bool(entry.get("enabled", True)),
                    config=dict(entry.get("config") or {}),
                )
            )
        assistant.published_version = number
        assistant.status = "published"
        self.invalidate(assistant.tenant_id)
        return version

    async def restore(
        self, uow: UnitOfWork, assistant: m.Assistant, version_number: int
    ) -> m.AssistantVersion:
        version = await uow.assistant_versions.by_number(assistant.id, version_number)
        if version is None:
            raise AppError(ErrorCode.NOT_FOUND, "Versión no encontrada")
        assistant.draft_config = copy.deepcopy(version.config)
        return version
