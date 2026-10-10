"""Límites de uso por site, sujeto e IP (CG-03.4) con ventanas fijas en Redis."""

from __future__ import annotations

import time
from dataclasses import dataclass

from webassistant.errors import AppError, ErrorCode
from webassistant.kv import KeyValueStore


@dataclass(frozen=True)
class Limit:
    name: str
    key: str
    limit: int
    window_seconds: int = 60


class RateLimiter:
    def __init__(self, store: KeyValueStore, prefix: str = "rl") -> None:
        self.store = store
        self.prefix = prefix

    async def hit(self, *limits: Limit) -> None:
        """Registra una petición en todas las ventanas; rechaza si alguna se supera."""
        for limit in limits:
            if limit.limit <= 0:
                continue
            bucket = int(time.time() // limit.window_seconds)
            key = f"{self.prefix}:{limit.name}:{limit.key}:{bucket}"
            count = await self.store.incr(key, ttl=limit.window_seconds + 1)
            if count > limit.limit:
                retry_after = limit.window_seconds - int(time.time() % limit.window_seconds)
                raise AppError(
                    ErrorCode.RATE_LIMITED,
                    f"Límite '{limit.name}' superado",
                    headers={"Retry-After": str(max(1, retry_after))},
                )
