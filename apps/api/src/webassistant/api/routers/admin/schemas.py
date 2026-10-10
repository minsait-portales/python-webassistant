"""Modelos de entrada de la API de administración."""

from __future__ import annotations

from decimal import Decimal
from typing import Annotated, Any, Literal

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator

from webassistant.security.origins import validate_origin_pattern

Email = Annotated[
    str,
    StringConstraints(
        strip_whitespace=True, to_lower=True, max_length=320, pattern=r"^[^@\s]+@[^@\s]+\.[^@\s]+$"
    ),
]


class Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


class LoginBody(Strict):
    email: Email
    password: str = Field(min_length=1, max_length=256)


class PasswordChange(Strict):
    current_password: str = Field(max_length=256)
    new_password: str = Field(min_length=12, max_length=256)


class TenantBody(Strict):
    slug: str = Field(pattern=r"^[a-z][a-z0-9-]{1,62}$")
    name: str = Field(min_length=1, max_length=200)
    status: Literal["active", "suspended"] = "active"
    settings: dict[str, Any] = Field(default_factory=dict)


class TenantPatch(Strict):
    name: str | None = Field(default=None, max_length=200)
    status: Literal["active", "suspended"] | None = None
    settings: dict[str, Any] | None = None


class SiteBody(Strict):
    public_id: str = Field(pattern=r"^[a-z][a-z0-9_-]{2,62}$")
    name: str = Field(min_length=1, max_length=200)
    status: Literal["active", "disabled"] = "active"
    access_mode: Literal["public", "private"] = "public"
    allowed_origins: list[str] = Field(min_length=1, max_length=50)
    default_assistant: str | None = Field(default=None, max_length=64)
    default_locale: str = Field(default="es", max_length=10)
    languages: list[str] = Field(default_factory=lambda: ["es"], max_length=20)
    theme: dict[str, Any] = Field(default_factory=dict)
    settings: dict[str, Any] = Field(default_factory=dict)
    capabilities: list[str] = Field(default_factory=list, max_length=30)

    @field_validator("allowed_origins")
    @classmethod
    def _origins(cls, value: list[str]) -> list[str]:
        return [validate_origin_pattern(origin) for origin in value]


class SitePatch(Strict):
    name: str | None = Field(default=None, max_length=200)
    status: Literal["active", "disabled"] | None = None
    access_mode: Literal["public", "private"] | None = None
    allowed_origins: list[str] | None = Field(default=None, max_length=50)
    default_assistant: str | None = Field(default=None, max_length=64)
    default_locale: str | None = Field(default=None, max_length=10)
    languages: list[str] | None = Field(default=None, max_length=20)
    theme: dict[str, Any] | None = None
    settings: dict[str, Any] | None = None
    capabilities: list[str] | None = Field(default=None, max_length=30)

    @field_validator("allowed_origins")
    @classmethod
    def _origins(cls, value: list[str] | None) -> list[str] | None:
        return [validate_origin_pattern(origin) for origin in value] if value is not None else None


class ApiClientBody(Strict):
    name: str = Field(min_length=1, max_length=200)
    site_id: str | None = Field(
        default=None, max_length=64, description="Identificador público del site al que se limita."
    )
    scopes: list[Literal["token:issue", "ingest:write", "ingest:read", "search:read"]] = Field(
        min_length=1
    )


class AdminUserBody(Strict):
    email: Email
    name: str = Field(default="", max_length=200)
    role: Literal["platform_admin", "tenant_admin", "editor", "auditor", "viewer"]
    password: str = Field(min_length=12, max_length=256)


class AdminUserPatch(Strict):
    name: str | None = Field(default=None, max_length=200)
    role: Literal["tenant_admin", "editor", "auditor", "viewer"] | None = None
    status: Literal["active", "disabled"] | None = None


class AgentConfigBody(Strict):
    key: str = Field(pattern=r"^[a-z][a-z0-9_-]{1,62}$")
    name: str = Field(min_length=1, max_length=200)
    policy: dict[str, Any] = Field(default_factory=dict)


class AgentConfigPatch(Strict):
    name: str | None = Field(default=None, max_length=200)
    policy: dict[str, Any] | None = None


class AssistantBody(Strict):
    key: str = Field(pattern=r"^[a-z][a-z0-9_-]{1,62}$")
    name: str = Field(min_length=1, max_length=200)
    config: dict[str, Any] = Field(default_factory=dict)
    agent_config: str | None = Field(default=None, max_length=64)


class AssistantPatch(Strict):
    name: str | None = Field(default=None, max_length=200)
    config: dict[str, Any] | None = None
    agent_config: str | None = Field(default=None, max_length=64)
    status: Literal["draft", "published", "archived"] | None = None


class PublishBody(Strict):
    note: str = Field(default="", max_length=500)


class RestoreBody(Strict):
    version: int = Field(ge=1)
    publish: bool = False


class KnowledgeBaseBody(Strict):
    key: str = Field(pattern=r"^[a-z][a-z0-9_-]{1,62}$")
    name: str = Field(min_length=1, max_length=200)
    description: str = Field(default="", max_length=2000)
    site_id: str | None = Field(default=None, max_length=64)
    default_locale: str = Field(default="es", max_length=10)
    chunking: dict[str, Any] = Field(default_factory=dict)


class KnowledgeBasePatch(Strict):
    name: str | None = Field(default=None, max_length=200)
    description: str | None = Field(default=None, max_length=2000)
    default_locale: str | None = Field(default=None, max_length=10)
    chunking: dict[str, Any] | None = None
    status: Literal["active", "disabled"] | None = None


class PriceBody(Strict):
    provider: str = Field(min_length=1, max_length=64)
    model: str = Field(min_length=1, max_length=128)
    input_per_million: Decimal = Field(ge=0)
    cached_per_million: Decimal = Field(default=Decimal(0), ge=0)
    output_per_million: Decimal = Field(ge=0)
    currency: str = Field(default="USD", pattern=r"^[A-Z]{3}$")
    valid_from: str | None = None
    source: str = Field(default="", max_length=500)


class PolicyBody(Strict):
    assistant: str | None = Field(default=None, max_length=64)
    allowed_models: list[str] = Field(default_factory=list)
    max_input_tokens_per_run: int | None = Field(default=None, ge=100)
    max_output_tokens_per_call: int | None = Field(default=None, ge=64)
    daily_budget: Decimal | None = Field(default=None, ge=0)
    monthly_budget: Decimal | None = Field(default=None, ge=0)
    currency: str = Field(default="USD", pattern=r"^[A-Z]{3}$")
    alert_threshold_pct: int = Field(default=80, ge=1, le=100)
    max_concurrent_runs: int | None = Field(default=None, ge=1)
    max_concurrent_runs_per_subject: int = Field(default=1, ge=1, le=20)
    rate_limit_subject_per_minute: int | None = Field(default=None, ge=1)
    rate_limit_site_per_minute: int | None = Field(default=None, ge=1)


class RetentionBody(Strict):
    conversations_days: int = Field(ge=1, le=3650)
    payloads_days: int = Field(ge=1, le=3650)
    audit_days: int = Field(ge=30, le=3650)
    usage_days: int = Field(ge=30, le=3650)
    mode: Literal["delete", "anonymize"] = "anonymize"


class ReindexBody(Strict):
    provider: str
    model: str
    activate: bool = False
