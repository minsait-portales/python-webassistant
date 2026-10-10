"""Redacción de datos sensibles (CG-12.4, CG-13.1, CG-03.6).

Se aplica a logs generales, detalles de auditoría y vistas normales de soporte:
credenciales, tokens, cabeceras de autorización y PII habitual (email, teléfono,
IBAN, tarjetas, DNI/NIE) nunca deben aparecer en claro fuera de los payloads
cifrados de acceso restringido.
"""

from __future__ import annotations

import re
from collections.abc import Mapping, Sequence
from typing import Any

REDACTED = "[REDACTED]"

SENSITIVE_KEYS = re.compile(
    r"(pass(word)?|secret|token|api[_-]?key|authorization|cookie|credential|private[_-]?key|"
    r"client[_-]?secret|access[_-]?token|refresh[_-]?token|session|signature|iban|card|cvv|pin)",
    re.IGNORECASE,
)

_PATTERNS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"(?i)bearer\s+[a-z0-9._~+/=-]{8,}"), "Bearer " + REDACTED),
    (re.compile(r"eyJ[a-zA-Z0-9_-]{5,}\.[a-zA-Z0-9_-]{5,}\.[a-zA-Z0-9_-]{5,}"), "[JWT]"),
    (re.compile(r"sk-[A-Za-z0-9_-]{16,}"), "[API_KEY]"),
    (re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}"), "[EMAIL]"),
    (re.compile(r"\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}\b"), "[IBAN]"),
    (re.compile(r"\b(?:\d[ -]?){13,19}\b"), "[CARD]"),
    (re.compile(r"\b(?:\+?\d{2,3}[ .-]?)?(?:[6789]\d{2}[ .-]?\d{3}[ .-]?\d{3})\b"), "[PHONE]"),
    (re.compile(r"\b[XYZ]?\d{7,8}[A-HJ-NP-TV-Z]\b"), "[DNI]"),
]


def redact_text(value: str) -> str:
    redacted = value
    for pattern, replacement in _PATTERNS:
        redacted = pattern.sub(replacement, redacted)
    return redacted


def redact(value: Any, *, depth: int = 0, max_depth: int = 8) -> Any:
    """Redacta recursivamente estructuras JSON-compatibles."""
    if depth > max_depth:
        return "[TRUNCATED]"
    if isinstance(value, str):
        return redact_text(value)
    if isinstance(value, Mapping):
        result: dict[str, Any] = {}
        for key, item in value.items():
            key_text = str(key)
            if SENSITIVE_KEYS.search(key_text):
                result[key_text] = REDACTED
            else:
                result[key_text] = redact(item, depth=depth + 1, max_depth=max_depth)
        return result
    if isinstance(value, Sequence) and not isinstance(value, bytes | bytearray):
        return [redact(item, depth=depth + 1, max_depth=max_depth) for item in value]
    return value


def truncate(value: str, limit: int = 2000) -> str:
    if len(value) <= limit:
        return value
    return value[: limit - 1] + "…"
