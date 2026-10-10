"""Modelos Pydantic de la API pública v1 (generan el OpenAPI 3.1, CG-09.1)."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


# --- Bootstrap y sesión -----------------------------------------------------------


class BootstrapTexts(BaseModel):
    welcome: str
    input_placeholder: str
    privacy_notice: str
    launcher_label: str
    login_required: str
    access_denied: str
    new_chat: str = "Nuevo chat"
    send: str = "Enviar"
    close: str = "Cerrar chat"


class BootstrapResponse(BaseModel):
    api_version: str = "1"
    site: dict[str, Any]
    assistant: dict[str, Any]
    locale: str
    locales: list[str]
    texts: BootstrapTexts
    theme: dict[str, Any]
    capabilities: dict[str, Any]
    suggestions: list[str]
    limits: dict[str, Any]
    auth: dict[str, Any]
    endpoints: dict[str, str]
    layout: dict[str, Any]


class SessionRequest(Strict):
    site_id: str = Field(max_length=64)
    assistant_id: str | None = Field(default=None, max_length=64)
    locale: str | None = Field(default=None, max_length=10)
    anonymous_session: str | None = Field(default=None, max_length=4096)
    host_token: str | None = Field(default=None, max_length=8192)


class SessionResponse(BaseModel):
    access_token: str
    token_type: Literal["Bearer"] = "Bearer"
    expires_in: int
    expires_at: str
    subject_type: Literal["user", "anonymous"]
    scopes: list[str]
    anonymous_session: str | None = None
    linked_conversations: int = 0


class TokenUser(Strict):
    id: str = Field(
        min_length=1,
        max_length=128,
        description="Identificador opaco del usuario en el portal (sin PII).",
    )
    roles: list[str] = Field(default_factory=list, max_length=50)
    groups: list[str] = Field(default_factory=list, max_length=50)
    locale: str | None = Field(default=None, max_length=10)
    display_name: str | None = Field(default=None, max_length=120)


class TokenRequest(Strict):
    grant_type: Literal["client_credentials", "widget_token"] = "client_credentials"
    client_id: str | None = Field(default=None, max_length=64)
    client_secret: str | None = Field(default=None, max_length=256)
    site_id: str | None = Field(default=None, max_length=64)
    assistant_id: str | None = Field(default=None, max_length=64)
    user: TokenUser | None = None
    scopes: list[str] = Field(default_factory=list, max_length=50)
    origin: str | None = Field(default=None, max_length=300)
    ttl_seconds: int | None = Field(default=None, ge=60, le=3600)


class TokenResponse(BaseModel):
    access_token: str
    token_type: Literal["Bearer"] = "Bearer"
    expires_in: int
    expires_at: str
    scopes: list[str]


# --- Conversación -------------------------------------------------------------------


class ConversationCreate(Strict):
    assistant_id: str | None = Field(default=None, max_length=64)
    locale: str | None = Field(default=None, max_length=10)


class MessageCreate(Strict):
    message: str = Field(min_length=1, max_length=20000)
    client_message_id: str | None = Field(default=None, max_length=64)


class ResetRequest(Strict):
    locale: str | None = Field(default=None, max_length=10)


class ChatResponse(BaseModel):
    api_version: str
    conversation_id: str
    run_id: str
    message_id: str | None = None
    skill: str | None
    reply: str
    presentation: dict[str, Any] | None
    keep_presentation: bool = False
    actions: list[dict[str, Any]]
    citations: list[dict[str, Any]]
    responsive_button_label: str = ""
    usage: dict[str, Any] = Field(default_factory=dict)


class RunAccepted(BaseModel):
    api_version: str = "1"
    conversation_id: str
    run_id: str
    status: str = "running"
    events_url: str


# --- Acciones -----------------------------------------------------------------------


class ActionPrepare(Strict):
    conversation_id: str = Field(max_length=64)
    run_id: str | None = Field(default=None, max_length=64)
    skill: str = Field(max_length=64)
    action: str = Field(max_length=64)
    params: dict[str, Any] = Field(default_factory=dict)


class ActionExecute(Strict):
    confirmation_token: str | None = Field(default=None, max_length=4096)


class ActionReport(Strict):
    status: Literal["succeeded", "failed"]
    result: dict[str, Any] | None = None


# --- Búsqueda e ingesta ---------------------------------------------------------------


class SearchBody(Strict):
    query: str = Field(min_length=1, max_length=2000)
    knowledge_bases: list[str] = Field(default_factory=list, max_length=20)
    site_id: str | None = Field(default=None, max_length=64)
    locale: str | None = Field(default=None, max_length=10)
    principals: list[str] = Field(default_factory=lambda: ["public"], max_length=50)
    limit: int = Field(default=8, ge=1, le=50)
    min_score: float | None = Field(default=None, ge=0, le=1)


class IngestBatch(Strict):
    documents: list[dict[str, Any]] = Field(min_length=1, max_length=100)


class AclUpdate(Strict):
    acl: list[str] = Field(min_length=1, max_length=200)
