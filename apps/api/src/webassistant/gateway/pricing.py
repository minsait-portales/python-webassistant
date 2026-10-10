"""Precios versionados y cálculo de coste determinista (CG-04.4, CG-12.2).

El coste distingue entrada no cacheada, entrada en caché y salida, aplica el
precio vigente en la fecha de la llamada y guarda el ``price_id`` utilizado
para poder recalcularlo de forma reproducible.
"""

from __future__ import annotations

import time
from dataclasses import dataclass
from datetime import datetime
from decimal import ROUND_HALF_UP, Decimal

from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.gateway.base import TokenUsage

MILLION = Decimal(1_000_000)
QUANT = Decimal("0.0000000001")


@dataclass(frozen=True)
class PriceSnapshot:
    id: str
    provider: str
    model: str
    input_per_million: Decimal
    cached_per_million: Decimal
    output_per_million: Decimal
    currency: str


@dataclass(frozen=True)
class Cost:
    input: Decimal
    cached: Decimal
    output: Decimal
    total: Decimal
    currency: str
    price_id: str | None


ZERO_COST = Cost(Decimal(0), Decimal(0), Decimal(0), Decimal(0), "USD", None)


def compute_cost(usage: TokenUsage, price: PriceSnapshot | None) -> Cost:
    if price is None:
        return ZERO_COST
    cached = max(0, min(usage.cached, usage.input))
    uncached = max(0, usage.input - cached)
    input_cost = (Decimal(uncached) / MILLION * price.input_per_million).quantize(
        QUANT, ROUND_HALF_UP
    )
    cached_cost = (Decimal(cached) / MILLION * price.cached_per_million).quantize(
        QUANT, ROUND_HALF_UP
    )
    output_cost = (Decimal(usage.output) / MILLION * price.output_per_million).quantize(
        QUANT, ROUND_HALF_UP
    )
    return Cost(
        input=input_cost,
        cached=cached_cost,
        output=output_cost,
        total=input_cost + cached_cost + output_cost,
        currency=price.currency,
        price_id=price.id,
    )


def snapshot(price: m.ModelPrice) -> PriceSnapshot:
    return PriceSnapshot(
        id=price.id,
        provider=price.provider,
        model=price.model,
        input_per_million=Decimal(price.input_per_million),
        cached_per_million=Decimal(price.cached_per_million),
        output_per_million=Decimal(price.output_per_million),
        currency=price.currency,
    )


class PriceResolver:
    """Resuelve el precio vigente con una caché corta para no consultar en cada llamada."""

    def __init__(self, db: Database, ttl_seconds: float = 60.0) -> None:
        self.db = db
        self.ttl = ttl_seconds
        self._cache: dict[tuple[str, str], tuple[float, PriceSnapshot | None, datetime | None]] = {}

    async def resolve(self, provider: str, model: str, at: datetime) -> PriceSnapshot | None:
        key = (provider, model)
        cached = self._cache.get(key)
        if (
            cached
            and time.monotonic() - cached[0] < self.ttl
            and cached[2] is not None
            and cached[2] <= at
        ):
            return cached[1]
        async with UnitOfWork(self.db, platform=True) as uow:
            price = await uow.model_prices.valid_at(provider, model, at)
            result = snapshot(price) if price else None
        self._cache[key] = (time.monotonic(), result, at)
        return result

    async def by_id(self, price_id: str) -> PriceSnapshot | None:
        async with UnitOfWork(self.db, platform=True) as uow:
            price = await uow.model_prices.get(price_id)
            return snapshot(price) if price else None

    def invalidate(self) -> None:
        self._cache.clear()
