"""Registro de uso y coste por llamada real al proveedor (CG-12.1, CG-12.2).

Cada llamada (chat o embeddings), incluidas las fallidas, genera un único
``usage_event`` persistido en su propia transacción: el uso queda registrado
aunque el run termine con error.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.gateway.base import ChatResult, EmbeddingResult, TokenUsage
from webassistant.gateway.pricing import PriceResolver, compute_cost
from webassistant.observability import metrics
from webassistant.security.redaction import redact
from webassistant.usage.audit import AuditLog


@dataclass
class UsageScope:
    tenant_id: str
    site_id: str | None = None
    assistant_id: str | None = None
    conversation_id: str | None = None
    run_id: str | None = None
    subject: str | None = None
    skill: str | None = None
    iteration: int = 0
    store_payloads: bool = True


@dataclass
class UsageSummary:
    provider_calls: int = 0
    tokens: TokenUsage = field(default_factory=TokenUsage)
    total_cost: Decimal = Decimal(0)
    currency: str = "USD"
    event_ids: list[str] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        return {
            "provider_calls": self.provider_calls,
            "input_tokens": self.tokens.input,
            "cached_tokens": self.tokens.cached,
            "output_tokens": self.tokens.output,
            "reasoning_tokens": self.tokens.reasoning,
            "total_tokens": self.tokens.total,
            "total_cost": f"{self.total_cost:.10f}",
            "currency": self.currency,
        }


class UsageRecorder:
    def __init__(self, db: Database, prices: PriceResolver, audit: AuditLog) -> None:
        self.db = db
        self.prices = prices
        self.audit = audit

    async def _persist(
        self,
        scope: UsageScope,
        *,
        provider: str,
        model: str,
        operation: str,
        usage: TokenUsage,
        latency_ms: int,
        status: str = "ok",
        error_code: str | None = None,
        provider_request_id: str | None = None,
        summary: UsageSummary | None = None,
        payloads: dict[str, Any] | None = None,
    ) -> m.UsageEvent:
        now = datetime.now(UTC)
        price = await self.prices.resolve(provider, model, now)
        cost = compute_cost(usage, price)
        event = m.UsageEvent(
            id=ids.new_id("usage_event"),
            tenant_id=scope.tenant_id,
            site_id=scope.site_id,
            assistant_id=scope.assistant_id,
            conversation_id=scope.conversation_id,
            run_id=scope.run_id,
            iteration=scope.iteration,
            skill=scope.skill,
            subject=scope.subject,
            provider=provider,
            model=model,
            operation=operation,
            provider_request_id=provider_request_id,
            input_tokens=usage.input,
            cached_tokens=usage.cached,
            output_tokens=usage.output,
            reasoning_tokens=usage.reasoning,
            total_tokens=usage.total,
            latency_ms=latency_ms,
            status=status,
            error_code=error_code,
            price_id=cost.price_id,
            input_cost=cost.input,
            cached_cost=cost.cached,
            output_cost=cost.output,
            total_cost=cost.total,
            currency=cost.currency,
            created_at=now,
        )
        async with UnitOfWork(self.db, scope.tenant_id) as uow:
            await uow.usage_events.add(event)
            if payloads and scope.store_payloads:
                for kind, content in payloads.items():
                    if content:
                        await self.audit.store_payload(
                            tenant_id=scope.tenant_id,
                            kind=kind,
                            content=content,
                            run_id=scope.run_id,
                            usage_event_id=event.id,
                            uow=uow,
                        )
        metrics.PROVIDER_CALLS.labels(provider, model, operation, status).inc()
        metrics.PROVIDER_LATENCY.labels(provider, model, operation).observe(latency_ms / 1000)
        for kind, value in (
            ("input", usage.input),
            ("cached", usage.cached),
            ("output", usage.output),
            ("reasoning", usage.reasoning),
        ):
            if value:
                metrics.TOKENS.labels(scope.tenant_id, provider, model, kind).inc(value)
        if cost.total:
            metrics.COST.labels(scope.tenant_id, provider, model, cost.currency).inc(
                float(cost.total)
            )
        if summary is not None:
            summary.provider_calls += 1
            summary.tokens.add(usage)
            summary.total_cost += cost.total
            summary.currency = cost.currency
            summary.event_ids.append(event.id)
        return event

    async def record_chat(
        self,
        scope: UsageScope,
        result: ChatResult,
        *,
        summary: UsageSummary | None = None,
        system_prompt: str | None = None,
    ) -> m.UsageEvent:
        payloads = {
            "system_prompt": system_prompt,
            "provider_request": redact(result.raw_request),
            "provider_response": redact(result.raw_response),
        }
        return await self._persist(
            scope,
            provider=result.provider,
            model=result.model,
            operation="chat",
            usage=result.usage,
            latency_ms=result.latency_ms,
            provider_request_id=result.provider_request_id,
            summary=summary,
            payloads=payloads,
        )

    async def record_embedding(
        self, scope: UsageScope, result: EmbeddingResult, *, summary: UsageSummary | None = None
    ) -> m.UsageEvent:
        return await self._persist(
            scope,
            provider=result.provider,
            model=result.model,
            operation="embedding",
            usage=result.usage,
            latency_ms=result.latency_ms,
            summary=summary,
        )

    async def record_error(
        self,
        scope: UsageScope,
        *,
        provider: str,
        model: str,
        operation: str,
        error_code: str,
        latency_ms: int = 0,
        summary: UsageSummary | None = None,
    ) -> m.UsageEvent:
        return await self._persist(
            scope,
            provider=provider,
            model=model,
            operation=operation,
            usage=TokenUsage(),
            latency_ms=latency_ms,
            status="error",
            error_code=error_code,
            summary=summary,
        )
