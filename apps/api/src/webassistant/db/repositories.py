"""Repositorios y unidad de trabajo (CG-02.2, CG-02.3).

Los servicios no construyen SQL: piden datos a repositorios que aplican
siempre el filtro ``tenant_id`` del contexto. Con PostgreSQL, RLS actúa como
segunda barrera; en SQLite (pruebas) la barrera es este filtro.
"""

from __future__ import annotations

from collections.abc import Sequence
from datetime import datetime
from typing import Any, ClassVar, Generic, TypeVar

from sqlalchemy import Select, delete, func, select, update
from sqlalchemy.ext.asyncio import AsyncSession

from webassistant.db import models as m
from webassistant.db.base import Base
from webassistant.db.session import Database
from webassistant.errors import AppError, ErrorCode, not_found

M = TypeVar("M", bound=Base)


class TenantScopeError(RuntimeError):
    """Se intentó operar sobre datos de tenant sin contexto de tenant."""


class Repository(Generic[M]):
    model: ClassVar[type[Base]]
    tenant_scoped: ClassVar[bool] = True
    label: ClassVar[str] = "Recurso"

    def __init__(
        self, session: AsyncSession, tenant_id: str | None, *, platform: bool = False
    ) -> None:
        self.session = session
        self.tenant_id = tenant_id
        self.platform = platform
        if self.tenant_scoped and tenant_id is None and not platform:
            raise TenantScopeError(f"{type(self).__name__} requiere tenant_id")

    def _col(self, name: str) -> Any:
        return getattr(self.model, name)

    def scoped(self, stmt: Select[Any]) -> Select[Any]:
        if self.tenant_scoped and self.tenant_id is not None:
            stmt = stmt.where(self._col("tenant_id") == self.tenant_id)
        return stmt

    def query(self) -> Select[Any]:
        return self.scoped(select(self.model))

    async def get(self, entity_id: str) -> M | None:
        pk = next(iter(self.model.__table__.primary_key.columns))  # type: ignore[attr-defined]
        stmt = self.query().where(pk == entity_id)
        return (await self.session.execute(stmt)).scalars().first()

    async def require(self, entity_id: str) -> M:
        entity = await self.get(entity_id)
        if entity is None:
            raise not_found(self.label)
        return entity

    async def list(
        self,
        *conditions: Any,
        order_by: Any = None,
        limit: int | None = None,
        offset: int = 0,
    ) -> Sequence[M]:
        stmt = self.query()
        for condition in conditions:
            stmt = stmt.where(condition)
        if order_by is not None:
            stmt = stmt.order_by(*(order_by if isinstance(order_by, list | tuple) else [order_by]))
        if offset:
            stmt = stmt.offset(offset)
        if limit is not None:
            stmt = stmt.limit(limit)
        return (await self.session.execute(stmt)).scalars().all()

    async def first(self, *conditions: Any, order_by: Any = None) -> M | None:
        rows = await self.list(*conditions, order_by=order_by, limit=1)
        return rows[0] if rows else None

    async def count(self, *conditions: Any) -> int:
        stmt = select(func.count()).select_from(self.model)
        if self.tenant_scoped and self.tenant_id is not None:
            stmt = stmt.where(self._col("tenant_id") == self.tenant_id)
        for condition in conditions:
            stmt = stmt.where(condition)
        return int((await self.session.execute(stmt)).scalar_one())

    async def add(self, entity: M) -> M:
        if self.tenant_scoped:
            current = getattr(entity, "tenant_id", None)
            if current is None:
                if self.tenant_id is None:
                    raise TenantScopeError("No se puede crear una entidad de tenant sin tenant_id")
                entity.tenant_id = self.tenant_id  # type: ignore[attr-defined]
            elif self.tenant_id is not None and current != self.tenant_id:
                raise AppError(ErrorCode.FORBIDDEN, "La entidad pertenece a otro tenant")
        self.session.add(entity)
        await self.session.flush()
        return entity

    async def remove(self, entity: M) -> None:
        if (
            self.tenant_scoped
            and self.tenant_id is not None
            and getattr(entity, "tenant_id", None) != self.tenant_id
        ):
            raise AppError(ErrorCode.FORBIDDEN, "La entidad pertenece a otro tenant")
        await self.session.delete(entity)
        await self.session.flush()

    async def delete_where(self, *conditions: Any) -> int:
        stmt = delete(self.model)
        if self.tenant_scoped and self.tenant_id is not None:
            stmt = stmt.where(self._col("tenant_id") == self.tenant_id)
        for condition in conditions:
            stmt = stmt.where(condition)
        result = await self.session.execute(stmt)
        return int(getattr(result, "rowcount", 0) or 0)

    async def update_where(self, values: dict[str, Any], *conditions: Any) -> int:
        stmt = update(self.model).values(**values)
        if self.tenant_scoped and self.tenant_id is not None:
            stmt = stmt.where(self._col("tenant_id") == self.tenant_id)
        for condition in conditions:
            stmt = stmt.where(condition)
        result = await self.session.execute(stmt.execution_options(synchronize_session=False))
        return int(getattr(result, "rowcount", 0) or 0)


