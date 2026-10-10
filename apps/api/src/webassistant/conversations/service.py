"""Conversaciones, historial y memoria persistidos (CG-08).

- Una conversación queda ligada a tenant, site, assistant y sujeto (usuario
  autenticado o sesión anónima); desde otro contexto no puede recuperarse.
- El historial visible guarda el texto del usuario y la respuesta breve; el
  payload técnico (resultado estructurado, tools) vive en ``runs``/``tool_calls``
  y nunca se expone en el historial.
- Reset archiva y abre otra conversación; el borrado por privacidad elimina
  mensajes, runs y payloads, y anonimiza los eventos de uso.
"""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from sqlalchemy import select

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode
from webassistant.security.principal import WidgetPrincipal


def conversation_view(conversation: m.Conversation) -> dict[str, Any]:
    return {
        "conversation_id": conversation.id,
        "assistant_id": conversation.assistant_id,
        "status": conversation.status,
        "locale": conversation.locale,
        "title": conversation.title,
        "created_at": conversation.created_at.isoformat(),
        "last_message_at": conversation.last_message_at.isoformat()
        if conversation.last_message_at
        else None,
    }


def message_view(message: m.Message) -> dict[str, Any]:
    data: dict[str, Any] = {
        "message_id": message.id,
        "role": message.role,
        "content": message.content,
        "run_id": message.run_id,
        "created_at": message.created_at.isoformat(),
    }
    if message.presentation:
        data.update(
            {
                "presentation": message.presentation.get("presentation"),
                "actions": message.presentation.get("actions", []),
                "citations": message.presentation.get("citations", []),
                "keep_presentation": message.presentation.get("keep_presentation", False),
                "skill": message.presentation.get("skill"),
            }
        )
    return data


class ConversationService:
    def __init__(self, db: Database) -> None:
        self.db = db

    async def create(
        self, principal: WidgetPrincipal, assistant_id: str, locale: str
    ) -> m.Conversation:
        conversation = m.Conversation(
            id=ids.new_id("conversation"),
            tenant_id=principal.tenant_id,
            site_id=principal.site.id,
            assistant_id=assistant_id,
            subject=principal.subject,
            subject_type=principal.claims.subject_type,
            locale=locale,
            status="active",
        )
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            await uow.conversations.add(conversation)
        return conversation

    @staticmethod
    async def get_owned(
        uow: UnitOfWork, principal: WidgetPrincipal, conversation_id: str
    ) -> m.Conversation:
        conversation = await uow.conversations.first(
            m.Conversation.id == conversation_id,
            m.Conversation.site_id == principal.site.id,
            m.Conversation.subject == principal.subject,
        )
        if conversation is None or conversation.status == "deleted":
            # Mismo error que una conversación inexistente: no se filtra su existencia.
            raise AppError(ErrorCode.NOT_FOUND, "Conversación no encontrada")
        return conversation

    async def list_for(
        self, principal: WidgetPrincipal, *, limit: int = 20
    ) -> list[dict[str, Any]]:
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            rows = await uow.conversations.list(
                m.Conversation.site_id == principal.site.id,
                m.Conversation.subject == principal.subject,
                m.Conversation.status.in_(["active", "archived"]),
                order_by=m.Conversation.updated_at.desc(),
                limit=limit,
            )
            return [conversation_view(c) for c in rows]

    async def history(
        self, principal: WidgetPrincipal, conversation_id: str, *, limit: int = 100
    ) -> dict[str, Any]:
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            conversation = await self.get_owned(uow, principal, conversation_id)
            messages = await uow.messages.list(
                m.Message.conversation_id == conversation.id,
                m.Message.role.in_(["user", "assistant"]),
                order_by=m.Message.created_at.desc(),
                limit=limit,
            )
            return {
                **conversation_view(conversation),
                "messages": [message_view(x) for x in reversed(messages)],
            }

    async def reset(
        self,
        principal: WidgetPrincipal,
        conversation_id: str | None,
        assistant_id: str,
        locale: str,
    ) -> m.Conversation:
        if conversation_id:
            async with UnitOfWork(self.db, principal.tenant_id) as uow:
                conversation = await self.get_owned(uow, principal, conversation_id)
                conversation.status = "archived"
                conversation.archived_at = datetime.now(UTC)
        return await self.create(principal, assistant_id, locale)

    async def delete(self, principal: WidgetPrincipal, conversation_id: str) -> None:
        async with UnitOfWork(self.db, principal.tenant_id) as uow:
            conversation = await self.get_owned(uow, principal, conversation_id)
            await purge_conversation(uow, conversation)

    async def link_anonymous(
        self, tenant_id: str, site_id: str, anonymous_subject: str, user_subject: str
    ) -> int:
        """Une las conversaciones anónimas de la sesión a la cuenta al iniciar sesión (CG-08.5)."""
        async with UnitOfWork(self.db, tenant_id) as uow:
            return await uow.conversations.update_where(
                {"subject": user_subject, "subject_type": "user"},
                m.Conversation.site_id == site_id,
                m.Conversation.subject == anonymous_subject,
                m.Conversation.status != "deleted",
            )


async def purge_conversation(uow: UnitOfWork, conversation: m.Conversation) -> None:
    """Borrado por privacidad: elimina contenido y conserva métricas anonimizadas."""
    run_ids = list(
        (
            await uow.session.execute(
                select(m.Run.id).where(m.Run.conversation_id == conversation.id)
            )
        ).scalars()
    )
    if run_ids:
        await uow.tool_calls.delete_where(m.ToolCall.run_id.in_(run_ids))
        await uow.audit_payloads.delete_where(m.AuditPayload.run_id.in_(run_ids))
        await uow.usage_events.update_where(
            {"conversation_id": None, "subject": None}, m.UsageEvent.run_id.in_(run_ids)
        )
    await uow.action_executions.update_where(
        {"params": {}, "result": None, "subject": "deleted"},
        m.ActionExecution.conversation_id == conversation.id,
    )
    await uow.messages.delete_where(m.Message.conversation_id == conversation.id)
    await uow.runs.update_where(
        {"structured_result": None, "context_report": {}, "subject": ""},
        m.Run.conversation_id == conversation.id,
    )
    conversation.status = "deleted"
    conversation.subject = "deleted:" + conversation.id
    conversation.title = ""
    conversation.summary = ""
    conversation.context_state = {}
