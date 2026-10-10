"""Resiliencia frente al proveedor (CG-04.3): timeouts, cancelación, backoff limitado y circuit breaker.

Solo se reintentan operaciones sin efectos (llamadas de modelo y embeddings).
Las acciones no idempotentes nunca pasan por aquí.
"""

from __future__ import annotations

import asyncio
import random
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field
from typing import TypeVar

from webassistant.errors import AppError, ErrorCode
from webassistant.gateway.base import CancelToken, ProviderError

T = TypeVar("T")

_RETRYABLE = {
    ErrorCode.PROVIDER_TIMEOUT,
    ErrorCode.PROVIDER_RATE_LIMITED,
    ErrorCode.PROVIDER_UNAVAILABLE,
}


@dataclass
class CircuitBreaker:
    failure_threshold: int = 5
    reset_seconds: float = 30.0
    failures: int = 0
    opened_at: float | None = None
    state: str = "closed"
    _lock: asyncio.Lock = field(default_factory=asyncio.Lock, repr=False)

    def allow(self) -> bool:
        if self.state == "open":
            if (
                self.opened_at is not None
                and time.monotonic() - self.opened_at >= self.reset_seconds
            ):
                self.state = "half_open"
                return True
            return False
        return True

    def record_success(self) -> None:
        self.failures = 0
        self.state = "closed"
        self.opened_at = None

    def record_failure(self) -> None:
        self.failures += 1
        if self.state == "half_open" or self.failures >= self.failure_threshold:
            self.state = "open"
            self.opened_at = time.monotonic()


@dataclass
class RetryPolicy:
    max_retries: int = 2
    base_delay: float = 0.4
    max_delay: float = 4.0
    timeout: float = 60.0


class Resilience:
    def __init__(
        self, policy: RetryPolicy, *, failure_threshold: int = 5, reset_seconds: float = 30.0
    ) -> None:
        self.policy = policy
        self.failure_threshold = failure_threshold
        self.reset_seconds = reset_seconds
        self.breakers: dict[str, CircuitBreaker] = {}

    def breaker(self, key: str) -> CircuitBreaker:
        if key not in self.breakers:
            self.breakers[key] = CircuitBreaker(self.failure_threshold, self.reset_seconds)
        return self.breakers[key]

    async def call(
        self,
        key: str,
        operation: Callable[[], Awaitable[T]],
        *,
        cancel: CancelToken | None = None,
        on_error: Callable[[ProviderError, int], Awaitable[None]] | None = None,
    ) -> T:
        breaker = self.breaker(key)
        attempt = 0
        while True:
            if cancel is not None:
                cancel.raise_if_cancelled()
            if not breaker.allow():
                raise ProviderError(
                    ErrorCode.PROVIDER_UNAVAILABLE, f"Circuit breaker abierto para {key}"
                )
            try:
                result = await self._with_timeout_and_cancel(operation, cancel)
            except ProviderError as exc:
                breaker.record_failure()
                if on_error is not None:
                    await on_error(exc, attempt)
                if exc.code not in _RETRYABLE or attempt >= self.policy.max_retries:
                    raise
                delay = min(self.policy.max_delay, self.policy.base_delay * (2**attempt))
                await asyncio.sleep(delay * (0.5 + random.random()))  # noqa: S311
                attempt += 1
                continue
            breaker.record_success()
            return result

    async def _with_timeout_and_cancel(
        self, operation: Callable[[], Awaitable[T]], cancel: CancelToken | None
    ) -> T:
        task = asyncio.ensure_future(operation())
        waiters: set[asyncio.Future[object]] = {task}  # type: ignore[arg-type]
        cancel_task: asyncio.Task[None] | None = None
        if cancel is not None:
            cancel_task = asyncio.ensure_future(cancel.wait())
            waiters.add(cancel_task)  # type: ignore[arg-type]
        try:
            done, _ = await asyncio.wait(
                waiters, timeout=self.policy.timeout, return_when=asyncio.FIRST_COMPLETED
            )
        finally:
            if cancel_task is not None and not cancel_task.done():
                cancel_task.cancel()
        if task in done:
            return task.result()
        task.cancel()
        if cancel is not None and cancel.cancelled:
            raise AppError(ErrorCode.RUN_CANCELLED, "Run cancelado")
        raise ProviderError(
            ErrorCode.PROVIDER_TIMEOUT, f"Sin respuesta del proveedor en {self.policy.timeout}s"
        )