class TenantRepository(Repository[m.Tenant]):
    model = m.Tenant
    tenant_scoped = False
    label = "Tenant"

    def query(self) -> Select[Any]:
        stmt = select(m.Tenant)
        if self.tenant_id is not None:
            stmt = stmt.where(m.Tenant.id == self.tenant_id)
        return stmt

    async def by_slug(self, slug: str) -> m.Tenant | None:
        return await self.first(m.Tenant.slug == slug)


class SiteRepository(Repository[m.Site]):
    model = m.Site
    label = "Site"

    async def by_public_id(self, public_id: str) -> m.Site | None:
        return await self.first(m.Site.public_id == public_id)


class ApiClientRepository(Repository[m.ApiClient]):
    model = m.ApiClient
    label = "Cliente API"

    async def by_client_id(self, client_id: str) -> m.ApiClient | None:
        return await self.first(m.ApiClient.client_id == client_id)


class AdminUserRepository(Repository[m.AdminUser]):
    model = m.AdminUser
    tenant_scoped = False
    label = "Usuario administrador"

    def query(self) -> Select[Any]:
        stmt = select(m.AdminUser)
        if self.tenant_id is not None:
            stmt = stmt.where(m.AdminUser.tenant_id == self.tenant_id)
        return stmt

    async def by_email(self, email: str) -> m.AdminUser | None:
        return await self.first(func.lower(m.AdminUser.email) == email.strip().lower())


class AgentConfigRepository(Repository[m.AgentConfig]):
    model = m.AgentConfig
    label = "Configuración de agente"

    async def by_key(self, key: str) -> m.AgentConfig | None:
        return await self.first(m.AgentConfig.key == key)


class AssistantRepository(Repository[m.Assistant]):
    model = m.Assistant
    label = "Assistant"

    async def by_key(self, key: str) -> m.Assistant | None:
        return await self.first(m.Assistant.key == key)

    async def by_id_or_key(self, ref: str) -> m.Assistant | None:
        return await self.get(ref) or await self.by_key(ref)


class AssistantVersionRepository(Repository[m.AssistantVersion]):
    model = m.AssistantVersion
    label = "Versión de assistant"

    async def by_number(self, assistant_id: str, version: int) -> m.AssistantVersion | None:
        return await self.first(
            m.AssistantVersion.assistant_id == assistant_id, m.AssistantVersion.version == version
        )

    async def latest_number(self, assistant_id: str) -> int:
        stmt = select(func.max(m.AssistantVersion.version)).where(
            m.AssistantVersion.assistant_id == assistant_id
        )
        stmt = self.scoped(stmt)
        value = (await self.session.execute(stmt)).scalar()
        return int(value or 0)


class AssistantSkillRepository(Repository[m.AssistantSkill]):
    model = m.AssistantSkill
    label = "Skill de assistant"


