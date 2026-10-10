"""Política de retención configurable por tenant (CG-02.5, CG-12.4).

- Conversaciones inactivas más allá de ``conversations_days``: se borran o se
  anonimizan (modo ``anonymize``: se elimina el contenido y se conservan
  métricas sin sujeto).
- Payloads técnicos cifrados: retención propia (``payloads_days``), más corta.
- Auditoría y eventos de uso: retención distinta a la de las métricas.
Cada ejecución deja un evento de auditoría con los conteos (trazabilidad).
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta
from typing import Any

from webassistant.conversations.service import purge_conversation
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.usage.audit import AuditLog


class RetentionService:
    def __init__(self, db: Database, audit: AuditLog) -> None:
        self.db = db
        self.audit = audit

    async def apply(self, tenant_id: str | None = None) -> list[dict[str, Any]]:
        async with UnitOfWork(self.db, platform=True) as uow:
            tenants = [tenant_id] if tenant_id else [t.id for t in await uow.tenants.list()]
        return [await self.apply_tenant(tid) for tid in tenants]

    async def apply_tenant(self, tenant_id: str) -> dict[str, Any]:
        now = datetime.now(UTC)
        async with UnitOfWork(self.db, tenant_id) as uow:
            policy = await uow.retention_policies.first()
            if policy is None:
                policy = m.RetentionPolicy(id=f"ret_{tenant_id}"[:48], tenant_id=tenant_id)
                await uow.retention_policies.add(policy)
            conversations_cutoff = now - timedelta(days=policy.conversations_days)
            stale = await uow.conversations.list(
                m.Conversation.status != "deleted",
                m.Conversation.updated_at < conversations_cutoff,
            )
            for conversation in stale:
                if policy.mode == "delete":
                    await uow.messages.delete_where(m.Message.conversation_id == conversation.id)
                    await uow.runs.delete_where(m.Run.conversation_id == conversation.id)
                    await uow.conversations.remove(conversation)
                else:
                    await purge_conversation(uow, conversation)
            payloads = await uow.audit_payloads.delete_where(
                m.AuditPayload.created_at < now - timedelta(days=policy.payloads_days)
            )
            audit = await uow.audit_events.delete_where(
                m.AuditEvent.created_at < now - timedelta(days=policy.audit_days)
            )
            usage = await uow.usage_events.delete_where(
                m.UsageEvent.created_at < now - timedelta(days=policy.usage_days)
            )
            expired_actions = await uow.action_executions.update_where(
                {"params": {}, "result": None},
                m.ActionExecution.created_at < conversations_cutoff,
            )
            policy.last_applied_at = now
            stats = {
                "tenant_id": tenant_id,
                "mode": policy.mode,
                "conversations": len(stale),
                "payloads": payloads,
                "audit_events": audit,
                "usage_events": usage,
                "actions_redacted": expired_actions,
            }
        await self.audit.record(
            "retention.applied",
            tenant_id=tenant_id,
            actor_type="system",
            actor_id="retention",
            details=stats,
        )
        return stats
