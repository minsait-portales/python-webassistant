"""Modelo relacional de WebAssistant (CG-02.1).

Toda entidad de negocio está acotada por ``tenant_id`` y, cuando procede, por
``site_id``. Documentos y chunks guardan solo derivados para RAG; los originales
permanecen en el portal anfitrión. Cada chunk usa el mismo identificador como
``point id`` en Qdrant, lo que permite conciliar ambos almacenes.
"""

from __future__ import annotations

from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    Boolean,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column

from webassistant.db.base import Base, TimestampMixin, id_column, utcnow


def _tenant_fk() -> Mapped[str]:
    return mapped_column(String(48), ForeignKey("tenants.id", ondelete="CASCADE"), index=True)


# --- Organización y canales -------------------------------------------------


class Tenant(TimestampMixin, Base):
    __tablename__ = "tenants"

    id: Mapped[str] = id_column()
    slug: Mapped[str] = mapped_column(String(64), unique=True)
    name: Mapped[str] = mapped_column(String(200))
    status: Mapped[str] = mapped_column(String(20), default="active")
    settings: Mapped[dict[str, Any]] = mapped_column(default=dict)


class Site(TimestampMixin, Base):
    __tablename__ = "sites"
    __table_args__ = (UniqueConstraint("public_id"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    public_id: Mapped[str] = mapped_column(String(64))
    name: Mapped[str] = mapped_column(String(200))
    status: Mapped[str] = mapped_column(String(20), default="active")
    access_mode: Mapped[str] = mapped_column(String(20), default="public")
    allowed_origins: Mapped[list[str]] = mapped_column(default=list)
    default_assistant_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    default_locale: Mapped[str] = mapped_column(String(10), default="es")
    languages: Mapped[list[str]] = mapped_column(default=list)
    theme: Mapped[dict[str, Any]] = mapped_column(default=dict)
    settings: Mapped[dict[str, Any]] = mapped_column(default=dict)
    capabilities: Mapped[list[str]] = mapped_column(default=list)


class ApiClient(TimestampMixin, Base):
    """Credencial server-to-server de un portal (ingesta, emisión de tokens)."""

    __tablename__ = "api_clients"

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str | None] = mapped_column(
        String(48), ForeignKey("sites.id", ondelete="CASCADE"), nullable=True
    )
    name: Mapped[str] = mapped_column(String(200))
    client_id: Mapped[str] = mapped_column(String(64), unique=True)
    secret_hash: Mapped[str] = mapped_column(String(255))
    scopes: Mapped[list[str]] = mapped_column(default=list)
    status: Mapped[str] = mapped_column(String(20), default="active")
    last_used_at: Mapped[datetime | None] = mapped_column(nullable=True)
    expires_at: Mapped[datetime | None] = mapped_column(nullable=True)
    rotated_at: Mapped[datetime | None] = mapped_column(nullable=True)


class AdminUser(TimestampMixin, Base):
    __tablename__ = "admin_users"

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str | None] = mapped_column(
        String(48), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=True, index=True
    )
    email: Mapped[str] = mapped_column(String(320), unique=True)
    name: Mapped[str] = mapped_column(String(200), default="")
    password_hash: Mapped[str] = mapped_column(String(255))
    role: Mapped[str] = mapped_column(String(32))
    status: Mapped[str] = mapped_column(String(20), default="active")
    last_login_at: Mapped[datetime | None] = mapped_column(nullable=True)
    failed_logins: Mapped[int] = mapped_column(Integer, default=0)


# --- Inteligencia -----------------------------------------------------------