class SkillRepository(Repository[m.Skill]):
    model = m.Skill
    tenant_scoped = False
    label = "Skill"


class KnowledgeBaseRepository(Repository[m.KnowledgeBase]):
    model = m.KnowledgeBase
    label = "Base de conocimiento"

    async def by_key(self, key: str) -> m.KnowledgeBase | None:
        return await self.first(m.KnowledgeBase.key == key)


class DocumentRepository(Repository[m.Document]):
    model = m.Document
    label = "Documento"

    async def by_source(self, knowledge_base_id: str, source_id: str) -> m.Document | None:
        return await self.first(
            m.Document.knowledge_base_id == knowledge_base_id, m.Document.source_id == source_id
        )

    async def status_counts(self, knowledge_base_id: str | None = None) -> dict[str, int]:
        stmt: Any = select(m.Document.status, func.count()).group_by(m.Document.status)
        stmt = self.scoped(stmt)
        if knowledge_base_id:
            stmt = stmt.where(m.Document.knowledge_base_id == knowledge_base_id)
        rows = (await self.session.execute(stmt)).all()
        return {str(status): int(count) for status, count in rows}


class ChunkRepository(Repository[m.Chunk]):
    model = m.Chunk
    label = "Chunk"

    async def by_ids(self, ids: Sequence[str]) -> list[m.Chunk]:
        if not ids:
            return []
        return list(await self.list(m.Chunk.id.in_(list(ids))))


class EmbeddingIndexRepository(Repository[m.EmbeddingIndex]):
    model = m.EmbeddingIndex
    tenant_scoped = False
    label = "Índice de embeddings"

    async def active(self) -> m.EmbeddingIndex | None:
        return await self.first(
            m.EmbeddingIndex.status == "active", order_by=m.EmbeddingIndex.activated_at.desc()
        )


class ConversationRepository(Repository[m.Conversation]):
    model = m.Conversation
    label = "Conversación"


class MessageRepository(Repository[m.Message]):
    model = m.Message
    label = "Mensaje"


class RunRepository(Repository[m.Run]):
    model = m.Run
    label = "Run"

    async def count_running(
        self, *, subject: str | None = None, since: datetime | None = None
    ) -> int:
        conditions: list[Any] = [m.Run.status == "running"]
        if subject is not None:
            conditions.append(m.Run.subject == subject)
        if since is not None:
            conditions.append(m.Run.started_at >= since)
        return await self.count(*conditions)


class ToolCallRepository(Repository[m.ToolCall]):
    model = m.ToolCall
    label = "Tool call"


class ActionExecutionRepository(Repository[m.ActionExecution]):
    model = m.ActionExecution
    label = "Ejecución de acción"

    async def by_idempotency_key(self, key: str) -> m.ActionExecution | None:
        return await self.first(m.ActionExecution.idempotency_key == key)


class ModelPriceRepository(Repository[m.ModelPrice]):
    model = m.ModelPrice
    tenant_scoped = False
    label = "Precio de modelo"

    async def valid_at(self, provider: str, model: str, at: datetime) -> m.ModelPrice | None:
        stmt = (
            select(m.ModelPrice)
            .where(m.ModelPrice.model == model)
            .where(m.ModelPrice.provider.in_([provider, "*"]))
            .where(m.ModelPrice.valid_from <= at)
            .where((m.ModelPrice.valid_to.is_(None)) | (m.ModelPrice.valid_to > at))
            .order_by(m.ModelPrice.valid_from.desc())
            .limit(1)
        )
        return (await self.session.execute(stmt)).scalars().first()


class UsageEventRepository(Repository[m.UsageEvent]):
    model = m.UsageEvent
    label = "Evento de uso"

    async def spent_since(self, since: datetime, assistant_id: str | None = None) -> float:
        stmt = select(func.coalesce(func.sum(m.UsageEvent.total_cost), 0)).where(
            m.UsageEvent.created_at >= since
        )
        stmt = self.scoped(stmt)
        if assistant_id:
            stmt = stmt.where(m.UsageEvent.assistant_id == assistant_id)
        return float((await self.session.execute(stmt)).scalar() or 0)


