"""Gestión del contexto enviado al modelo (CG-08.3).

Se aplica una ventana de mensajes visibles con presupuesto de tokens y, en la
estrategia ``summary``, un resumen extractivo de los turnos anteriores a la
ventana. Se registra exactamente qué información se envía (``context_report``).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from webassistant.db import models as m
from webassistant.gateway.base import ChatMessage, approx_tokens
from webassistant.text import limit_text


@dataclass
class ContextWindow:
    messages: list[ChatMessage]
    summary: str = ""
    report: dict[str, Any] = field(default_factory=dict)


def build_context(
    history: list[m.Message],
    *,
    window_messages: int,
    max_tokens: int,
    strategy: str = "window",
    existing_summary: str = "",
) -> ContextWindow:
    visible = [msg for msg in history if msg.role in {"user", "assistant"} and msg.content.strip()]
    window = visible[-window_messages:] if window_messages > 0 else []
    # Recorte por presupuesto de tokens empezando por los mensajes más antiguos.
    budget = max_tokens
    selected: list[m.Message] = []
    for msg in reversed(window):
        cost = approx_tokens(msg.content)
        if cost > budget:
            break
        budget -= cost
        selected.append(msg)
    selected.reverse()
    dropped = visible[: len(visible) - len(selected)]
    summary = ""
    if strategy == "summary" and dropped:
        lines = [
            f"{'Usuario' if x.role == 'user' else 'Asistente'}: {limit_text(x.content, 160)}"
            for x in dropped[-12:]
        ]
        summary = limit_text(
            (existing_summary + "\n" if existing_summary else "") + "\n".join(lines), 2000
        )
    messages = [
        ChatMessage(role="user" if x.role == "user" else "assistant", content=x.content)
        for x in selected
    ]
    report = {
        "strategy": strategy,
        "history_messages_available": len(visible),
        "history_messages_sent": len(selected),
        "history_message_ids": [x.id for x in selected],
        "summary_chars": len(summary),
        "history_tokens": sum(approx_tokens(x.content) for x in selected),
    }
    return ContextWindow(messages=messages, summary=summary, report=report)
