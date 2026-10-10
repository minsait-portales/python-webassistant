"""Esquema inicial: modelo relacional multi-tenant y políticas RLS (CG-02.1, CG-02.3)

Revision ID: 0001
Revises:
Create Date: 2026-10-10 13:35:08.583338
"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

TENANT_TABLES = (
    "sites",
    "api_clients",
    "agent_configs",
    "assistants",
    "assistant_versions",
    "assistant_skills",
    "knowledge_bases",
    "documents",
    "chunks",
    "conversations",
    "messages",
    "runs",
    "tool_calls",
    "action_executions",
    "usage_events",
    "audit_payloads",
    "tenant_policies",
    "retention_policies",
    "audit_events",
)

TENANT_PREDICATE = (
    "current_setting('app.bypass_rls', true) = 'on' "
    "OR tenant_id = NULLIF(current_setting('app.tenant_id', true), '')"
)

revision: str = "0001"
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.create_table(
        "embedding_indexes",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("provider", sa.String(length=64), nullable=False),
        sa.Column("model", sa.String(length=128), nullable=False),
        sa.Column("dimension", sa.Integer(), nullable=False),
        sa.Column("collection", sa.String(length=128), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column(
            "stats",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("activated_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("retired_at", sa.DateTime(timezone=True), nullable=True),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_embedding_indexes")),
        sa.UniqueConstraint("collection", name=op.f("uq_embedding_indexes_collection")),
    )
    op.create_table(
        "model_prices",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("provider", sa.String(length=64), nullable=False),
        sa.Column("model", sa.String(length=128), nullable=False),
        sa.Column("input_per_million", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("cached_per_million", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("output_per_million", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("currency", sa.String(length=3), nullable=False),
        sa.Column("valid_from", sa.DateTime(timezone=True), nullable=False),
        sa.Column("valid_to", sa.DateTime(timezone=True), nullable=True),
        sa.Column("source", sa.String(length=500), nullable=False),
        sa.Column("created_by", sa.String(length=48), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_model_prices")),
    )
    op.create_index(
        "ix_model_prices_lookup", "model_prices", ["provider", "model", "valid_from"], unique=False
    )
    op.create_table(
        "skills",
        sa.Column("name", sa.String(length=64), nullable=False),
        sa.Column("version", sa.String(length=32), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("source", sa.String(length=500), nullable=False),
        sa.Column(
            "manifest",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "diagnostics",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("checksum", sa.String(length=64), nullable=False),
        sa.Column("discovered_at", sa.DateTime(timezone=True), nullable=False),
        sa.PrimaryKeyConstraint("name", name=op.f("pk_skills")),
    )
    op.create_table(
        "tenants",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("slug", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column(
            "settings",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_tenants")),
        sa.UniqueConstraint("slug", name=op.f("uq_tenants_slug")),
    )
    op.create_table(
        "action_executions",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=False),
        sa.Column("conversation_id", sa.String(length=48), nullable=True),
        sa.Column("run_id", sa.String(length=48), nullable=True),
        sa.Column("subject", sa.String(length=128), nullable=False),
        sa.Column("skill", sa.String(length=64), nullable=False),
        sa.Column("action", sa.String(length=64), nullable=False),
        sa.Column("type", sa.String(length=20), nullable=False),
        sa.Column(
            "params",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("params_hash", sa.String(length=64), nullable=False),
        sa.Column("requires_confirmation", sa.Boolean(), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("idempotency_key", sa.String(length=128), nullable=True),
        sa.Column(
            "result",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=True,
        ),
        sa.Column("error_code", sa.String(length=64), nullable=True),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("confirmed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("executed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("completed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_action_executions_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_action_executions")),
        sa.UniqueConstraint(
            "tenant_id", "idempotency_key", name=op.f("uq_action_executions_tenant_id")
        ),
    )
    op.create_index(
        op.f("ix_action_executions_conversation_id"),
        "action_executions",
        ["conversation_id"],
        unique=False,
    )
    op.create_index(
        op.f("ix_action_executions_run_id"), "action_executions", ["run_id"], unique=False
    )
    op.create_index(
        op.f("ix_action_executions_tenant_id"), "action_executions", ["tenant_id"], unique=False
    )
    op.create_table(
        "admin_users",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=True),
        sa.Column("email", sa.String(length=320), nullable=False),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column("password_hash", sa.String(length=255), nullable=False),
        sa.Column("role", sa.String(length=32), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("last_login_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("failed_logins", sa.Integer(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_admin_users_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_admin_users")),
        sa.UniqueConstraint("email", name=op.f("uq_admin_users_email")),
    )
    op.create_index(op.f("ix_admin_users_tenant_id"), "admin_users", ["tenant_id"], unique=False)
    op.create_table(
        "agent_configs",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("key", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column(
            "policy",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_agent_configs_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_agent_configs")),
        sa.UniqueConstraint("tenant_id", "key", name=op.f("uq_agent_configs_tenant_id")),
    )
    op.create_index(
        op.f("ix_agent_configs_tenant_id"), "agent_configs", ["tenant_id"], unique=False
    )
    op.create_table(
        "audit_events",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=True),
        sa.Column("actor_type", sa.String(length=20), nullable=False),
        sa.Column("actor_id", sa.String(length=128), nullable=False),
        sa.Column("action", sa.String(length=128), nullable=False),
        sa.Column("target_type", sa.String(length=64), nullable=False),
        sa.Column("target_id", sa.String(length=128), nullable=False),
        sa.Column("outcome", sa.String(length=20), nullable=False),
        sa.Column("correlation_id", sa.String(length=64), nullable=True),
        sa.Column("run_id", sa.String(length=48), nullable=True),
        sa.Column("conversation_id", sa.String(length=48), nullable=True),
        sa.Column(
            "details",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("ip", sa.String(length=64), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_audit_events_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_audit_events")),
    )
    op.create_index(
        "ix_audit_events_period", "audit_events", ["tenant_id", "created_at"], unique=False
    )
    op.create_index(op.f("ix_audit_events_tenant_id"), "audit_events", ["tenant_id"], unique=False)
    op.create_table(
        "audit_payloads",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("run_id", sa.String(length=48), nullable=True),
        sa.Column("usage_event_id", sa.String(length=48), nullable=True),
        sa.Column("kind", sa.String(length=40), nullable=False),
        sa.Column("content_encrypted", sa.Text(), nullable=False),
        sa.Column("redacted_preview", sa.Text(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_audit_payloads_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_audit_payloads")),
    )
    op.create_index(op.f("ix_audit_payloads_run_id"), "audit_payloads", ["run_id"], unique=False)
    op.create_index(
        op.f("ix_audit_payloads_tenant_id"), "audit_payloads", ["tenant_id"], unique=False
    )
    op.create_table(
        "retention_policies",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("conversations_days", sa.Integer(), nullable=False),
        sa.Column("payloads_days", sa.Integer(), nullable=False),
        sa.Column("audit_days", sa.Integer(), nullable=False),
        sa.Column("usage_days", sa.Integer(), nullable=False),
        sa.Column("mode", sa.String(length=20), nullable=False),
        sa.Column("last_applied_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_retention_policies_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_retention_policies")),
        sa.UniqueConstraint("tenant_id", name=op.f("uq_retention_policies_tenant_id")),
    )
    op.create_index(
        op.f("ix_retention_policies_tenant_id"), "retention_policies", ["tenant_id"], unique=False
    )
    op.create_table(
        "sites",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("public_id", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("access_mode", sa.String(length=20), nullable=False),
        sa.Column(
            "allowed_origins",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("default_assistant_id", sa.String(length=48), nullable=True),
        sa.Column("default_locale", sa.String(length=10), nullable=False),
        sa.Column(
            "languages",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "theme",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "settings",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "capabilities",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_sites_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_sites")),
        sa.UniqueConstraint("public_id", name=op.f("uq_sites_public_id")),
    )
    op.create_index(op.f("ix_sites_tenant_id"), "sites", ["tenant_id"], unique=False)
    op.create_table(
        "tenant_policies",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_id", sa.String(length=48), nullable=True),
        sa.Column(
            "allowed_models",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("max_input_tokens_per_run", sa.Integer(), nullable=True),
        sa.Column("max_output_tokens_per_call", sa.Integer(), nullable=True),
        sa.Column("daily_budget", sa.Numeric(precision=18, scale=10), nullable=True),
        sa.Column("monthly_budget", sa.Numeric(precision=18, scale=10), nullable=True),
        sa.Column("currency", sa.String(length=3), nullable=False),
        sa.Column("alert_threshold_pct", sa.Integer(), nullable=False),
        sa.Column("max_concurrent_runs", sa.Integer(), nullable=True),
        sa.Column("max_concurrent_runs_per_subject", sa.Integer(), nullable=False),
        sa.Column("rate_limit_subject_per_minute", sa.Integer(), nullable=True),
        sa.Column("rate_limit_site_per_minute", sa.Integer(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_tenant_policies_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_tenant_policies")),
        sa.UniqueConstraint("tenant_id", "assistant_id", name=op.f("uq_tenant_policies_tenant_id")),
    )
    op.create_index(
        op.f("ix_tenant_policies_tenant_id"), "tenant_policies", ["tenant_id"], unique=False
    )
    op.create_table(
        "usage_events",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=True),
        sa.Column("assistant_id", sa.String(length=48), nullable=True),
        sa.Column("conversation_id", sa.String(length=48), nullable=True),
        sa.Column("run_id", sa.String(length=48), nullable=True),
        sa.Column("iteration", sa.Integer(), nullable=False),
        sa.Column("skill", sa.String(length=64), nullable=True),
        sa.Column("subject", sa.String(length=128), nullable=True),
        sa.Column("provider", sa.String(length=64), nullable=False),
        sa.Column("model", sa.String(length=128), nullable=False),
        sa.Column("operation", sa.String(length=32), nullable=False),
        sa.Column("provider_request_id", sa.String(length=128), nullable=True),
        sa.Column("input_tokens", sa.Integer(), nullable=False),
        sa.Column("cached_tokens", sa.Integer(), nullable=False),
        sa.Column("output_tokens", sa.Integer(), nullable=False),
        sa.Column("reasoning_tokens", sa.Integer(), nullable=False),
        sa.Column("total_tokens", sa.Integer(), nullable=False),
        sa.Column("latency_ms", sa.Integer(), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("error_code", sa.String(length=64), nullable=True),
        sa.Column("price_id", sa.String(length=48), nullable=True),
        sa.Column("input_cost", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("cached_cost", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("output_cost", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("total_cost", sa.Numeric(precision=18, scale=10), nullable=False),
        sa.Column("currency", sa.String(length=3), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_usage_events_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_usage_events")),
    )
    op.create_index(
        "ix_usage_events_period", "usage_events", ["tenant_id", "created_at"], unique=False
    )
    op.create_index("ix_usage_events_run", "usage_events", ["run_id"], unique=False)
    op.create_index(op.f("ix_usage_events_tenant_id"), "usage_events", ["tenant_id"], unique=False)
    op.create_table(
        "api_clients",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=True),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column("client_id", sa.String(length=64), nullable=False),
        sa.Column("secret_hash", sa.String(length=255), nullable=False),
        sa.Column(
            "scopes",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("last_used_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("rotated_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["site_id"], ["sites.id"], name=op.f("fk_api_clients_site_id_sites"), ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_api_clients_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_api_clients")),
        sa.UniqueConstraint("client_id", name=op.f("uq_api_clients_client_id")),
    )
    op.create_index(op.f("ix_api_clients_tenant_id"), "api_clients", ["tenant_id"], unique=False)
    op.create_table(
        "assistants",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("key", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column(
            "draft_config",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("agent_config_id", sa.String(length=48), nullable=True),
        sa.Column("published_version", sa.Integer(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["agent_config_id"],
            ["agent_configs.id"],
            name=op.f("fk_assistants_agent_config_id_agent_configs"),
            ondelete="SET NULL",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_assistants_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_assistants")),
        sa.UniqueConstraint("tenant_id", "key", name=op.f("uq_assistants_tenant_id")),
    )
    op.create_index(op.f("ix_assistants_tenant_id"), "assistants", ["tenant_id"], unique=False)
    op.create_table(
        "knowledge_bases",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=True),
        sa.Column("key", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=200), nullable=False),
        sa.Column("description", sa.Text(), nullable=False),
        sa.Column("default_locale", sa.String(length=10), nullable=False),
        sa.Column(
            "chunking",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["site_id"],
            ["sites.id"],
            name=op.f("fk_knowledge_bases_site_id_sites"),
            ondelete="SET NULL",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_knowledge_bases_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_knowledge_bases")),
        sa.UniqueConstraint("tenant_id", "key", name=op.f("uq_knowledge_bases_tenant_id")),
    )
    op.create_index(
        op.f("ix_knowledge_bases_tenant_id"), "knowledge_bases", ["tenant_id"], unique=False
    )
    op.create_table(
        "assistant_versions",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_id", sa.String(length=48), nullable=False),
        sa.Column("version", sa.Integer(), nullable=False),
        sa.Column(
            "config",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "agent_policy",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("note", sa.String(length=500), nullable=False),
        sa.Column("created_by", sa.String(length=48), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["assistant_id"],
            ["assistants.id"],
            name=op.f("fk_assistant_versions_assistant_id_assistants"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_assistant_versions_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_assistant_versions")),
        sa.UniqueConstraint(
            "assistant_id", "version", name=op.f("uq_assistant_versions_assistant_id")
        ),
    )
    op.create_index(
        op.f("ix_assistant_versions_assistant_id"),
        "assistant_versions",
        ["assistant_id"],
        unique=False,
    )
    op.create_index(
        op.f("ix_assistant_versions_tenant_id"), "assistant_versions", ["tenant_id"], unique=False
    )
    op.create_table(
        "conversations",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_id", sa.String(length=48), nullable=False),
        sa.Column("subject", sa.String(length=128), nullable=False),
        sa.Column("subject_type", sa.String(length=20), nullable=False),
        sa.Column("locale", sa.String(length=10), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("title", sa.String(length=200), nullable=False),
        sa.Column("summary", sa.Text(), nullable=False),
        sa.Column(
            "context_state",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("last_message_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("archived_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["assistant_id"],
            ["assistants.id"],
            name=op.f("fk_conversations_assistant_id_assistants"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["site_id"],
            ["sites.id"],
            name=op.f("fk_conversations_site_id_sites"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_conversations_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_conversations")),
    )
    op.create_index(
        "ix_conversations_subject",
        "conversations",
        ["tenant_id", "site_id", "subject"],
        unique=False,
    )
    op.create_index(
        op.f("ix_conversations_tenant_id"), "conversations", ["tenant_id"], unique=False
    )
    op.create_table(
        "documents",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=True),
        sa.Column("knowledge_base_id", sa.String(length=48), nullable=False),
        sa.Column("source_id", sa.String(length=256), nullable=False),
        sa.Column("source_type", sa.String(length=40), nullable=False),
        sa.Column("locale", sa.String(length=10), nullable=False),
        sa.Column("title", sa.String(length=500), nullable=False),
        sa.Column("canonical_url", sa.String(length=2048), nullable=True),
        sa.Column(
            "acl",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("version", sa.String(length=64), nullable=False),
        sa.Column("content_hash", sa.String(length=80), nullable=False),
        sa.Column("content_format", sa.String(length=20), nullable=False),
        sa.Column(
            "metadata",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("text", sa.Text(), nullable=True),
        sa.Column(
            "reference",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=True,
        ),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("error", sa.Text(), nullable=True),
        sa.Column("attempts", sa.Integer(), nullable=False),
        sa.Column("chunk_count", sa.Integer(), nullable=False),
        sa.Column("embedding_index_id", sa.String(length=48), nullable=True),
        sa.Column("source_created_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("source_updated_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("indexed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("deleted_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["knowledge_base_id"],
            ["knowledge_bases.id"],
            name=op.f("fk_documents_knowledge_base_id_knowledge_bases"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_documents_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_documents")),
        sa.UniqueConstraint(
            "tenant_id", "knowledge_base_id", "source_id", name=op.f("uq_documents_tenant_id")
        ),
    )
    op.create_index(
        op.f("ix_documents_knowledge_base_id"), "documents", ["knowledge_base_id"], unique=False
    )
    op.create_index("ix_documents_status", "documents", ["tenant_id", "status"], unique=False)
    op.create_index(op.f("ix_documents_tenant_id"), "documents", ["tenant_id"], unique=False)
    op.create_table(
        "assistant_skills",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_version_id", sa.String(length=48), nullable=False),
        sa.Column("skill_name", sa.String(length=64), nullable=False),
        sa.Column("skill_version", sa.String(length=32), nullable=False),
        sa.Column("enabled", sa.Boolean(), nullable=False),
        sa.Column(
            "config",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.ForeignKeyConstraint(
            ["assistant_id"],
            ["assistants.id"],
            name=op.f("fk_assistant_skills_assistant_id_assistants"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["assistant_version_id"],
            ["assistant_versions.id"],
            name=op.f("fk_assistant_skills_assistant_version_id_assistant_versions"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_assistant_skills_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_assistant_skills")),
        sa.UniqueConstraint(
            "assistant_version_id",
            "skill_name",
            name=op.f("uq_assistant_skills_assistant_version_id"),
        ),
    )
    op.create_index(
        op.f("ix_assistant_skills_assistant_id"), "assistant_skills", ["assistant_id"], unique=False
    )
    op.create_index(
        op.f("ix_assistant_skills_assistant_version_id"),
        "assistant_skills",
        ["assistant_version_id"],
        unique=False,
    )
    op.create_index(
        op.f("ix_assistant_skills_tenant_id"), "assistant_skills", ["tenant_id"], unique=False
    )
    op.create_table(
        "chunks",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=True),
        sa.Column("knowledge_base_id", sa.String(length=48), nullable=False),
        sa.Column("document_id", sa.String(length=48), nullable=False),
        sa.Column("document_version", sa.String(length=64), nullable=False),
        sa.Column("position", sa.Integer(), nullable=False),
        sa.Column("title", sa.String(length=500), nullable=False),
        sa.Column(
            "heading_path",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("text", sa.Text(), nullable=False),
        sa.Column("url", sa.String(length=2048), nullable=True),
        sa.Column("page", sa.Integer(), nullable=True),
        sa.Column("token_count", sa.Integer(), nullable=False),
        sa.Column("content_hash", sa.String(length=64), nullable=False),
        sa.Column("embedding_index_id", sa.String(length=48), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["document_id"],
            ["documents.id"],
            name=op.f("fk_chunks_document_id_documents"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_chunks_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_chunks")),
    )
    op.create_index("ix_chunks_document", "chunks", ["document_id", "position"], unique=False)
    op.create_index(
        op.f("ix_chunks_knowledge_base_id"), "chunks", ["knowledge_base_id"], unique=False
    )
    op.create_index(op.f("ix_chunks_tenant_id"), "chunks", ["tenant_id"], unique=False)
    op.create_table(
        "messages",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=False),
        sa.Column("conversation_id", sa.String(length=48), nullable=False),
        sa.Column("run_id", sa.String(length=48), nullable=True),
        sa.Column("role", sa.String(length=20), nullable=False),
        sa.Column("content", sa.Text(), nullable=False),
        sa.Column(
            "presentation",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=True,
        ),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["conversation_id"],
            ["conversations.id"],
            name=op.f("fk_messages_conversation_id_conversations"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_messages_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_messages")),
    )
    op.create_index(
        "ix_messages_conversation", "messages", ["conversation_id", "created_at"], unique=False
    )
    op.create_index(op.f("ix_messages_tenant_id"), "messages", ["tenant_id"], unique=False)
    op.create_table(
        "runs",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("site_id", sa.String(length=48), nullable=False),
        sa.Column("conversation_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_id", sa.String(length=48), nullable=False),
        sa.Column("assistant_version", sa.Integer(), nullable=True),
        sa.Column("subject", sa.String(length=128), nullable=False),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("skill", sa.String(length=64), nullable=True),
        sa.Column("error_code", sa.String(length=64), nullable=True),
        sa.Column("iterations", sa.Integer(), nullable=False),
        sa.Column("tool_calls_count", sa.Integer(), nullable=False),
        sa.Column("rag_used", sa.Boolean(), nullable=False),
        sa.Column("repaired", sa.Boolean(), nullable=False),
        sa.Column(
            "structured_result",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=True,
        ),
        sa.Column(
            "context_report",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "usage_summary",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("trace_id", sa.String(length=64), nullable=True),
        sa.Column("latency_ms", sa.Integer(), nullable=True),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.ForeignKeyConstraint(
            ["conversation_id"],
            ["conversations.id"],
            name=op.f("fk_runs_conversation_id_conversations"),
            ondelete="CASCADE",
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_runs_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_runs")),
    )
    op.create_index("ix_runs_conversation", "runs", ["conversation_id", "started_at"], unique=False)
    op.create_index(op.f("ix_runs_tenant_id"), "runs", ["tenant_id"], unique=False)
    op.create_table(
        "tool_calls",
        sa.Column("id", sa.String(length=48), nullable=False),
        sa.Column("tenant_id", sa.String(length=48), nullable=False),
        sa.Column("run_id", sa.String(length=48), nullable=False),
        sa.Column("iteration", sa.Integer(), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column(
            "arguments",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column(
            "result_summary",
            sa.JSON().with_variant(postgresql.JSONB(astext_type=sa.Text()), "postgresql"),
            nullable=False,
        ),
        sa.Column("status", sa.String(length=20), nullable=False),
        sa.Column("error_code", sa.String(length=64), nullable=True),
        sa.Column("latency_ms", sa.Integer(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(
            ["run_id"], ["runs.id"], name=op.f("fk_tool_calls_run_id_runs"), ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["tenant_id"],
            ["tenants.id"],
            name=op.f("fk_tool_calls_tenant_id_tenants"),
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id", name=op.f("pk_tool_calls")),
    )
    op.create_index(op.f("ix_tool_calls_run_id"), "tool_calls", ["run_id"], unique=False)
    op.create_index(op.f("ix_tool_calls_tenant_id"), "tool_calls", ["tenant_id"], unique=False)

    _enable_rls()


def _enable_rls() -> None:
    """RLS forzado: ni el propietario de las tablas puede cruzar tenants sin bypass explícito."""
    if op.get_bind().dialect.name != "postgresql":
        return
    for table in TENANT_TABLES:
        op.execute(f"ALTER TABLE {table} ENABLE ROW LEVEL SECURITY")
        op.execute(f"ALTER TABLE {table} FORCE ROW LEVEL SECURITY")
        op.execute(
            f"CREATE POLICY tenant_isolation ON {table} USING ({TENANT_PREDICATE}) WITH CHECK ({TENANT_PREDICATE})"
        )


def _disable_rls() -> None:
    if op.get_bind().dialect.name != "postgresql":
        return
    for table in TENANT_TABLES:
        op.execute(f"DROP POLICY IF EXISTS tenant_isolation ON {table}")
        op.execute(f"ALTER TABLE {table} NO FORCE ROW LEVEL SECURITY")
        op.execute(f"ALTER TABLE {table} DISABLE ROW LEVEL SECURITY")


def downgrade() -> None:
    _disable_rls()
    op.drop_index(op.f("ix_tool_calls_tenant_id"), table_name="tool_calls")
    op.drop_index(op.f("ix_tool_calls_run_id"), table_name="tool_calls")
    op.drop_table("tool_calls")
    op.drop_index(op.f("ix_runs_tenant_id"), table_name="runs")
    op.drop_index("ix_runs_conversation", table_name="runs")
    op.drop_table("runs")
    op.drop_index(op.f("ix_messages_tenant_id"), table_name="messages")
    op.drop_index("ix_messages_conversation", table_name="messages")
    op.drop_table("messages")
    op.drop_index(op.f("ix_chunks_tenant_id"), table_name="chunks")
    op.drop_index(op.f("ix_chunks_knowledge_base_id"), table_name="chunks")
    op.drop_index("ix_chunks_document", table_name="chunks")
    op.drop_table("chunks")
    op.drop_index(op.f("ix_assistant_skills_tenant_id"), table_name="assistant_skills")
    op.drop_index(op.f("ix_assistant_skills_assistant_version_id"), table_name="assistant_skills")
    op.drop_index(op.f("ix_assistant_skills_assistant_id"), table_name="assistant_skills")
    op.drop_table("assistant_skills")
    op.drop_index(op.f("ix_documents_tenant_id"), table_name="documents")
    op.drop_index("ix_documents_status", table_name="documents")
    op.drop_index(op.f("ix_documents_knowledge_base_id"), table_name="documents")
    op.drop_table("documents")
    op.drop_index(op.f("ix_conversations_tenant_id"), table_name="conversations")
    op.drop_index("ix_conversations_subject", table_name="conversations")
    op.drop_table("conversations")
    op.drop_index(op.f("ix_assistant_versions_tenant_id"), table_name="assistant_versions")
    op.drop_index(op.f("ix_assistant_versions_assistant_id"), table_name="assistant_versions")
    op.drop_table("assistant_versions")
    op.drop_index(op.f("ix_knowledge_bases_tenant_id"), table_name="knowledge_bases")
    op.drop_table("knowledge_bases")
    op.drop_index(op.f("ix_assistants_tenant_id"), table_name="assistants")
    op.drop_table("assistants")
    op.drop_index(op.f("ix_api_clients_tenant_id"), table_name="api_clients")
    op.drop_table("api_clients")
    op.drop_index(op.f("ix_usage_events_tenant_id"), table_name="usage_events")
    op.drop_index("ix_usage_events_run", table_name="usage_events")
    op.drop_index("ix_usage_events_period", table_name="usage_events")
    op.drop_table("usage_events")
    op.drop_index(op.f("ix_tenant_policies_tenant_id"), table_name="tenant_policies")
    op.drop_table("tenant_policies")
    op.drop_index(op.f("ix_sites_tenant_id"), table_name="sites")
    op.drop_table("sites")
    op.drop_index(op.f("ix_retention_policies_tenant_id"), table_name="retention_policies")
    op.drop_table("retention_policies")
    op.drop_index(op.f("ix_audit_payloads_tenant_id"), table_name="audit_payloads")
    op.drop_index(op.f("ix_audit_payloads_run_id"), table_name="audit_payloads")
    op.drop_table("audit_payloads")
    op.drop_index(op.f("ix_audit_events_tenant_id"), table_name="audit_events")
    op.drop_index("ix_audit_events_period", table_name="audit_events")
    op.drop_table("audit_events")
    op.drop_index(op.f("ix_agent_configs_tenant_id"), table_name="agent_configs")
    op.drop_table("agent_configs")
    op.drop_index(op.f("ix_admin_users_tenant_id"), table_name="admin_users")
    op.drop_table("admin_users")
    op.drop_index(op.f("ix_action_executions_tenant_id"), table_name="action_executions")
    op.drop_index(op.f("ix_action_executions_run_id"), table_name="action_executions")
    op.drop_index(op.f("ix_action_executions_conversation_id"), table_name="action_executions")
    op.drop_table("action_executions")
    op.drop_table("tenants")
    op.drop_table("skills")
    op.drop_index("ix_model_prices_lookup", table_name="model_prices")
    op.drop_table("model_prices")
    op.drop_table("embedding_indexes")
