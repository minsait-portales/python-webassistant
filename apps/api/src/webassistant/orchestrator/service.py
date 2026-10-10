"""Servicio de conversación: prepara, ejecuta y persiste un run (CG-05.3, CG-08, CG-09.3/09.4).

Un mismo flujo sirve la respuesta JSON completa y el streaming SSE: el run se
ejecuta en una tarea que publica eventos (``run.started``, ``run.status``,
``reply.delta``, ``tool.*``, ``run.completed|failed|cancelled``) y el cliente
puede reconectar con ``Last-Event-ID`` o cancelar el run.
"""

from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

from webassistant import API_VERSION, ids
from webassistant.conversations.context import build_context
from webassistant.conversations.service import ConversationService
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode
from webassistant.gateway.base import CancelToken
from webassistant.gateway.policies import PolicyService
from webassistant.kv import KeyValueStore
from webassistant.observability import context as obs_context
from webassistant.observability import metrics
from webassistant.observability.logging import get_logger
from webassistant.orchestrator.agent import AgentInput, AgentOutcome, AgentRunner
from webassistant.orchestrator.assistants import AssistantService, PublishedAssistant
from webassistant.orchestrator.events import RunEventBus
from webassistant.security.principal import WidgetPrincipal
from webassistant.security.scopes import Scope, has_scopes
from webassistant.skills.matcher import SkillMatcher
from webassistant.skills.prompts import collect_selection_items
from webassistant.text import limit_text
from webassistant.usage.audit import AuditLog
from webassistant.usage.recorder import UsageSummary

logger = get_logger("webassistant.chat")


@dataclass
class PreparedRun:
    run_id: str
    conversation: m.Conversation
    assistant: PublishedAssistant
    agent_input: AgentInput
    started: float
    policy_slot: Any = None


