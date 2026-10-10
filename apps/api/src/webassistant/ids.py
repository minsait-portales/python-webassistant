"""Identificadores opacos con prefijo de tipo.

Los identificadores no revelan secuencia ni contexto (CG-08.1): 20 bytes
aleatorios codificados en base32 sin relleno, en minúsculas.
"""

from __future__ import annotations

import base64
import hashlib
import re
import secrets
import uuid

PREFIXES = {
    "tenant": "ten",
    "site": "site",
    "assistant": "ast",
    "assistant_version": "astv",
    "assistant_skill": "asks",
    "agent_config": "agt",
    "conversation": "conv",
    "message": "msg",
    "run": "run",
    "tool_call": "tool",
    "knowledge_base": "kb",
    "document": "doc",
    "usage_event": "use",
    "audit_event": "aud",
    "audit_payload": "pay",
    "price": "price",
    "api_client": "cli",
    "admin_user": "usr",
    "action_execution": "act",
    "policy": "pol",
    "embedding_index": "emb",
    "job": "job",
    "anonymous": "anon",
    "correlation": "cor",
}

_ID_RE = re.compile(r"^[a-z]+_[a-z0-9]{8,64}$")
CHUNK_NAMESPACE = uuid.UUID("6f1d2a3c-9e1b-4a7f-b5c2-1f0e7d9a8b42")


def new_id(kind: str) -> str:
    prefix = PREFIXES[kind]
    token = base64.b32encode(secrets.token_bytes(15)).decode("ascii").rstrip("=").lower()
    return f"{prefix}_{token}"


def has_prefix(value: str, kind: str) -> bool:
    return value.startswith(PREFIXES[kind] + "_") and bool(_ID_RE.match(value))


def chunk_point_id(
    tenant_id: str, knowledge_base_id: str, source_id: str, version: str, index: int
) -> str:
    """Identificador estable compartido por PostgreSQL y Qdrant para un chunk."""
    name = f"{tenant_id}|{knowledge_base_id}|{source_id}|{version}|{index}"
    return str(uuid.uuid5(CHUNK_NAMESPACE, name))


def stable_hash(*parts: str) -> str:
    digest = hashlib.sha256()
    for part in parts:
        digest.update(part.encode("utf-8"))
        digest.update(b"\x00")
    return digest.hexdigest()


def new_secret(length: int = 32) -> str:
    return secrets.token_urlsafe(length)
