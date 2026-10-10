"""Políticas de uso por tenant/assistant (CG-04.5): modelos permitidos, tokens,
gasto diario/mensual y concurrencia. Un límite excedido se rechaza y se audita."""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal

from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.kv import KeyValueStore
from webassistant.observability import metrics
from webassistant.usage.audit import AuditLog

_SLOT_TTL_SECONDS = 300


@dataclass
class EffectivePolicy:
    allowed_models: list[str]
    max_input_tokens_per_run: int | None = None
    max_output_tokens_per_call: int | None = None
    daily_budget: Decimal | None = None
    monthly_budget: Decimal | None = None
    currency: str = "USD"
    alert_threshold_pct: int = 80
    max_concurrent_runs: int | None = None
    max_concurrent_runs_per_subject: int = 1
    rate_limit_subject_per_minute: int | None = None
    rate_limit_site_per_minute: int | None = None


class PolicyService:
    def __init__(self, kv: KeyValueStore, audit: AuditLog) -> None:
        self.kv = kv
        self.audit = audit

    async def effective(self, uow: UnitOfWork, assistant_id: str | None) -> EffectivePolicy:
        rows = await uow.policies.for_assistant(assistant_id)
        # La política del assistant prevalece sobre la del tenant campo a campo.
        rows = sorted(rows, key=lambda p: p.assistant_id is not None)
        policy = EffectivePolicy(allowed_models=[])
        for row in rows:
            if row.allowed_models:
                policy.allowed_models = list(row.allowed_models)
            for attr in (
                "max_input_tokens_per_run",
                "max_output_tokens_per_call",
                "daily_budget",
                "monthly_budget",
                "max_concurrent_runs",
                "rate_limit_subject_per_minute",
                "rate_limit_site_per_minute",
            ):
                value = getattr(row, attr)
                if value is not None:
                    setattr(policy, attr, value)
            policy.currency = row.currency or policy.currency
            policy.alert_threshold_pct = row.alert_threshold_pct
            policy.max_concurrent_runs_per_subject = row.max_concurrent_runs_per_subject or 1
        return policy

    async def _reject(
        self, tenant_id: str, reason: str, code: ErrorCode, detail: str, actor: str
    ) -> AppError:
        metrics.POLICY_REJECTIONS.labels(tenant_id, reason).inc()
        await self.audit.record(
            "policy.rejected",
            tenant_id=tenant_id,
            actor_type="subject",
            actor_id=actor,
            outcome="denied",
            details={"reason": reason, "detail": detail},
        )
        return AppError(code, detail)

    async def check_before_run(
        self,
        uow: UnitOfWork,
        policy: EffectivePolicy,
        *,
        tenant_id: str,
        assistant_id: str,
        model: str,
        subject: str,
    ) -> None:
        if policy.allowed_models and model not in policy.allowed_models:
            raise await self._reject(
                tenant_id,
                "model_not_allowed",
                ErrorCode.POLICY_VIOLATION,
                f"Modelo {model} no permitido",
                subject,
            )
        now = datetime.now(UTC)
        if policy.daily_budget is not None:
            start = now.replace(hour=0, minute=0, second=0, microsecond=0)
            spent = Decimal(str(await uow.usage_events.spent_since(start)))
            if spent >= policy.daily_budget:
                raise await self._reject(
                    tenant_id,
                    "daily_budget",
                    ErrorCode.BUDGET_EXCEEDED,
                    "Presupuesto diario agotado",
                    subject,
                )
        if policy.monthly_budget is not None:
            start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
            spent = Decimal(str(await uow.usage_events.spent_since(start)))
            if spent >= policy.monthly_budget:
                raise await self._reject(
                    tenant_id,
                    "monthly_budget",
                    ErrorCode.BUDGET_EXCEEDED,
                    "Presupuesto mensual agotado",
                    subject,
                )

    @asynccontextmanager
    async def run_slot(
        self, policy: EffectivePolicy, *, tenant_id: str, subject: str
    ) -> AsyncIterator[None]:
        """Reserva concurrencia por sujeto y por tenant durante un run."""
        keys: list[tuple[str, int, str]] = [
            (
                f"conc:subject:{tenant_id}:{subject}",
                policy.max_concurrent_runs_per_subject,
                "subject_concurrency",
            )
        ]
        if policy.max_concurrent_runs:
            keys.append(
                (f"conc:tenant:{tenant_id}", policy.max_concurrent_runs, "tenant_concurrency")
            )
        acquired: list[str] = []
        try:
            for key, limit, reason in keys:
                value = await self.kv.incr(key, ttl=_SLOT_TTL_SECONDS)
                acquired.append(key)
                if limit and value > limit:
                    raise await self._reject(
                        tenant_id,
                        reason,
                        ErrorCode.CONCURRENCY_LIMIT,
                        "Límite de concurrencia alcanzado",
                        subject,
                    )
            yield
        finally:
            for key in acquired:
                await self.kv.decr(key)