class AgentConfig(TimestampMixin, Base):
    __tablename__ = "agent_configs"
    __table_args__ = (UniqueConstraint("tenant_id", "key"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    key: Mapped[str] = mapped_column(String(64))
    name: Mapped[str] = mapped_column(String(200))
    policy: Mapped[dict[str, Any]] = mapped_column(default=dict)


class Assistant(TimestampMixin, Base):
    __tablename__ = "assistants"
    __table_args__ = (UniqueConstraint("tenant_id", "key"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    key: Mapped[str] = mapped_column(String(64))
    name: Mapped[str] = mapped_column(String(200))
    status: Mapped[str] = mapped_column(String(20), default="draft")
    draft_config: Mapped[dict[str, Any]] = mapped_column(default=dict)
    agent_config_id: Mapped[str | None] = mapped_column(
        String(48), ForeignKey("agent_configs.id", ondelete="SET NULL"), nullable=True
    )
    published_version: Mapped[int | None] = mapped_column(Integer, nullable=True)


class AssistantVersion(Base):
    __tablename__ = "assistant_versions"
    __table_args__ = (UniqueConstraint("assistant_id", "version"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    assistant_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("assistants.id", ondelete="CASCADE"), index=True
    )
    version: Mapped[int] = mapped_column(Integer)
    config: Mapped[dict[str, Any]] = mapped_column(default=dict)
    agent_policy: Mapped[dict[str, Any]] = mapped_column(default=dict)
    note: Mapped[str] = mapped_column(String(500), default="")
    created_by: Mapped[str | None] = mapped_column(String(48), nullable=True)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class Skill(Base):
    """Registro global de skills descubiertas (paquetes o registro explícito)."""

    __tablename__ = "skills"

    name: Mapped[str] = mapped_column(String(64), primary_key=True)
    version: Mapped[str] = mapped_column(String(32))
    status: Mapped[str] = mapped_column(String(20))
    source: Mapped[str] = mapped_column(String(500))
    manifest: Mapped[dict[str, Any]] = mapped_column(default=dict)
    diagnostics: Mapped[list[Any]] = mapped_column(default=list)
    checksum: Mapped[str] = mapped_column(String(64), default="")
    discovered_at: Mapped[datetime] = mapped_column(default=utcnow)


class AssistantSkill(Base):
    """Skills habilitadas en una versión publicada de un assistant."""

    __tablename__ = "assistant_skills"
    __table_args__ = (UniqueConstraint("assistant_version_id", "skill_name"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    assistant_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("assistants.id", ondelete="CASCADE"), index=True
    )
    assistant_version_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("assistant_versions.id", ondelete="CASCADE"), index=True
    )
    skill_name: Mapped[str] = mapped_column(String(64))
    skill_version: Mapped[str] = mapped_column(String(32), default="")
    enabled: Mapped[bool] = mapped_column(Boolean, default=True)
    config: Mapped[dict[str, Any]] = mapped_column(default=dict)


# --- Conocimiento -----------------------------------------------------------


class KnowledgeBase(TimestampMixin, Base):
    __tablename__ = "knowledge_bases"
    __table_args__ = (UniqueConstraint("tenant_id", "key"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str | None] = mapped_column(
        String(48), ForeignKey("sites.id", ondelete="SET NULL"), nullable=True
    )
    key: Mapped[str] = mapped_column(String(64))
    name: Mapped[str] = mapped_column(String(200))
    description: Mapped[str] = mapped_column(Text, default="")
    default_locale: Mapped[str] = mapped_column(String(10), default="es")
    chunking: Mapped[dict[str, Any]] = mapped_column(default=dict)
    status: Mapped[str] = mapped_column(String(20), default="active")


class Document(TimestampMixin, Base):
    __tablename__ = "documents"
    __table_args__ = (
        UniqueConstraint("tenant_id", "knowledge_base_id", "source_id"),
        Index("ix_documents_status", "tenant_id", "status"),
    )

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    knowledge_base_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("knowledge_bases.id", ondelete="CASCADE"), index=True
    )
    source_id: Mapped[str] = mapped_column(String(256))
    source_type: Mapped[str] = mapped_column(String(40))
    locale: Mapped[str] = mapped_column(String(10), default="es")
    title: Mapped[str] = mapped_column(String(500))
    canonical_url: Mapped[str | None] = mapped_column(String(2048), nullable=True)
    acl: Mapped[list[str]] = mapped_column(default=list)
    version: Mapped[str] = mapped_column(String(64))
    content_hash: Mapped[str] = mapped_column(String(80))
    content_format: Mapped[str] = mapped_column(String(20), default="text")
    metadata_: Mapped[dict[str, Any]] = mapped_column("metadata", default=dict)
    text: Mapped[str | None] = mapped_column(Text, nullable=True)
    reference: Mapped[dict[str, Any] | None] = mapped_column(nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="queued")
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    chunk_count: Mapped[int] = mapped_column(Integer, default=0)
    embedding_index_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    source_created_at: Mapped[datetime | None] = mapped_column(nullable=True)
    source_updated_at: Mapped[datetime | None] = mapped_column(nullable=True)
    indexed_at: Mapped[datetime | None] = mapped_column(nullable=True)
    deleted_at: Mapped[datetime | None] = mapped_column(nullable=True)


class Chunk(Base):
    __tablename__ = "chunks"
    __table_args__ = (Index("ix_chunks_document", "document_id", "position"),)

    id: Mapped[str] = mapped_column(String(48), primary_key=True)
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    knowledge_base_id: Mapped[str] = mapped_column(String(48), index=True)
    document_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("documents.id", ondelete="CASCADE")
    )
    document_version: Mapped[str] = mapped_column(String(64))
    position: Mapped[int] = mapped_column(Integer)
    title: Mapped[str] = mapped_column(String(500), default="")
    heading_path: Mapped[list[str]] = mapped_column(default=list)
    text: Mapped[str] = mapped_column(Text)
    url: Mapped[str | None] = mapped_column(String(2048), nullable=True)
    page: Mapped[int | None] = mapped_column(Integer, nullable=True)
    token_count: Mapped[int] = mapped_column(Integer, default=0)
    content_hash: Mapped[str] = mapped_column(String(64))
    embedding_index_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class EmbeddingIndex(Base):
    """Versión del índice vectorial (modelo + dimensión + colección de Qdrant)."""

    __tablename__ = "embedding_indexes"

    id: Mapped[str] = id_column()
    provider: Mapped[str] = mapped_column(String(64))
    model: Mapped[str] = mapped_column(String(128))
    dimension: Mapped[int] = mapped_column(Integer)
    collection: Mapped[str] = mapped_column(String(128), unique=True)
    status: Mapped[str] = mapped_column(String(20), default="building")
    stats: Mapped[dict[str, Any]] = mapped_column(default=dict)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)
    activated_at: Mapped[datetime | None] = mapped_column(nullable=True)
    retired_at: Mapped[datetime | None] = mapped_column(nullable=True)


# --- Conversación -----------------------------------------------------------


class Conversation(TimestampMixin, Base):
    __tablename__ = "conversations"
    __table_args__ = (Index("ix_conversations_subject", "tenant_id", "site_id", "subject"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str] = mapped_column(String(48), ForeignKey("sites.id", ondelete="CASCADE"))
    assistant_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("assistants.id", ondelete="CASCADE")
    )
    subject: Mapped[str] = mapped_column(String(128))
    subject_type: Mapped[str] = mapped_column(String(20))
    locale: Mapped[str] = mapped_column(String(10), default="es")
    status: Mapped[str] = mapped_column(String(20), default="active")
    title: Mapped[str] = mapped_column(String(200), default="")
    summary: Mapped[str] = mapped_column(Text, default="")
    context_state: Mapped[dict[str, Any]] = mapped_column(default=dict)
    last_message_at: Mapped[datetime | None] = mapped_column(nullable=True)
    archived_at: Mapped[datetime | None] = mapped_column(nullable=True)


class Message(Base):
    __tablename__ = "messages"
    __table_args__ = (Index("ix_messages_conversation", "conversation_id", "created_at"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str] = mapped_column(String(48))
    conversation_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("conversations.id", ondelete="CASCADE")
    )
    run_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    role: Mapped[str] = mapped_column(String(20))
    content: Mapped[str] = mapped_column(Text)
    presentation: Mapped[dict[str, Any] | None] = mapped_column(nullable=True)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class Run(Base):
    __tablename__ = "runs"
    __table_args__ = (Index("ix_runs_conversation", "conversation_id", "started_at"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str] = mapped_column(String(48))
    conversation_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("conversations.id", ondelete="CASCADE")
    )
    assistant_id: Mapped[str] = mapped_column(String(48))
    assistant_version: Mapped[int | None] = mapped_column(Integer, nullable=True)
    subject: Mapped[str] = mapped_column(String(128), default="")
    status: Mapped[str] = mapped_column(String(20), default="running")
    skill: Mapped[str | None] = mapped_column(String(64), nullable=True)
    error_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    iterations: Mapped[int] = mapped_column(Integer, default=0)
    tool_calls_count: Mapped[int] = mapped_column(Integer, default=0)
    rag_used: Mapped[bool] = mapped_column(Boolean, default=False)
    repaired: Mapped[bool] = mapped_column(Boolean, default=False)
    structured_result: Mapped[dict[str, Any] | None] = mapped_column(nullable=True)
    context_report: Mapped[dict[str, Any]] = mapped_column(default=dict)
    usage_summary: Mapped[dict[str, Any]] = mapped_column(default=dict)
    trace_id: Mapped[str | None] = mapped_column(String(64), nullable=True)
    latency_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    started_at: Mapped[datetime] = mapped_column(default=utcnow)
    finished_at: Mapped[datetime | None] = mapped_column(nullable=True)


class ToolCall(Base):
    __tablename__ = "tool_calls"

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    run_id: Mapped[str] = mapped_column(
        String(48), ForeignKey("runs.id", ondelete="CASCADE"), index=True
    )
    iteration: Mapped[int] = mapped_column(Integer)
    name: Mapped[str] = mapped_column(String(128))
    arguments: Mapped[dict[str, Any]] = mapped_column(default=dict)
    result_summary: Mapped[dict[str, Any]] = mapped_column(default=dict)
    status: Mapped[str] = mapped_column(String(20))
    error_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    latency_ms: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class ActionExecution(TimestampMixin, Base):
    __tablename__ = "action_executions"
    __table_args__ = (UniqueConstraint("tenant_id", "idempotency_key"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str] = mapped_column(String(48))
    conversation_id: Mapped[str | None] = mapped_column(String(48), nullable=True, index=True)
    run_id: Mapped[str | None] = mapped_column(String(48), nullable=True, index=True)
    subject: Mapped[str] = mapped_column(String(128))
    skill: Mapped[str] = mapped_column(String(64))
    action: Mapped[str] = mapped_column(String(64))
    type: Mapped[str] = mapped_column(String(20))
    params: Mapped[dict[str, Any]] = mapped_column(default=dict)
    params_hash: Mapped[str] = mapped_column(String(64))
    requires_confirmation: Mapped[bool] = mapped_column(Boolean, default=False)
    status: Mapped[str] = mapped_column(String(20), default="proposed")
    idempotency_key: Mapped[str | None] = mapped_column(String(128), nullable=True)
    result: Mapped[dict[str, Any] | None] = mapped_column(nullable=True)
    error_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    expires_at: Mapped[datetime | None] = mapped_column(nullable=True)
    confirmed_at: Mapped[datetime | None] = mapped_column(nullable=True)
    executed_at: Mapped[datetime | None] = mapped_column(nullable=True)
    completed_at: Mapped[datetime | None] = mapped_column(nullable=True)


# --- Gobierno ---------------------------------------------------------------


class ModelPrice(Base):
    """Precio versionado por proveedor y modelo (USD u otra moneda por millón de tokens)."""

    __tablename__ = "model_prices"
    __table_args__ = (Index("ix_model_prices_lookup", "provider", "model", "valid_from"),)

    id: Mapped[str] = id_column()
    provider: Mapped[str] = mapped_column(String(64))
    model: Mapped[str] = mapped_column(String(128))
    input_per_million: Mapped[Decimal] = mapped_column()
    cached_per_million: Mapped[Decimal] = mapped_column()
    output_per_million: Mapped[Decimal] = mapped_column()
    currency: Mapped[str] = mapped_column(String(3), default="USD")
    valid_from: Mapped[datetime] = mapped_column()
    valid_to: Mapped[datetime | None] = mapped_column(nullable=True)
    source: Mapped[str] = mapped_column(String(500), default="")
    created_by: Mapped[str | None] = mapped_column(String(48), nullable=True)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class UsageEvent(Base):
    """Una fila por llamada real al proveedor (chat, embeddings) o error de proveedor."""

    __tablename__ = "usage_events"
    __table_args__ = (
        Index("ix_usage_events_period", "tenant_id", "created_at"),
        Index("ix_usage_events_run", "run_id"),
    )

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    site_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    assistant_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    conversation_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    run_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    iteration: Mapped[int] = mapped_column(Integer, default=0)
    skill: Mapped[str | None] = mapped_column(String(64), nullable=True)
    subject: Mapped[str | None] = mapped_column(String(128), nullable=True)
    provider: Mapped[str] = mapped_column(String(64))
    model: Mapped[str] = mapped_column(String(128))
    operation: Mapped[str] = mapped_column(String(32))
    provider_request_id: Mapped[str | None] = mapped_column(String(128), nullable=True)
    input_tokens: Mapped[int] = mapped_column(Integer, default=0)
    cached_tokens: Mapped[int] = mapped_column(Integer, default=0)
    output_tokens: Mapped[int] = mapped_column(Integer, default=0)
    reasoning_tokens: Mapped[int] = mapped_column(Integer, default=0)
    total_tokens: Mapped[int] = mapped_column(Integer, default=0)
    latency_ms: Mapped[int] = mapped_column(Integer, default=0)
    status: Mapped[str] = mapped_column(String(20), default="ok")
    error_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    price_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    input_cost: Mapped[Decimal] = mapped_column(default=Decimal(0))
    cached_cost: Mapped[Decimal] = mapped_column(default=Decimal(0))
    output_cost: Mapped[Decimal] = mapped_column(default=Decimal(0))
    total_cost: Mapped[Decimal] = mapped_column(default=Decimal(0))
    currency: Mapped[str] = mapped_column(String(3), default="USD")
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class TenantPolicy(TimestampMixin, Base):
    """Límites de modelos, tokens, gasto y concurrencia por tenant o assistant (CG-04.5)."""

    __tablename__ = "tenant_policies"
    __table_args__ = (UniqueConstraint("tenant_id", "assistant_id"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    assistant_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    allowed_models: Mapped[list[str]] = mapped_column(default=list)
    max_input_tokens_per_run: Mapped[int | None] = mapped_column(Integer, nullable=True)
    max_output_tokens_per_call: Mapped[int | None] = mapped_column(Integer, nullable=True)
    daily_budget: Mapped[Decimal | None] = mapped_column(nullable=True)
    monthly_budget: Mapped[Decimal | None] = mapped_column(nullable=True)
    currency: Mapped[str] = mapped_column(String(3), default="USD")
    alert_threshold_pct: Mapped[int] = mapped_column(Integer, default=80)
    max_concurrent_runs: Mapped[int | None] = mapped_column(Integer, nullable=True)
    max_concurrent_runs_per_subject: Mapped[int] = mapped_column(Integer, default=1)
    rate_limit_subject_per_minute: Mapped[int | None] = mapped_column(Integer, nullable=True)
    rate_limit_site_per_minute: Mapped[int | None] = mapped_column(Integer, nullable=True)


class RetentionPolicy(TimestampMixin, Base):
    __tablename__ = "retention_policies"
    __table_args__ = (UniqueConstraint("tenant_id"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    conversations_days: Mapped[int] = mapped_column(Integer, default=90)
    payloads_days: Mapped[int] = mapped_column(Integer, default=30)
    audit_days: Mapped[int] = mapped_column(Integer, default=365)
    usage_days: Mapped[int] = mapped_column(Integer, default=730)
    mode: Mapped[str] = mapped_column(String(20), default="anonymize")
    last_applied_at: Mapped[datetime | None] = mapped_column(nullable=True)


class AuditEvent(Base):
    __tablename__ = "audit_events"
    __table_args__ = (Index("ix_audit_events_period", "tenant_id", "created_at"),)

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str | None] = mapped_column(
        String(48), ForeignKey("tenants.id", ondelete="CASCADE"), nullable=True, index=True
    )
    actor_type: Mapped[str] = mapped_column(String(20))
    actor_id: Mapped[str] = mapped_column(String(128))
    action: Mapped[str] = mapped_column(String(128))
    target_type: Mapped[str] = mapped_column(String(64), default="")
    target_id: Mapped[str] = mapped_column(String(128), default="")
    outcome: Mapped[str] = mapped_column(String(20), default="success")
    correlation_id: Mapped[str | None] = mapped_column(String(64), nullable=True)
    run_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    conversation_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    details: Mapped[dict[str, Any]] = mapped_column(default=dict)
    ip: Mapped[str | None] = mapped_column(String(64), nullable=True)
    created_at: Mapped[datetime] = mapped_column(default=utcnow)


class AuditPayload(Base):
    """Payload técnico cifrado (prompt de sistema, petición/respuesta de proveedor)."""

    __tablename__ = "audit_payloads"

    id: Mapped[str] = id_column()
    tenant_id: Mapped[str] = _tenant_fk()
    run_id: Mapped[str | None] = mapped_column(String(48), nullable=True, index=True)
    usage_event_id: Mapped[str | None] = mapped_column(String(48), nullable=True)
    kind: Mapped[str] = mapped_column(String(40))
    content_encrypted: Mapped[str] = mapped_column(Text)
    redacted_preview: Mapped[str] = mapped_column(Text, default="")
    created_at: Mapped[datetime] = mapped_column(default=utcnow)
    expires_at: Mapped[datetime | None] = mapped_column(nullable=True)
