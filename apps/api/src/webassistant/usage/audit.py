"""Registro de auditoría (CG-12.3, CG-12.4).

Los detalles se redactan antes de persistir. Los payloads técnicos completos
(prompt de sistema, petición/respuesta del proveedor) se guardan cifrados en
``audit_payloads`` con retención propia y acceso restringido a auditoría.
"""

from __future__ import annotations

import json
from datetime import UTC, datetime, timedelta
from typing import Any

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.observability import context
from webassistant.observability.logging import get_logger
from webassistant.security.crypto import PayloadCipher
from webassistant.security.redaction import redact, redact_text, truncate

logger = get_logger("webassistant.audit")


class AuditLog:
    def __init__(
        self, db: Database, cipher: PayloadCipher, *, payload_retention_days: int = 30
    ) -> None:
        self.db = db
        self.cipher = cipher
        self.payload_retention_days = payload_retention_days

    async def record(
        self,
        action: str,
        *,
        tenant_id: str | None,
        actor_type: str,
        actor_id: str,
        target_type: str = "",
        target_id: str = "",
        outcome: str = "success",
        details: dict[str, Any] | None = None,
        ip: str | None = None,
        uow: UnitOfWork | None = None,
    ) -> None:
        event = m.AuditEvent(
            id=ids.new_id("audit_event"),
            tenant_id=tenant_id,
            actor_type=actor_type,
            actor_id=actor_id[:128],
            action=action,
            target_type=target_type,
            target_id=target_id[:128],
            outcome=outcome,
            correlation_id=context.get("correlation_id"),
            run_id=context.get("run_id"),
            conversation_id=context.get("conversation_id"),
            details=redact(details or {}),
            ip=ip,
        )
        logger.info(
            "audit %s %s", action, outcome, extra={"audit_target": f"{target_type}:{target_id}"}
        )
        if uow is not None:
            await uow.audit_events.add(event)
            return
        async with UnitOfWork(self.db, tenant_id, platform=tenant_id is None) as own:
            await own.audit_events.add(event)

    async def store_payload(
        self,
        *,
        tenant_id: str,
        kind: str,
        content: Any,
        run_id: str | None = None,
        usage_event_id: str | None = None,
        uow: UnitOfWork | None = None,
    ) -> None:
        text = (
            content
            if isinstance(content, str)
            else json.dumps(content, ensure_ascii=False, default=str)
        )
        payload = m.AuditPayload(
            id=ids.new_id("audit_payload"),
            tenant_id=tenant_id,
            run_id=run_id,
            usage_event_id=usage_event_id,
            kind=kind,
            content_encrypted=self.cipher.encrypt(text),
            redacted_preview=truncate(redact_text(text), 1500),
            expires_at=datetime.now(UTC) + timedelta(days=self.payload_retention_days),
        )
        if uow is not None:
            await uow.audit_payloads.add(payload)
            return
        async with UnitOfWork(self.db, tenant_id) as own:
            await own.audit_payloads.add(payload)

    def decrypt(self, payload: m.AuditPayload) -> str | None:
        return self.cipher.decrypt(payload.content_encrypted)
