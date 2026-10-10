"""Eventos de un run para streaming SSE con reconexión (CG-09.4).

Cada evento se numera (``seq``) y se guarda en un buffer con TTL. Un cliente
que se reconecta envía ``Last-Event-ID`` y recibe los eventos pendientes; los
seguidores en otra instancia leen el buffer compartido en Redis.
"""

from __future__ import annotations

import asyncio
import contextlib
import json
from collections.abc import AsyncIterator
from typing import Any

from webassistant.kv import KeyValueStore

TERMINAL_EVENTS = {"run.completed", "run.failed", "run.cancelled"}
BUFFER_TTL_SECONDS = 900


class RunEventBus:
    def __init__(self, kv: KeyValueStore) -> None:
        self.kv = kv
        self._waiters: dict[str, asyncio.Event] = {}
        self._seq: dict[str, int] = {}

    @staticmethod
    def _key(run_id: str) -> str:
        return f"run:{run_id}:events"

    async def publish(self, run_id: str, event_type: str, **data: Any) -> dict[str, Any]:
        seq = self._seq.get(run_id, 0) + 1
        self._seq[run_id] = seq
        event = {"type": event_type, "run_id": run_id, "seq": seq, **data}
        await self.kv.rpush(
            self._key(run_id),
            json.dumps(event, ensure_ascii=False, default=str),
            BUFFER_TTL_SECONDS,
        )
        waiter = self._waiters.get(run_id)
        if waiter is not None:
            waiter.set()
        if event_type in TERMINAL_EVENTS:
            self._seq.pop(run_id, None)
        return event

    async def events(
        self,
        run_id: str,
        *,
        after: int = 0,
        heartbeat_seconds: float = 15.0,
        poll_seconds: float = 0.5,
    ) -> AsyncIterator[dict[str, Any]]:
        """Itera los eventos con ``seq > after`` hasta un evento terminal."""
        cursor = after
        waiter = self._waiters.setdefault(run_id, asyncio.Event())
        idle = 0.0
        try:
            while True:
                raw_events = await self.kv.lrange(self._key(run_id), cursor, -1)
                for raw in raw_events:
                    event = json.loads(raw)
                    cursor = max(cursor, int(event["seq"]))
                    idle = 0.0
                    yield event
                    if event["type"] in TERMINAL_EVENTS:
                        return
                waiter.clear()
                try:
                    await asyncio.wait_for(waiter.wait(), timeout=poll_seconds)
                except TimeoutError:
                    idle += poll_seconds
                    if idle >= heartbeat_seconds:
                        idle = 0.0
                        yield {"type": "heartbeat", "run_id": run_id, "seq": cursor}
        finally:
            if not waiter.is_set():
                self._waiters.pop(run_id, None)


class ReplyExtractor:
    """Extrae progresivamente el valor de ``chat_reply`` de un JSON en streaming."""

    _KEY = '"chat_reply"'

    def __init__(self) -> None:
        self.buffer = ""
        self.start: int | None = None
        self.position = 0
        self.done = False
        self.emitted = ""

    def feed(self, fragment: str) -> str:
        if self.done:
            return ""
        self.buffer += fragment
        if self.start is None:
            index = self.buffer.find(self._KEY)
            if index < 0:
                return ""
            rest = self.buffer[index + len(self._KEY) :]
            stripped = rest.lstrip()
            if not stripped.startswith(":"):
                return ""
            after_colon = stripped[1:].lstrip()
            if not after_colon.startswith('"'):
                return ""
            self.start = len(self.buffer) - len(after_colon) + 1
            self.position = self.start
        output: list[str] = []
        i = self.position
        while i < len(self.buffer):
            char = self.buffer[i]
            if char == "\\":
                if i + 1 >= len(self.buffer):
                    break
                nxt = self.buffer[i + 1]
                if nxt == "u":
                    if i + 6 > len(self.buffer):
                        break
                    with contextlib.suppress(ValueError):
                        output.append(chr(int(self.buffer[i + 2 : i + 6], 16)))
                    i += 6
                    continue
                output.append({"n": "\n", "t": "\t", '"': '"', "\\": "\\", "/": "/"}.get(nxt, nxt))
                i += 2
                continue
            if char == '"':
                self.done = True
                i += 1
                break
            output.append(char)
            i += 1
        self.position = i
        text = "".join(output)
        self.emitted += text
        return text