class ChatService:
    def __init__(
        self,
        db: Database,
        kv: KeyValueStore,
        assistants: AssistantService,
        conversations: ConversationService,
        matcher: SkillMatcher,
        agent: AgentRunner,
        policies: PolicyService,
        events: RunEventBus,
        audit: AuditLog,
    ) -> None:
        self.db = db
        self.kv = kv
        self.assistants = assistants
        self.conversations = conversations
        self.matcher = matcher
        self.agent = agent
        self.policies = policies
        self.events = events
        self.audit = audit
        self.cancel_tokens: dict[str, CancelToken] = {}
        self.tasks: set[asyncio.Task[Any]] = set()

    # --- Preparación --------------------------------------------------------------

    async def prepare(
        self, principal: WidgetPrincipal, conversation_id: str, message: str
    ) -> PreparedRun:
        if not has_scopes(principal.scopes, [Scope.CHAT_USE]):
            raise AppError(ErrorCode.FORBIDDEN, "El token no permite usar el chat")
        message = message.strip()
        if not message:
            raise AppError(ErrorCode.VALIDATION_ERROR, "El mensaje no puede estar vacío")
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            conversation = await self.conversations.get_owned(uow, principal, conversation_id)
            if conversation.status != "active":
                raise AppError(
                    ErrorCode.CONFLICT, "La conversación está archivada; inicia una nueva"
                )
            assistant = await self.assistants.published(
                principal.tenant_id, conversation.assistant_id
            )
            max_length = int(assistant.limits.get("max_message_length", 800))
            if len(message) > max_length:
                raise AppError(
                    ErrorCode.MESSAGE_TOO_LONG,
                    f"El mensaje supera {max_length} caracteres",
                    user_message=f"La consulta no puede superar los {max_length} caracteres.",
                )
            policy = await self.policies.effective(uow, assistant.assistant_id)
            await self.policies.check_before_run(
                uow,
                policy,
                tenant_id=principal.tenant_id,
                assistant_id=assistant.assistant_id,
                model=assistant.model_id,
                subject=principal.subject,
            )
            tenant = await uow.tenants.get(principal.tenant_id)
            tenant_allowed = (tenant.settings or {}).get("allowed_skills") if tenant else None
            match = self.matcher.match(
                assistant.skills,
                scopes=principal.scopes,
                tenant_allowed=tenant_allowed,
                site_capabilities=list(principal.site.capabilities),
            )
            if not match.active:
                raise AppError(
                    ErrorCode.SKILL_UNAVAILABLE, "No hay skills disponibles para este usuario"
                )
            kb_keys = list(assistant.config.get("knowledge_bases") or [])
            kbs = (
                await uow.knowledge_bases.list(
                    m.KnowledgeBase.key.in_(kb_keys), m.KnowledgeBase.status == "active"
                )
                if kb_keys
                else []
            )
            kb_ids = tuple(kb.id for kb in kbs if kb.site_id in (None, principal.site.id))
            window = int(
                assistant.agent_policy.get("history_window")
                or assistant.limits.get("max_history_messages", 8)
            )
            history_rows = list(
                await uow.messages.list(
                    m.Message.conversation_id == conversation.id,
                    m.Message.role.in_(["user", "assistant"]),
                    order_by=m.Message.created_at.desc(),
                    limit=max(window * 3, 20),
                )
            )
            history_rows.reverse()
            strategy = str((assistant.config.get("history") or {}).get("strategy", "window"))
            window_ctx = build_context(
                history_rows,
                window_messages=window,
                max_tokens=int(assistant.limits.get("max_context_tokens", 24000)) // 2,
                strategy=strategy,
                existing_summary=conversation.summary,
            )
            run_id = ids.new_id("run")
            run = m.Run(
                id=run_id,
                tenant_id=principal.tenant_id,
                site_id=principal.site.id,
                conversation_id=conversation.id,
                assistant_id=assistant.assistant_id,
                assistant_version=assistant.version,
                subject=principal.subject,
                status="running",
                trace_id=obs_context.get("trace_id"),
                context_report={
                    **window_ctx.report,
                    "skills_offered": match.names,
                    "skills_denied": [{"name": d.name, "reason": d.reason} for d in match.denied],
                    "knowledge_bases": list(kb_ids),
                    "selection_items": len(
                        ((conversation.context_state or {}).get("selection") or {}).get("items", [])
                    ),
                },
            )
            await uow.runs.add(run)
            await uow.messages.add(
                m.Message(
                    id=ids.new_id("message"),
                    tenant_id=principal.tenant_id,
                    site_id=principal.site.id,
                    conversation_id=conversation.id,
                    run_id=run_id,
                    role="user",
                    content=message,
                )
            )
            if not conversation.title:
                conversation.title = limit_text(message, 80)
            conversation.last_message_at = datetime.now(UTC)
            if window_ctx.summary:
                conversation.summary = window_ctx.summary
        agent_input = AgentInput(
            run_id=run_id,
            conversation_id=conversation.id,
            tenant_id=principal.tenant_id,
            site_id=principal.site.id,
            subject=principal.subject,
            subject_type=principal.claims.subject_type,
            scopes=principal.scopes,
            principals=principal.claims.acl_principals,
            capabilities=frozenset(principal.site.capabilities),
            locale=conversation.locale or principal.site.default_locale,
            message=message,
            history=window_ctx.messages,
            conversation_summary=window_ctx.summary,
            selection=(conversation.context_state or {}).get("selection"),
            assistant=assistant,
            match=match,
            knowledge_base_ids=kb_ids,
            max_output_tokens=min(
                int(assistant.limits.get("max_output_tokens", 2000)),
                policy.max_output_tokens_per_call or 64000,
            ),
        )
        prepared = PreparedRun(run_id, conversation, assistant, agent_input, time.perf_counter())
        prepared.policy_slot = self.policies.run_slot(
            policy, tenant_id=principal.tenant_id, subject=principal.subject
        )
        return prepared

    # --- Ejecución ------------------------------------------------------------------

    async def _watch_remote_cancel(self, run_id: str, token: CancelToken) -> None:
        while not token.cancelled:
            if await self.kv.get(f"run:{run_id}:cancel"):
                token.cancel()
                return
            await asyncio.sleep(0.5)

    async def execute(self, prepared: PreparedRun, *, stream: bool) -> dict[str, Any]:
        run_id = prepared.run_id
        data = prepared.agent_input
        token = CancelToken()
        self.cancel_tokens[run_id] = token
        watcher = asyncio.create_task(self._watch_remote_cancel(run_id, token))
        summary = UsageSummary()
        metrics.RUNS_IN_PROGRESS.inc()
        await self.events.publish(run_id, "run.started", conversation_id=data.conversation_id)

        async def on_delta(text: str) -> None:
            await self.events.publish(run_id, "reply.delta", delta=text)

        async def on_status(status: str, info: dict[str, Any]) -> None:
            await self.events.publish(run_id, "run.status", status=status, **info)

        try:
            with obs_context.bound(
                tenant_id=data.tenant_id,
                site_id=data.site_id,
                conversation_id=data.conversation_id,
                run_id=run_id,
                assistant_id=data.assistant.assistant_id,
            ):
                async with prepared.policy_slot:
                    outcome = await self.agent.run(
                        data,
                        cancel=token,
                        summary=summary,
                        on_delta=on_delta if stream else None,
                        on_status=on_status,
                    )
                response = await self._complete(prepared, outcome, summary)
            await self.events.publish(run_id, "run.completed", response=response)
            return response
        except AppError as exc:
            status = "cancelled" if exc.code == ErrorCode.RUN_CANCELLED else "failed"
            message = exc.resolved_user_message(prepared.assistant.messages.get("errors") or {})
            await self._fail(prepared, exc.code.value, message, status, summary)
            problem = exc.to_problem(correlation_id=obs_context.get("correlation_id"))
            problem["user_message"] = message
            await self.events.publish(run_id, f"run.{status}", error=problem)
            raise
        except Exception as exc:
            logger.exception("Fallo inesperado en el run %s", run_id)
            app_error = AppError(ErrorCode.INTERNAL_ERROR, type(exc).__name__)
            message = app_error.resolved_user_message(
                prepared.assistant.messages.get("errors") or {}
            )
            await self._fail(prepared, ErrorCode.INTERNAL_ERROR.value, message, "failed", summary)
            await self.events.publish(run_id, "run.failed", error=app_error.to_problem())
            raise app_error from exc
        finally:
            watcher.cancel()
            self.cancel_tokens.pop(run_id, None)
            metrics.RUNS_IN_PROGRESS.dec()
            metrics.RUN_LATENCY.labels(data.tenant_id, data.assistant.key).observe(
                time.perf_counter() - prepared.started
            )

    def start_background(self, prepared: PreparedRun) -> None:
        async def runner() -> None:
            try:
                await self.execute(prepared, stream=True)
            except AppError:
                pass
            except Exception:
                logger.exception("Run en segundo plano fallido")

        task = asyncio.create_task(runner())
        self.tasks.add(task)
        task.add_done_callback(self.tasks.discard)

    async def cancel(
        self, principal: WidgetPrincipal, conversation_id: str, run_id: str
    ) -> dict[str, Any]:
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            conversation = await self.conversations.get_owned(uow, principal, conversation_id)
            run = await uow.runs.first(m.Run.id == run_id, m.Run.conversation_id == conversation.id)
            if run is None:
                raise AppError(ErrorCode.NOT_FOUND, "Run no encontrado")
            status = run.status
        if status == "running":
            token = self.cancel_tokens.get(run_id)
            if token is not None:
                token.cancel()
            await self.kv.set(f"run:{run_id}:cancel", "1", ttl=600)
        return {"run_id": run_id, "status": "cancelling" if status == "running" else status}

    async def run_owned(
        self, principal: WidgetPrincipal, conversation_id: str, run_id: str
    ) -> m.Run:
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            conversation = await self.conversations.get_owned(uow, principal, conversation_id)
            run = await uow.runs.first(m.Run.id == run_id, m.Run.conversation_id == conversation.id)
            if run is None:
                raise AppError(ErrorCode.NOT_FOUND, "Run no encontrado")
            return run

    # --- Persistencia ---------------------------------------------------------------

    async def _complete(
        self, prepared: PreparedRun, outcome: AgentOutcome, summary: UsageSummary
    ) -> dict[str, Any]:
        data = prepared.agent_input
        built = outcome.built
        latency_ms = int((time.perf_counter() - prepared.started) * 1000)
        message_id = ids.new_id("message")
        visible = {
            "presentation": built.presentation,
            "actions": built.actions,
            "citations": built.citations,
            "keep_presentation": built.keep_presentation,
            "skill": None if built.out_of_scope else built.skill,
        }
        async with UnitOfWork(self.db, data.tenant_id) as uow:
            await uow.messages.add(
                m.Message(
                    id=message_id,
                    tenant_id=data.tenant_id,
                    site_id=data.site_id,
                    conversation_id=data.conversation_id,
                    run_id=prepared.run_id,
                    role="assistant",
                    content=built.chat_reply,
                    presentation=visible,
                )
            )
            run = await uow.runs.require(prepared.run_id)
            run.status = "completed"
            run.skill = None if built.out_of_scope else built.skill
            run.error_code = outcome.error_code
            run.iterations = outcome.iterations
            run.tool_calls_count = outcome.tool_calls
            run.rag_used = outcome.rag_used
            run.repaired = outcome.repaired
            run.structured_result = built.structured_result
            run.context_report = {
                **run.context_report,
                "processing": built.report.as_dict(),
                "fallback": built.fallback_used,
            }
            run.usage_summary = summary.as_dict()
            run.latency_ms = latency_ms
            run.finished_at = datetime.now(UTC)
            conversation = await uow.conversations.require(data.conversation_id)
            state = dict(conversation.context_state or {})
            if built.presentation is not None and not built.keep_presentation:
                result = (
                    built.structured_result.get("result")
                    if isinstance(built.structured_result.get("result"), dict)
                    else {}
                )
                items = collect_selection_items(result or {})
                processed_ids = {
                    str(i.get("product_id"))
                    for i in (built.presentation.get("data", {}).get("items") or [])
                    + [
                        x
                        for c in built.presentation.get("data", {}).get("categories") or []
                        for x in c.get("items", [])
                    ]
                }
                items = [i for i in items if i["product_id"] in processed_ids]
                state["selection"] = {"skill": built.skill, "items": items} if items else None
                state["last_presentation"] = {
                    "skill": built.skill,
                    "type": built.presentation["type"],
                }
            conversation.context_state = state
            conversation.last_message_at = datetime.now(UTC)
        metrics.RUNS.labels(
            data.tenant_id, data.assistant.key, built.skill or "none", "completed"
        ).inc()
        return {
            "api_version": API_VERSION,
            "conversation_id": data.conversation_id,
            "run_id": prepared.run_id,
            "message_id": message_id,
            "skill": None if built.out_of_scope else built.skill,
            "reply": built.chat_reply,
            "presentation": built.presentation,
            "keep_presentation": built.keep_presentation,
            "actions": built.actions,
            "citations": built.citations,
            "responsive_button_label": built.responsive_button_label,
            "usage": summary.as_dict(),
        }

    async def _fail(
        self, prepared: PreparedRun, code: str, message: str, status: str, summary: UsageSummary
    ) -> None:
        data = prepared.agent_input
        try:
            async with UnitOfWork(self.db, data.tenant_id) as uow:
                run = await uow.runs.require(prepared.run_id)
                run.status = status
                run.error_code = code
                run.usage_summary = summary.as_dict()
                run.latency_ms = int((time.perf_counter() - prepared.started) * 1000)
                run.finished_at = datetime.now(UTC)
                await uow.messages.add(
                    m.Message(
                        id=ids.new_id("message"),
                        tenant_id=data.tenant_id,
                        site_id=data.site_id,
                        conversation_id=data.conversation_id,
                        run_id=prepared.run_id,
                        role="assistant",
                        content=message,
                        presentation={"error_code": code},
                    )
                )
        except Exception:
            logger.exception("No se pudo registrar el fallo del run %s", prepared.run_id)
        metrics.RUNS.labels(data.tenant_id, data.assistant.key, "none", status).inc()
        await self.audit.record(
            f"run.{status}",
            tenant_id=data.tenant_id,
            actor_type="subject",
            actor_id=data.subject,
            target_type="run",
            target_id=prepared.run_id,
            outcome="failure" if status == "failed" else "cancelled",
            details={"error_code": code},
        )
