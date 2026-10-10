"""Scopes, roles administrativos y su matriz de permisos (CG-03.3, CG-11.6)."""

from __future__ import annotations

from collections.abc import Iterable
from enum import StrEnum


class Scope(StrEnum):
    # Widget / usuario final
    CHAT_USE = "chat:use"
    DOCUMENTS_READ = "documents:read"
    KNOWLEDGE_READ = "knowledge:read"
    ACTIONS_EXECUTE = "actions:execute"
    # Server-to-server
    TOKEN_ISSUE = "token:issue"  # nombre de scope, no un secreto  # nosec B105
    INGEST_WRITE = "ingest:write"
    INGEST_READ = "ingest:read"
    SEARCH_READ = "search:read"


WIDGET_BASE_SCOPES = frozenset({Scope.CHAT_USE, Scope.KNOWLEDGE_READ, Scope.DOCUMENTS_READ})
ANONYMOUS_DEFAULT_SCOPES = frozenset({Scope.CHAT_USE, Scope.KNOWLEDGE_READ, Scope.DOCUMENTS_READ})
SERVER_SCOPES = frozenset(
    {Scope.TOKEN_ISSUE, Scope.INGEST_WRITE, Scope.INGEST_READ, Scope.SEARCH_READ}
)


class AdminRole(StrEnum):
    PLATFORM_ADMIN = "platform_admin"
    TENANT_ADMIN = "tenant_admin"
    EDITOR = "editor"
    AUDITOR = "auditor"
    VIEWER = "viewer"


class Permission(StrEnum):
    PLATFORM_MANAGE = "platform.manage"  # tenants, precios, skills globales, índices
    TENANT_MANAGE = "tenant.manage"  # sites, clientes API, usuarios, políticas, retención
    CONFIG_READ = "config.read"
    CONFIG_WRITE = "config.write"  # assistants, agentes, skills por assistant
    CONFIG_PUBLISH = "config.publish"
    KNOWLEDGE_READ = "knowledge.read"
    KNOWLEDGE_WRITE = "knowledge.write"
    USAGE_READ = "usage.read"
    AUDIT_READ = "audit.read"
    PAYLOADS_READ = "payloads.read"  # payloads técnicos cifrados
    CONVERSATIONS_READ = "conversations.read"


ROLE_PERMISSIONS: dict[AdminRole, frozenset[Permission]] = {
    AdminRole.PLATFORM_ADMIN: frozenset(Permission),
    AdminRole.TENANT_ADMIN: frozenset(Permission) - {Permission.PLATFORM_MANAGE},
    AdminRole.EDITOR: frozenset(
        {
            Permission.CONFIG_READ,
            Permission.CONFIG_WRITE,
            Permission.CONFIG_PUBLISH,
            Permission.KNOWLEDGE_READ,
            Permission.KNOWLEDGE_WRITE,
            Permission.USAGE_READ,
        }
    ),
    AdminRole.AUDITOR: frozenset(
        {
            Permission.CONFIG_READ,
            Permission.KNOWLEDGE_READ,
            Permission.USAGE_READ,
            Permission.AUDIT_READ,
            Permission.PAYLOADS_READ,
            Permission.CONVERSATIONS_READ,
        }
    ),
    AdminRole.VIEWER: frozenset(
        {Permission.CONFIG_READ, Permission.KNOWLEDGE_READ, Permission.USAGE_READ}
    ),
}


def role_permissions(role: str) -> frozenset[Permission]:
    try:
        return ROLE_PERMISSIONS[AdminRole(role)]
    except ValueError:
        return frozenset()


def has_scopes(granted: Iterable[str], required: Iterable[str]) -> bool:
    granted_set = set(granted)
    for scope in required:
        if scope in granted_set:
            continue
        namespace = scope.split(":", 1)[0]
        if f"{namespace}:*" in granted_set:
            continue
        return False
    return True


def missing_scopes(granted: Iterable[str], required: Iterable[str]) -> list[str]:
    granted_set = set(granted)
    return [s for s in required if not has_scopes(granted_set, [s])]
