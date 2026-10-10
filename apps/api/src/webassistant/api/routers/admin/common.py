"""Utilidades comunes de la API de administración."""

from __future__ import annotations

from typing import Annotated, Any

from fastapi import Depends, Request

from webassistant.api.deps import (
    AdminDep,
    ServicesDep,
    client_ip,
    require_permission,
    tenant_access,
)
from webassistant.db.base import Base
from webassistant.security.principal import AdminPrincipal
from webassistant.security.scopes import Permission
from webassistant.services.container import Services


def perm(permission: Permission) -> Any:
    return Annotated[AdminPrincipal, Depends(require_permission(permission))]


PlatformAdmin = perm(Permission.PLATFORM_MANAGE)
TenantManager = perm(Permission.TENANT_MANAGE)
ConfigReader = perm(Permission.CONFIG_READ)
ConfigWriter = perm(Permission.CONFIG_WRITE)
ConfigPublisher = perm(Permission.CONFIG_PUBLISH)
KnowledgeReader = perm(Permission.KNOWLEDGE_READ)
KnowledgeWriter = perm(Permission.KNOWLEDGE_WRITE)
UsageReader = perm(Permission.USAGE_READ)
AuditReader = perm(Permission.AUDIT_READ)
PayloadReader = perm(Permission.PAYLOADS_READ)
ConversationReader = perm(Permission.CONVERSATIONS_READ)

__all__ = [
    "AdminDep",
    "AuditReader",
    "ConfigPublisher",
    "ConfigReader",
    "ConfigWriter",
    "ConversationReader",
    "KnowledgeReader",
    "KnowledgeWriter",
    "PayloadReader",
    "PlatformAdmin",
    "ServicesDep",
    "TenantManager",
    "UsageReader",
    "audit_admin",
    "serialize",
    "tenant_access",
]


def serialize(entity: Base, *, exclude: set[str] | None = None) -> dict[str, Any]:
    exclude = exclude or set()
    data: dict[str, Any] = {}
    for column in entity.__table__.columns:
        key = column.key
        attr = "metadata_" if key == "metadata" else key
        if key in exclude or attr in exclude:
            continue
        value = getattr(entity, attr)
        if hasattr(value, "isoformat"):
            value = value.isoformat()
        elif value.__class__.__name__ == "Decimal":
            value = str(value)
        data[key] = value
    return data


async def audit_admin(
    services: Services,
    principal: AdminPrincipal,
    action: str,
    *,
    tenant_id: str | None,
    target_type: str,
    target_id: str,
    details: dict[str, Any] | None = None,
    request: Request | None = None,
) -> None:
    await services.audit.record(
        action,
        tenant_id=tenant_id,
        actor_type="admin",
        actor_id=principal.user_id,
        target_type=target_type,
        target_id=target_id,
        details=details,
        ip=client_ip(request, services.settings.trusted_proxies) if request else None,
    )
