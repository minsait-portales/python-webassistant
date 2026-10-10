"""Dispatcher de acciones de skills (CG-05.5, CG-06.5, CG-09.5).

Separación en tres pasos:

1. **prepare**: valida skill activa, acción declarada, permisos, run y
   parámetros (JSON Schema). Devuelve una propuesta y, si la acción requiere
   confirmación, un token de confirmación de vida corta ligado a la propuesta.
2. **execute**: exige ``Idempotency-Key`` (siempre en acciones con efectos);
   un doble clic devuelve el mismo resultado sin duplicar la operación. Las
   acciones internas se ejecutan en el servidor (sin reintentos automáticos);
   las ``host`` se delegan al widget con un ``correlation_id``.
3. **report**: el widget notifica el resultado de una host action, que queda
   unido al run.
"""

from __future__ import annotations

import hashlib
import json
from datetime import UTC, datetime, timedelta
from typing import Any

from webassistant import ids
from webassistant.contracts import format_errors, validator_for_schema
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode
from webassistant.observability import metrics
from webassistant.observability.tracing import traced
from webassistant.orchestrator.assistants import AssistantService
from webassistant.security.principal import WidgetPrincipal
from webassistant.security.scopes import Scope, has_scopes
from webassistant.security.tokens import ConfirmationClaims, TokenService
from webassistant.skills.matcher import SkillMatcher
from webassistant.usage.audit import AuditLog
from webassistant_skill_sdk import ActionContext

PROPOSAL_TTL_SECONDS = 300
HOST_ACTION_TTL_SECONDS = 120


def params_hash(params: dict[str, Any]) -> str:
    return hashlib.sha256(
        json.dumps(params, sort_keys=True, ensure_ascii=False).encode()
    ).hexdigest()


def execution_view(execution: m.ActionExecution, **extra: Any) -> dict[str, Any]:
    return {
        "execution_id": execution.id,
        "skill": execution.skill,
        "action": execution.action,
        "type": execution.type,
        "status": execution.status,
        "requires_confirmation": execution.requires_confirmation,
        "params": execution.params,
        "run_id": execution.run_id,
        "result": execution.result,
        "expires_at": execution.expires_at.isoformat() if execution.expires_at else None,
        **extra,
    }


