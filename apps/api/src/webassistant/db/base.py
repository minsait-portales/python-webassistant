"""Base declarativa y tipos portables (PostgreSQL en piloto/producción, SQLite en pruebas)."""

from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import JSON, DateTime, MetaData, Numeric, String, TypeDecorator
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.engine import Dialect
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column

NAMING_CONVENTION = {
    "ix": "ix_%(column_0_label)s",
    "uq": "uq_%(table_name)s_%(column_0_name)s",
    "ck": "ck_%(table_name)s_%(constraint_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}

JSONType = JSON().with_variant(JSONB(), "postgresql")
Money = Numeric(18, 10)


def utcnow() -> datetime:
    return datetime.now(UTC)


class UTCDateTime(TypeDecorator[datetime]):
    """Fecha siempre consciente de zona horaria y almacenada en UTC."""

    impl = DateTime(timezone=True)
    cache_ok = True

    def process_bind_param(self, value: datetime | None, dialect: Dialect) -> datetime | None:
        if value is None:
            return None
        if value.tzinfo is None:
            value = value.replace(tzinfo=UTC)
        return value.astimezone(UTC)

    def process_result_value(self, value: datetime | None, dialect: Dialect) -> datetime | None:
        if value is None:
            return None
        if value.tzinfo is None:
            return value.replace(tzinfo=UTC)
        return value.astimezone(UTC)


class Base(DeclarativeBase):
    metadata = MetaData(naming_convention=NAMING_CONVENTION)
    type_annotation_map = {  # noqa: RUF012
        dict[str, Any]: JSONType,
        list[Any]: JSONType,
        list[str]: JSONType,
        datetime: UTCDateTime(),
        Decimal: Money,
    }


def id_column(**kwargs: Any) -> Mapped[str]:
    return mapped_column(String(48), primary_key=True, **kwargs)


class TimestampMixin:
    created_at: Mapped[datetime] = mapped_column(default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(default=utcnow, onupdate=utcnow)


# Tablas cuyas filas pertenecen a un tenant: llevan ``tenant_id`` obligatorio y
# reciben una política RLS en PostgreSQL (ver migración inicial).
TENANT_SCOPED_TABLES: tuple[str, ...] = (
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
)
