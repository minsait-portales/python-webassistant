"""Almacén clave-valor para caché, locks, rate limit, idempotencia y eventos de run.

Usa Redis cuando ``WEBASSISTANT_REDIS_URL`` está definida; si no, una
implementación en memoria válida para desarrollo y pruebas con una sola
instancia.
"""

from __future__ import annotations

import asyncio
import time
from abc import ABC, abstractmethod
from typing import Any

from redis.asyncio import Redis


class KeyValueStore(ABC):
    @abstractmethod
    async def get(self, key: str) -> str | None: ...

    @abstractmethod
    async def set(
        self, key: str, value: str, ttl: float | None = None, *, nx: bool = False
    ) -> bool: ...

    @abstractmethod
    async def delete(self, *keys: str) -> None: ...

    @abstractmethod
    async def incr(self, key: str, ttl: float) -> int:
        """Incrementa un contador; fija el TTL cuando la clave se crea."""

    @abstractmethod
    async def decr(self, key: str) -> int: ...

    @abstractmethod
    async def rpush(self, key: str, value: str, ttl: float) -> int: ...

    @abstractmethod
    async def lrange(self, key: str, start: int = 0, end: int = -1) -> list[str]: ...

    @abstractmethod
    async def ping(self) -> bool: ...

    async def close(self) -> None:  # noqa: B027
        pass


class MemoryStore(KeyValueStore):
    def __init__(self) -> None:
        self._data: dict[str, tuple[Any, float | None]] = {}
        self._lock = asyncio.Lock()

    def _alive(self, key: str) -> Any:
        item = self._data.get(key)
        if item is None:
            return None
        value, expires = item
        if expires is not None and expires <= time.monotonic():
            self._data.pop(key, None)
            return None
        return value

    @staticmethod
    def _expiry(ttl: float | None) -> float | None:
        return time.monotonic() + ttl if ttl else None

    async def get(self, key: str) -> str | None:
        value = self._alive(key)
        return None if value is None else str(value)

    async def set(
        self, key: str, value: str, ttl: float | None = None, *, nx: bool = False
    ) -> bool:
        async with self._lock:
            if nx and self._alive(key) is not None:
                return False
            self._data[key] = (value, self._expiry(ttl))
            return True

    async def delete(self, *keys: str) -> None:
        async with self._lock:
            for key in keys:
                self._data.pop(key, None)

    async def incr(self, key: str, ttl: float) -> int:
        async with self._lock:
            current = self._alive(key)
            if current is None:
                self._data[key] = (1, self._expiry(ttl))
                return 1
            expires = self._data[key][1]
            self._data[key] = (int(current) + 1, expires)
            return int(current) + 1

    async def decr(self, key: str) -> int:
        async with self._lock:
            current = self._alive(key)
            if current is None:
                return 0
            expires = self._data[key][1]
            value = max(0, int(current) - 1)
            self._data[key] = (value, expires)
            return value

    async def rpush(self, key: str, value: str, ttl: float) -> int:
        async with self._lock:
            current = self._alive(key)
            items: list[str] = list(current) if current is not None else []
            items.append(value)
            self._data[key] = (items, self._expiry(ttl))
            return len(items)

    async def lrange(self, key: str, start: int = 0, end: int = -1) -> list[str]:
        current = self._alive(key)
        if current is None:
            return []
        items = list(current)
        stop = None if end == -1 else end + 1
        return items[start:stop]

    async def ping(self) -> bool:
        return True


class RedisStore(KeyValueStore):
    def __init__(self, url: str) -> None:
        self.redis: Redis = Redis.from_url(url, decode_responses=True, health_check_interval=30)

    async def get(self, key: str) -> str | None:
        return await self.redis.get(key)

    async def set(
        self, key: str, value: str, ttl: float | None = None, *, nx: bool = False
    ) -> bool:
        px = int(ttl * 1000) if ttl else None
        return bool(await self.redis.set(key, value, px=px, nx=nx))

    async def delete(self, *keys: str) -> None:
        if keys:
            await self.redis.delete(*keys)

    async def incr(self, key: str, ttl: float) -> int:
        async with self.redis.pipeline(transaction=True) as pipe:
            pipe.incr(key)
            pipe.pexpire(key, int(ttl * 1000), nx=True)
            value, _ = await pipe.execute()
        return int(value)

    async def decr(self, key: str) -> int:
        value = int(await self.redis.decr(key))
        if value < 0:
            await self.redis.set(key, 0, keepttl=True)
            return 0
        return value

    async def rpush(self, key: str, value: str, ttl: float) -> int:
        async with self.redis.pipeline(transaction=True) as pipe:
            pipe.rpush(key, value)
            pipe.pexpire(key, int(ttl * 1000))
            length, _ = await pipe.execute()
        return int(length)

    async def lrange(self, key: str, start: int = 0, end: int = -1) -> list[str]:
        return list(await self.redis.lrange(key, start, end))  # type: ignore[misc]

    async def ping(self) -> bool:
        return bool(await self.redis.ping())

    async def close(self) -> None:
        await self.redis.aclose()


def create_store(redis_url: str | None) -> KeyValueStore:
    return RedisStore(redis_url) if redis_url else MemoryStore()