class TenantPolicyRepository(Repository[m.TenantPolicy]):
    model = m.TenantPolicy
    label = "Política"

    async def for_assistant(self, assistant_id: str | None) -> list[m.TenantPolicy]:
        conditions: list[Any] = [
            (m.TenantPolicy.assistant_id.is_(None)) | (m.TenantPolicy.assistant_id == assistant_id)
        ]
        return list(await self.list(*conditions))


class RetentionPolicyRepository(Repository[m.RetentionPolicy]):
    model = m.RetentionPolicy
    label = "Política de retención"


class AuditEventRepository(Repository[m.AuditEvent]):
    model = m.AuditEvent
    label = "Evento de auditoría"

    def scoped(self, stmt: Select[Any]) -> Select[Any]:
        if self.tenant_id is not None:
            stmt = stmt.where(m.AuditEvent.tenant_id == self.tenant_id)
        return stmt

    async def add(self, entity: m.AuditEvent) -> m.AuditEvent:
        if entity.tenant_id is None and self.tenant_id is not None:
            entity.tenant_id = self.tenant_id
        self.session.add(entity)
        await self.session.flush()
        return entity


class AuditPayloadRepository(Repository[m.AuditPayload]):
    model = m.AuditPayload
    label = "Payload de auditoría"


class UnitOfWork:
    """Agrupa repositorios sobre una única transacción con contexto de tenant."""

    def __init__(
        self, db: Database, tenant_id: str | None = None, *, platform: bool = False
    ) -> None:
        self.db = db
        self.tenant_id = tenant_id
        self.platform = platform
        self._cm: Any = None
        self.session: AsyncSession

    async def __aenter__(self) -> UnitOfWork:
        self._cm = self.db.session(self.tenant_id, bypass_rls=self.platform)
        self.session = await self._cm.__aenter__()
        tid, plat, s = self.tenant_id, self.platform, self.session
        self.tenants = TenantRepository(s, tid, platform=plat)
        self.sites = SiteRepository(s, tid, platform=plat)
        self.api_clients = ApiClientRepository(s, tid, platform=plat)
        self.admin_users = AdminUserRepository(s, tid, platform=plat)
        self.agent_configs = AgentConfigRepository(s, tid, platform=plat)
        self.assistants = AssistantRepository(s, tid, platform=plat)
        self.assistant_versions = AssistantVersionRepository(s, tid, platform=plat)
        self.assistant_skills = AssistantSkillRepository(s, tid, platform=plat)
        self.skills = SkillRepository(s, tid, platform=plat)
        self.knowledge_bases = KnowledgeBaseRepository(s, tid, platform=plat)
        self.documents = DocumentRepository(s, tid, platform=plat)
        self.chunks = ChunkRepository(s, tid, platform=plat)
        self.embedding_indexes = EmbeddingIndexRepository(s, tid, platform=plat)
        self.conversations = ConversationRepository(s, tid, platform=plat)
        self.messages = MessageRepository(s, tid, platform=plat)
        self.runs = RunRepository(s, tid, platform=plat)
        self.tool_calls = ToolCallRepository(s, tid, platform=plat)
        self.action_executions = ActionExecutionRepository(s, tid, platform=plat)
        self.model_prices = ModelPriceRepository(s, tid, platform=plat)
        self.usage_events = UsageEventRepository(s, tid, platform=plat)
        self.policies = TenantPolicyRepository(s, tid, platform=plat)
        self.retention_policies = RetentionPolicyRepository(s, tid, platform=plat)
        self.audit_events = AuditEventRepository(s, tid, platform=plat)
        self.audit_payloads = AuditPayloadRepository(s, tid, platform=plat)
        return self

    async def __aexit__(self, exc_type: Any, exc: Any, tb: Any) -> None:
        await self._cm.__aexit__(exc_type, exc, tb)

    async def flush(self) -> None:
        await self.session.flush()
