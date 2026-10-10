"""Assistants, agentes y skills por assistant (CG-11.2, CG-11.3, CG-05.1/05.2)."""

from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Request

from webassistant import ids
from webassistant.api.routers.admin.common import (
    ConfigPublisher,
    ConfigReader,
    ConfigWriter,
    ServicesDep,
    audit_admin,
    serialize,
    tenant_access,
)
from webassistant.api.routers.admin.schemas import (
    AgentConfigBody,
    AgentConfigPatch,
    AssistantBody,
    AssistantPatch,
    PublishBody,
    RestoreBody,
)
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode, validation
from webassistant.orchestrator.assistants import (
    DEFAULT_AGENT_POLICY,
    DEFAULT_ASSISTANT_CONFIG,
    AssistantService,
    merge_config,
)
from webassistant.services.container import Services

router = APIRouter(prefix="/v1/admin/tenants/{tenant_id}", tags=["admin-assistants"])

_POLICY_LIMITS = {
    "max_iterations": (1, 10),
    "max_tool_calls": (0, 20),
    "repair_attempts": (0, 1),
    "history_window": (0, 50),
}


def _validate_policy(policy: dict[str, Any]) -> dict[str, Any]:
    merged = merge_config(DEFAULT_AGENT_POLICY, policy)
    errors = []
    for key, (low, high) in _POLICY_LIMITS.items():
        value = merged.get(key)
        if not isinstance(value, int) or not low <= value <= high:
            errors.append({"path": f"/{key}", "message": f"Debe estar entre {low} y {high}"})
    unknown_tools = set(merged.get("allowed_tools") or []) - {"search_knowledge"}
    if unknown_tools:
        errors.append(
            {"path": "/allowed_tools", "message": f"Tools no soportadas: {sorted(unknown_tools)}"}
        )
    if errors:
        raise validation("Política de agente no válida", errors)
    return policy


def _check_skills(services: Services, config: dict[str, Any]) -> list[str]:
    """No se activa una skill incompatible (CG-11.3)."""
    problems = []
    for entry in config.get("skills") or []:
        if entry.get("enabled", True) and services.registry.get(str(entry.get("name"))) is None:
            problems.append(f"Skill no disponible o incompatible: {entry.get('name')}")
    return problems


def _assistant_view(assistant: m.Assistant) -> dict[str, Any]:
    data = serialize(assistant)
    data["effective_config"] = merge_config(DEFAULT_ASSISTANT_CONFIG, assistant.draft_config)
    return data


# --- Agentes -------------------------------------------------------------------------


