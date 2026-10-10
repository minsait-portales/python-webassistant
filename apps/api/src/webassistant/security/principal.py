"""Principales autenticados que llegan a los servicios."""

from __future__ import annotations

from dataclasses import dataclass, field

from webassistant.security.tokens import WidgetClaims


@dataclass(frozen=True)
class SiteInfo:
    id: str
    public_id: str
    tenant_id: str
    name: str
    status: str
    access_mode: str
    allowed_origins: tuple[str, ...]
    default_assistant_id: str | None
    default_locale: str
    languages: tuple[str, ...]
    theme: dict[str, object] = field(default_factory=dict, hash=False, compare=False)
    settings: dict[str, object] = field(default_factory=dict, hash=False, compare=False)
    capabilities: tuple[str, ...] = ()


@dataclass(frozen=True)
class WidgetPrincipal:
    claims: WidgetClaims
    site: SiteInfo
    ip: str | None = None

    @property
    def tenant_id(self) -> str:
        return self.claims.tenant_id

    @property
    def subject(self) -> str:
        return self.claims.subject

    @property
    def scopes(self) -> frozenset[str]:
        return self.claims.scopes


@dataclass(frozen=True)
class ServerPrincipal:
    client_id: str
    tenant_id: str
    site_id: str | None
    scopes: frozenset[str]


@dataclass(frozen=True)
class AdminPrincipal:
    user_id: str
    tenant_id: str | None
    role: str
    email: str
    permissions: frozenset[str]

    @property
    def is_platform(self) -> bool:
        return self.tenant_id is None and self.role == "platform_admin"
