"""Cola de trabajos asíncronos respaldada por Redis (arq) con modo inline para desarrollo.

Los trabajos son idempotentes: pueden reintentarse sin duplicar derivados.
"""

from __future__ import annotations

import asyncio
from abc import ABC, abstractmethod
from collections.abc import Awaitable, Callable
from typing import Any

from webassistant.observability.logging import get_logger

logger = get_logger("webassistant.queue")

JobFunction = Callable[..., Awaitable[Any]]


class JobQueue(ABC):
    @abstractmethod
    async def enqueue(
        self,
        job: str,
        *,
        job_id: str | None = None,
        defer_seconds: float | None = None,
        **kwargs: Any,
    ) -> str | None: ...

    async def depth(self) -> int:
        return 0

    async def close(self) -> None:  # noqa: B027
        pass


class InlineQueue(JobQueue):
    """Ejecuta los trabajos en el propio proceso (desarrollo, pruebas, demo)."""

    def __init__(self, *, run_in_background: bool = True) -> None:
        self.handlers: dict[str, JobFunction] = {}
        self.run_in_background = run_in_background
        self.tasks: set[asyncio.Task[Any]] = set()
        self.history: list[tuple[str, dict[str, Any]]] = []

    def register(self, name: str, handler: JobFunction) -> None:
        self.handlers[name] = handler

    async def _run(self, job: str, kwargs: dict[str, Any]) -> None:
        handler = self.handlers.get(job)
        if handler is None:
            logger.error("Trabajo desconocido: %s", job)
            return
        try:
            await handler(**kwargs)
        except Exception:
            logger.exception("El trabajo %s falló", job)

    async def enqueue(
        self,
        job: str,
        *,
        job_id: str | None = None,
        defer_seconds: float | None = None,
        **kwargs: Any,
    ) -> str | None:
        self.history.append((job, kwargs))
        if not self.run_in_background:
            await self._run(job, kwargs)
            return job_id
        task = asyncio.create_task(self._run(job, kwargs))
        self.tasks.add(task)
        task.add_done_callback(self.tasks.discard)
        return job_id

    async def drain(self) -> None:
        while self.tasks:
            await asyncio.gather(*list(self.tasks), return_exceptions=True)

    async def depth(self) -> int:
        return len(self.tasks)


class ArqQueue(JobQueue):
    def __init__(self, redis_url: str, queue_name: str) -> None:
        self.redis_url = redis_url
        self.queue_name = queue_name
        self._pool: Any = None

    async def _get_pool(self) -> Any:
        if self._pool is None:
            from arq import create_pool
            from arq.connections import RedisSettings

            self._pool = await create_pool(
                RedisSettings.from_dsn(self.redis_url), default_queue_name=self.queue_name
            )
        return self._pool

    async def enqueue(
        self,
        job: str,
        *,
        job_id: str | None = None,
        defer_seconds: float | None = None,
        **kwargs: Any,
    ) -> str | None:
        pool = await self._get_pool()
        result = await pool.enqueue_job(job, _job_id=job_id, _defer_by=defer_seconds, **kwargs)
        return result.job_id if result else job_id

    async def depth(self) -> int:
        pool = await self._get_pool()
        return int(await pool.zcard(self.queue_name))

    async def close(self) -> None:
        if self._pool is not None:
            await self._pool.aclose()