@router.get("/agent-configs", summary="Políticas de agente")
async def list_agents(
    tenant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        return {
            "items": [
                serialize(a) for a in await uow.agent_configs.list(order_by=m.AgentConfig.key)
            ],
            "defaults": DEFAULT_AGENT_POLICY,
        }


@router.post("/agent-configs", status_code=201, summary="Crear política de agente")
async def create_agent(
    tenant_id: str,
    body: AgentConfigBody,
    principal: ConfigWriter,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    _validate_policy(body.policy)
    async with UnitOfWork(services.db, tenant_id) as uow:
        if await uow.agent_configs.by_key(body.key):
            raise AppError(ErrorCode.CONFLICT, "Ya existe un agente con esa clave")
        agent = m.AgentConfig(
            id=ids.new_id("agent_config"),
            tenant_id=tenant_id,
            key=body.key,
            name=body.name,
            policy=body.policy,
        )
        await uow.agent_configs.add(agent)
        data = serialize(agent)
    await audit_admin(
        services,
        principal,
        "agent_config.created",
        tenant_id=tenant_id,
        target_type="agent_config",
        target_id=agent.id,
        request=request,
    )
    return data


@router.patch(
    "/agent-configs/{agent_id}",
    summary="Actualizar política de agente (efectiva al publicar el assistant)",
)
async def update_agent(
    tenant_id: str,
    agent_id: str,
    body: AgentConfigPatch,
    principal: ConfigWriter,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    if body.policy is not None:
        _validate_policy(body.policy)
    async with UnitOfWork(services.db, tenant_id) as uow:
        agent = await uow.agent_configs.require(agent_id)
        for key, value in body.model_dump(exclude_none=True).items():
            setattr(agent, key, value)
        data = serialize(agent)
    await audit_admin(
        services,
        principal,
        "agent_config.updated",
        tenant_id=tenant_id,
        target_type="agent_config",
        target_id=agent_id,
        request=request,
    )
    return data


# --- Assistants ----------------------------------------------------------------------


@router.get("/assistants", summary="Assistants del tenant")
async def list_assistants(
    tenant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        return {
            "items": [
                _assistant_view(a) for a in await uow.assistants.list(order_by=m.Assistant.key)
            ],
            "defaults": DEFAULT_ASSISTANT_CONFIG,
        }


@router.get("/assistants/{assistant_id}", summary="Detalle de un assistant con sus versiones")
async def get_assistant(
    tenant_id: str, assistant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        assistant = await uow.assistants.require(assistant_id)
        versions = await uow.assistant_versions.list(
            m.AssistantVersion.assistant_id == assistant.id,
            order_by=m.AssistantVersion.version.desc(),
        )
        return {
            **_assistant_view(assistant),
            "versions": [serialize(v, exclude={"config", "agent_policy"}) for v in versions],
        }


@router.post("/assistants", status_code=201, summary="Crear assistant (borrador)")
async def create_assistant(
    tenant_id: str,
    body: AssistantBody,
    principal: ConfigWriter,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    AssistantService.validate_config(body.config)
    problems = _check_skills(services, body.config)
    if problems:
        raise validation(
            "Skills no válidas", [{"path": "/config/skills", "message": p} for p in problems]
        )
    async with UnitOfWork(services.db, tenant_id) as uow:
        if await uow.assistants.by_key(body.key):
            raise AppError(ErrorCode.CONFLICT, "Ya existe un assistant con esa clave")
        agent_id = None
        if body.agent_config:
            agent = await uow.agent_configs.by_key(
                body.agent_config
            ) or await uow.agent_configs.get(body.agent_config)
            if agent is None:
                raise AppError(ErrorCode.VALIDATION_ERROR, "Agente desconocido")
            agent_id = agent.id
        assistant = m.Assistant(
            id=ids.new_id("assistant"),
            tenant_id=tenant_id,
            key=body.key,
            name=body.name,
            draft_config=body.config,
            agent_config_id=agent_id,
        )
        await uow.assistants.add(assistant)
        data = _assistant_view(assistant)
    await audit_admin(
        services,
        principal,
        "assistant.created",
        tenant_id=tenant_id,
        target_type="assistant",
        target_id=assistant.id,
        request=request,
    )
    return data


@router.patch("/assistants/{assistant_id}", summary="Editar el borrador del assistant")
async def update_assistant(
    tenant_id: str,
    assistant_id: str,
    body: AssistantPatch,
    principal: ConfigWriter,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    if body.config is not None:
        AssistantService.validate_config(body.config)
        problems = _check_skills(services, body.config)
        if problems:
            raise validation(
                "Skills no válidas", [{"path": "/config/skills", "message": p} for p in problems]
            )
    async with UnitOfWork(services.db, tenant_id) as uow:
        assistant = await uow.assistants.require(assistant_id)
        if body.name is not None:
            assistant.name = body.name
        if body.config is not None:
            assistant.draft_config = body.config
        if body.status == "archived":
            assistant.status = "archived"
        if body.agent_config is not None:
            agent = await uow.agent_configs.by_key(
                body.agent_config
            ) or await uow.agent_configs.get(body.agent_config)
            if agent is None:
                raise AppError(ErrorCode.VALIDATION_ERROR, "Agente desconocido")
            assistant.agent_config_id = agent.id
        data = _assistant_view(assistant)
    services.assistants.invalidate(tenant_id)
    await audit_admin(
        services,
        principal,
        "assistant.draft_updated",
        tenant_id=tenant_id,
        target_type="assistant",
        target_id=assistant_id,
        request=request,
    )
    return data


@router.post(
    "/assistants/{assistant_id}/publish", summary="Publicar el borrador como nueva versión"
)
async def publish_assistant(
    tenant_id: str,
    assistant_id: str,
    body: PublishBody,
    principal: ConfigPublisher,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        assistant = await uow.assistants.require(assistant_id)
        problems = _check_skills(services, assistant.draft_config)
        if problems:
            raise validation(
                "No se puede publicar con skills incompatibles",
                [{"path": "/config/skills", "message": p} for p in problems],
            )
        version = await services.assistants.publish(
            uow, assistant, actor_id=principal.user_id, note=body.note
        )
        data = serialize(version, exclude={"config", "agent_policy"})
    await audit_admin(
        services,
        principal,
        "assistant.published",
        tenant_id=tenant_id,
        target_type="assistant",
        target_id=assistant_id,
        details={"version": data["version"]},
        request=request,
    )
    return data


@router.post(
    "/assistants/{assistant_id}/restore",
    summary="Restaurar una versión al borrador (y opcionalmente publicarla)",
)
async def restore_assistant(
    tenant_id: str,
    assistant_id: str,
    body: RestoreBody,
    principal: ConfigPublisher,
    services: ServicesDep,
    request: Request,
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        assistant = await uow.assistants.require(assistant_id)
        await services.assistants.restore(uow, assistant, body.version)
        published = None
        if body.publish:
            published = await services.assistants.publish(
                uow, assistant, actor_id=principal.user_id, note=f"restaurada v{body.version}"
            )
        data = {
            **_assistant_view(assistant),
            "published_version_created": published.version if published else None,
        }
    await audit_admin(
        services,
        principal,
        "assistant.restored",
        tenant_id=tenant_id,
        target_type="assistant",
        target_id=assistant_id,
        details={"version": body.version, "publish": body.publish},
        request=request,
    )
    return data


@router.get(
    "/assistants/{assistant_id}/versions/{version}",
    summary="Configuración congelada de una versión",
)
async def get_version(
    tenant_id: str, assistant_id: str, version: int, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    async with UnitOfWork(services.db, tenant_id) as uow:
        row = await uow.assistant_versions.by_number(assistant_id, version)
        if row is None:
            raise AppError(ErrorCode.NOT_FOUND, "Versión no encontrada")
        skills = await uow.assistant_skills.list(m.AssistantSkill.assistant_version_id == row.id)
        return {**serialize(row), "skills": [serialize(s) for s in skills]}


@router.get(
    "/assistants/{assistant_id}/skills",
    summary="Skills de la versión publicada y su compatibilidad",
)
async def assistant_skills(
    tenant_id: str, assistant_id: str, principal: ConfigReader, services: ServicesDep
) -> dict[str, Any]:
    tenant_access(principal, tenant_id)
    published = await services.assistants.published(tenant_id, assistant_id)
    items = []
    for entry in published.skills:
        definition = services.registry.get(entry["name"])
        items.append(
            {
                **entry,
                "available": definition is not None,
                "version": definition.version if definition else None,
                "permissions": definition.permissions if definition else [],
                "capabilities": definition.required_capabilities if definition else [],
            }
        )
    return {"assistant_version": published.version, "items": items}