class ActionService:
    def __init__(
        self,
        db: Database,
        assistants: AssistantService,
        matcher: SkillMatcher,
        tokens: TokenService,
        audit: AuditLog,
    ) -> None:
        self.db = db
        self.assistants = assistants
        self.matcher = matcher
        self.tokens = tokens
        self.audit = audit

    async def _resolve(
        self,
        principal: WidgetPrincipal,
        uow: UnitOfWork,
        conversation_id: str,
        skill_name: str,
        action: str,
    ) -> tuple[Any, dict[str, Any], m.Conversation]:
        conversation = await uow.conversations.first(
            m.Conversation.id == conversation_id,
            m.Conversation.site_id == principal.site.id,
            m.Conversation.subject == principal.subject,
        )
        if conversation is None or conversation.status == "deleted":
            raise AppError(ErrorCode.NOT_FOUND, "Conversación no encontrada")
        assistant = await self.assistants.published(principal.tenant_id, conversation.assistant_id)
        tenant = await uow.tenants.get(principal.tenant_id)
        match = self.matcher.match(
            assistant.skills,
            scopes=principal.scopes,
            tenant_allowed=(tenant.settings or {}).get("allowed_skills") if tenant else None,
            site_capabilities=list(principal.site.capabilities),
        )
        skill = match.get(skill_name)
        if skill is None:
            raise AppError(ErrorCode.SKILL_UNAVAILABLE, "Skill no activa para este usuario")
        definition = skill.definition.actions.get(action)
        if definition is None:
            raise AppError(ErrorCode.ACTION_UNAVAILABLE, "Acción no declarada por la skill")
        permission = definition.get("permission")
        if permission and not has_scopes(principal.scopes, [permission]):
            raise AppError(ErrorCode.FORBIDDEN, f"Falta el permiso {permission}")
        return skill, definition, conversation

    async def prepare(
        self,
        principal: WidgetPrincipal,
        *,
        conversation_id: str,
        run_id: str | None,
        skill_name: str,
        action: str,
        params: dict[str, Any],
    ) -> dict[str, Any]:
        if not has_scopes(principal.scopes, [Scope.ACTIONS_EXECUTE]):
            raise AppError(ErrorCode.FORBIDDEN, "El token no permite ejecutar acciones")
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            skill, definition, conversation = await self._resolve(
                principal, uow, conversation_id, skill_name, action
            )
            if run_id:
                run = await uow.runs.first(
                    m.Run.id == run_id, m.Run.conversation_id == conversation.id
                )
                if run is None:
                    raise AppError(ErrorCode.NOT_FOUND, "Run no encontrado")
            schema = (
                skill.definition.package.action_schemas.get(action)
                if skill.definition.package
                else None
            )
            if schema:
                errors = format_errors(list(validator_for_schema(schema).iter_errors(params)))
                if errors:
                    raise AppError(
                        ErrorCode.VALIDATION_ERROR, "Parámetros de acción no válidos", errors=errors
                    )
            requires_confirmation = definition.get("confirmation") == "required"
            execution = m.ActionExecution(
                id=ids.new_id("action_execution"),
                tenant_id=principal.tenant_id,
                site_id=principal.site.id,
                conversation_id=conversation.id,
                run_id=run_id,
                subject=principal.subject,
                skill=skill.name,
                action=action,
                type=definition["type"],
                params=params,
                params_hash=params_hash(params),
                requires_confirmation=requires_confirmation,
                status="proposed",
                expires_at=datetime.now(UTC) + timedelta(seconds=PROPOSAL_TTL_SECONDS),
            )
            await uow.action_executions.add(execution)
            await self.audit.record(
                "action.prepared",
                tenant_id=principal.tenant_id,
                actor_type="subject",
                actor_id=principal.subject,
                target_type="action",
                target_id=f"{skill.name}.{action}",
                details={
                    "execution_id": execution.id,
                    "run_id": run_id,
                    "requires_confirmation": requires_confirmation,
                },
                uow=uow,
            )
            extra: dict[str, Any] = {
                "label": definition.get("label", action),
                "description": definition.get("description", ""),
                "effects": bool(definition.get("effects", False)),
            }
            if requires_confirmation:
                token, expires = self.tokens.issue_confirmation(
                    ConfirmationClaims(
                        subject=principal.subject,
                        execution_id=execution.id,
                        params_hash=execution.params_hash,
                        tenant_id=principal.tenant_id,
                    )
                )
                extra["confirmation_token"] = token
                extra["confirmation_expires_at"] = expires.isoformat()
            metrics.ACTIONS.labels(skill.name, action, definition["type"], "proposed").inc()
            return execution_view(execution, **extra)

    @traced(
        "action.execute", lambda self, principal, execution_id, **_: {"execution_id": execution_id}
    )
    async def execute(
        self,
        principal: WidgetPrincipal,
        execution_id: str,
        *,
        idempotency_key: str | None,
        confirmation_token: str | None,
    ) -> dict[str, Any]:
        if not has_scopes(principal.scopes, [Scope.ACTIONS_EXECUTE]):
            raise AppError(ErrorCode.FORBIDDEN, "El token no permite ejecutar acciones")
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            if idempotency_key:
                previous = await uow.action_executions.by_idempotency_key(idempotency_key)
                if previous is not None:
                    if previous.id != execution_id:
                        raise AppError(
                            ErrorCode.IDEMPOTENCY_CONFLICT,
                            "La clave de idempotencia ya se usó con otra acción",
                        )
                    if previous.status in {"executed", "delegated", "succeeded", "failed"}:
                        return execution_view(
                            previous, replayed=True, host_action=self._host_instruction(previous)
                        )
            execution = await uow.action_executions.first(
                m.ActionExecution.id == execution_id, m.ActionExecution.subject == principal.subject
            )
            if execution is None:
                raise AppError(ErrorCode.NOT_FOUND, "Acción no encontrada")
            skill, definition, _ = await self._resolve(
                principal, uow, execution.conversation_id or "", execution.skill, execution.action
            )
            if definition.get("effects") and not idempotency_key:
                raise AppError(
                    ErrorCode.IDEMPOTENCY_KEY_REQUIRED,
                    "Las acciones con efectos requieren Idempotency-Key",
                )
            if execution.status != "proposed":
                if execution.idempotency_key and execution.idempotency_key == idempotency_key:
                    return execution_view(
                        execution, replayed=True, host_action=self._host_instruction(execution)
                    )
                raise AppError(
                    ErrorCode.CONFLICT, f"La acción ya está en estado {execution.status}"
                )
            if execution.expires_at and execution.expires_at < datetime.now(UTC):
                execution.status = "expired"
                raise AppError(ErrorCode.CONFIRMATION_INVALID, "La propuesta ha caducado")
            if execution.requires_confirmation:
                if not confirmation_token:
                    raise AppError(
                        ErrorCode.CONFIRMATION_REQUIRED, "Falta la confirmación del usuario"
                    )
                claims = self.tokens.decode_confirmation(confirmation_token)
                if (
                    claims.execution_id != execution.id
                    or claims.subject != principal.subject
                    or claims.params_hash != execution.params_hash
                    or claims.tenant_id != principal.tenant_id
                ):
                    raise AppError(
                        ErrorCode.CONFIRMATION_INVALID,
                        "La confirmación no corresponde a esta acción",
                    )
                execution.confirmed_at = datetime.now(UTC)
            execution.idempotency_key = idempotency_key
            execution.executed_at = datetime.now(UTC)
            await uow.flush()
            if execution.type == "internal":
                handler = skill.definition.action_handlers.get(execution.action)
                if handler is None:
                    raise AppError(ErrorCode.ACTION_UNAVAILABLE, "Acción interna sin handler")
                ctx = ActionContext(
                    tenant_id=principal.tenant_id,
                    site_id=principal.site.id,
                    subject=principal.subject,
                    scopes=principal.scopes,
                    skill=execution.skill,
                    action=execution.action,
                    execution_id=execution.id,
                    run_id=execution.run_id,
                    conversation_id=execution.conversation_id,
                )
                try:
                    outcome = await handler.execute(ctx, dict(execution.params))
                    execution.status = "succeeded" if outcome.status == "succeeded" else "failed"
                    execution.result = {
                        "status": outcome.status,
                        "data": outcome.data,
                        "message": outcome.message,
                    }
                except Exception as exc:
                    execution.status = "failed"
                    execution.error_code = ErrorCode.TOOL_FAILED.value
                    execution.result = {
                        "status": "failed",
                        "message": "La acción no se pudo completar.",
                    }
                    await self._audit_execution(
                        uow, principal, execution, outcome="failure", error=type(exc).__name__
                    )
                    metrics.ACTIONS.labels(
                        execution.skill, execution.action, execution.type, "failed"
                    ).inc()
                    return execution_view(execution)
                execution.completed_at = datetime.now(UTC)
            else:
                execution.status = "delegated"
                execution.expires_at = datetime.now(UTC) + timedelta(
                    seconds=HOST_ACTION_TTL_SECONDS
                )
            await self._audit_execution(uow, principal, execution, outcome="success")
            metrics.ACTIONS.labels(
                execution.skill, execution.action, execution.type, execution.status
            ).inc()
            return execution_view(
                execution, host_action=self._host_instruction(execution, definition)
            )

    def _host_instruction(
        self, execution: m.ActionExecution, definition: dict[str, Any] | None = None
    ) -> dict[str, Any] | None:
        if execution.type != "host" or execution.status not in {"delegated"}:
            return None
        name = (definition or {}).get("host_action") or f"{execution.skill}.{execution.action}"
        return {
            "version": "1",
            "name": name,
            "execution_id": execution.id,
            "correlation_id": execution.id,
            "skill": execution.skill,
            "action": execution.action,
            "run_id": execution.run_id,
            "params": execution.params,
            "expires_at": execution.expires_at.isoformat() if execution.expires_at else None,
        }

    async def report(
        self,
        principal: WidgetPrincipal,
        execution_id: str,
        *,
        status: str,
        result: dict[str, Any] | None,
    ) -> dict[str, Any]:
        if status not in {"succeeded", "failed"}:
            raise AppError(ErrorCode.VALIDATION_ERROR, "Estado no válido")
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            execution = await uow.action_executions.first(
                m.ActionExecution.id == execution_id, m.ActionExecution.subject == principal.subject
            )
            if execution is None or execution.type != "host":
                raise AppError(ErrorCode.NOT_FOUND, "Acción no encontrada")
            if execution.status in {"succeeded", "failed"}:
                return execution_view(execution, replayed=True)
            if execution.status != "delegated":
                raise AppError(ErrorCode.CONFLICT, f"La acción está en estado {execution.status}")
            execution.status = status
            safe_result = (
                json.loads(json.dumps(result or {}, default=str)[:20000]) if result else {}
            )
            execution.result = {"status": status, "data": safe_result}
            execution.completed_at = datetime.now(UTC)
            await self._audit_execution(
                uow, principal, execution, outcome="success" if status == "succeeded" else "failure"
            )
            metrics.ACTIONS.labels(execution.skill, execution.action, execution.type, status).inc()
            return execution_view(execution)

    async def _audit_execution(
        self,
        uow: UnitOfWork,
        principal: WidgetPrincipal,
        execution: m.ActionExecution,
        *,
        outcome: str,
        error: str | None = None,
    ) -> None:
        await self.audit.record(
            f"action.{execution.status}",
            tenant_id=principal.tenant_id,
            actor_type="subject",
            actor_id=principal.subject,
            target_type="action",
            target_id=f"{execution.skill}.{execution.action}",
            outcome=outcome,
            details={
                "execution_id": execution.id,
                "run_id": execution.run_id,
                "type": execution.type,
                "error": error,
            },
            uow=uow,
        )
