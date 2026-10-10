"""Validación de orígenes registrados por site (CG-03.1).

El origen HTTP no es el único control: además se exige site activo, token
firmado ligado a ese origen y límites de uso. Aun así, un dominio no
registrado no puede inicializar el widget.
"""

from __future__ import annotations

import re
from urllib.parse import urlsplit

_ORIGIN_RE = re.compile(r"^https?://[a-z0-9.-]+(?::\d{1,5})?$")
_WILDCARD_RE = re.compile(r"^https?://\*\.[a-z0-9.-]+(?::\d{1,5})?$")


def normalize_origin(value: str) -> str | None:
    value = (value or "").strip().lower().rstrip("/")
    if not value or value == "null":
        return None
    parts = urlsplit(value)
    if parts.scheme not in {"http", "https"} or not parts.hostname:
        return None
    port = parts.port
    default_port = (parts.scheme == "http" and port == 80) or (
        parts.scheme == "https" and port == 443
    )
    netloc = parts.hostname if port is None or default_port else f"{parts.hostname}:{port}"
    return f"{parts.scheme}://{netloc}"


def validate_origin_pattern(value: str) -> str:
    """Valida una entrada de ``allowed_origins`` (admite ``https://*.dominio``)."""
    candidate = value.strip().lower().rstrip("/")
    if _WILDCARD_RE.match(candidate):
        return candidate
    normalized = normalize_origin(candidate)
    if not normalized or not _ORIGIN_RE.match(normalized) or normalized != candidate:
        raise ValueError(f"Origen no válido: {value!r} (usa esquema://host[:puerto] sin ruta)")
    return normalized


def origin_allowed(origin: str | None, allowed: list[str]) -> bool:
    normalized = normalize_origin(origin or "")
    if not normalized:
        return False
    for raw_pattern in allowed:
        pattern = raw_pattern.strip().lower().rstrip("/")
        if pattern == normalized:
            return True
        if pattern.startswith(("http://*.", "https://*.")):
            scheme, _, rest = pattern.partition("://*.")
            o_scheme, _, o_host = normalized.partition("://")
            if o_scheme == scheme and (o_host.endswith("." + rest)):
                return True
    return False
