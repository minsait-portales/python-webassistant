"""Contexto de correlación compartido por logs, trazas, métricas y auditoría (principio
"observabilidad por ejecución": conversación, run, llamada, tool y acción comparten IDs)."""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from contextvars import ContextVar
from typing import Any

_FIELDS = (
    "correlation_id",
    "trace_id",
    "tenant_id",
    "site_id",
    "assistant_id",
    "conversation_id",
    "run_id",
    "tool_call_id",
    "action_execution_id",
    "subject",
)

_vars: dict[str, ContextVar[str | None]] = {
    name: ContextVar(f"webassistant_{name}", default=None) for name in _FIELDS
}


def bind(**values: Any) -> dict[str, Any]:
    """Asocia valores al contexto actual y devuelve los tokens para restaurarlo."""
    tokens: dict[str, Any] = {}
    for key, value in values.items():
        if key in _vars and value is not None:
            tokens[key] = _vars[key].set(str(value))
    return tokens


def reset(tokens: dict[str, Any]) -> None:
    for key, token in tokens.items():
        _vars[key].reset(token)


@contextmanager
def bound(**values: Any) -> Iterator[None]:
    tokens = bind(**values)
    try:
        yield
    finally:
        reset(tokens)


def get(name: str) -> str | None:
    var = _vars.get(name)
    return var.get() if var else None


def snapshot() -> dict[str, str]:
    return {name: value for name in _FIELDS if (value := _vars[name].get()) is not None}
