"""Composición de servicios compartida por la API y el worker."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from webassistant.config import Settings
from webassistant.conversations.service import ConversationService
from webassistant.db.session import Database
from webassistant.gateway.gateway import (
    ModelGateway,
    build_providers,
    build_resilience,
    default_embedding,
)
from webassistant.gateway.policies import PolicyService
from webassistant.gateway.pricing import PriceResolver
from webassistant.kv import KeyValueStore, create_store
from webassistant.observability.logging import get_logger
from webassistant.orchestrator.actions import ActionService
from webassistant.orchestrator.agent import AgentRunner
from webassistant.orchestrator.assistants import AssistantService
from webassistant.orchestrator.events import RunEventBus
from webassistant.orchestrator.service import ChatService
from webassistant.queue import ArqQueue, InlineQueue, JobQueue
from webassistant.rag.indexes import EmbeddingIndexManager
from webassistant.rag.ingestion import IngestionService
from webassistant.rag.pipeline import DownloadSettings, IndexingPipeline
from webassistant.rag.search import SearchService
from webassistant.rag.vector_store import VectorStore
from webassistant.security.crypto import PayloadCipher
from webassistant.security.ratelimit import RateLimiter
from webassistant.security.tokens import TokenService
from webassistant.services.sites import SiteDirectory
from webassistant.skills.matcher import SkillMatcher
from webassistant.skills.registry import SkillRegistry
from webassistant.skills.runtime import SkillRuntime
from webassistant.usage.audit import AuditLog
from webassistant.usage.recorder import UsageRecorder

logger = get_logger("webassistant.container")


@dataclass
class Services:
    settings: Settings
    db: Database
    kv: KeyValueStore
    cipher: PayloadCipher
    audit: AuditLog
    prices: PriceResolver
    recorder: UsageRecorder
    gateway: ModelGateway
    registry: SkillRegistry
    matcher: SkillMatcher
    runtime: SkillRuntime
    store: VectorStore
    indexes: EmbeddingIndexManager
    queue: JobQueue
    ingestion: IngestionService
    pipeline: IndexingPipeline
    search: SearchService
    assistants: AssistantService
    conversations: ConversationService
    policies: PolicyService
    events: RunEventBus
    agent: AgentRunner
    chat: ChatService
    actions: ActionService
    tokens: TokenService
    rate_limiter: RateLimiter
    sites: SiteDirectory
    started: bool = False

    async def startup(self, *, create_schema: bool = False) -> None:
        if create_schema:
            await self.db.create_all()
        self.registry.discover()
        await self.registry.sync_to_db(self.db)
        provider, model = default_embedding(self.settings)
        dimension = self.gateway.provider(provider).embedding_dimension(model)
        await self.indexes.ensure_active(provider, model, dimension)
        self.started = True
        logger.info("Servicios iniciados (%s skills disponibles)", len(self.registry.available()))

    async def shutdown(self) -> None:
        if isinstance(self.queue, InlineQueue):
            await self.queue.drain()
        await self.queue.close()
        await self.gateway.close()
        await self.store.close()
        await self.kv.close()
        await self.db.dispose()


def build_services(settings: Settings, *, overrides: dict[str, Any] | None = None) -> Services:
    overrides = overrides or {}
    db = overrides.get("db") or Database(
        settings.database_url,
        echo=settings.database_echo,
        pool_size=settings.database_pool_size,
        max_overflow=settings.database_max_overflow,
        rls=settings.database_rls,
    )
    kv = overrides.get("kv") or create_store(settings.redis_url)
    cipher = PayloadCipher(settings.encryption_key.get_secret_value() or None)
    audit = AuditLog(db, cipher)
    prices = PriceResolver(db)
    recorder = UsageRecorder(db, prices, audit)
    providers = overrides.get("providers") or build_providers(settings)
    gateway = ModelGateway(providers, build_resilience(settings), recorder)
    registry = overrides.get("registry") or SkillRegistry(
        paths=settings.skill_paths,
        load_builtin=settings.load_builtin_skills,
        load_entry_points=settings.load_entry_point_skills,
    )
    matcher = SkillMatcher(registry)
    runtime = SkillRuntime()
    store = overrides.get("store") or VectorStore.connect(
        url=settings.qdrant_url,
        api_key=settings.qdrant_api_key.get_secret_value() if settings.qdrant_api_key else None,
        location=settings.qdrant_location,
        timeout=settings.qdrant_timeout_seconds,
    )
    indexes = EmbeddingIndexManager(db, store, settings.qdrant_collection_prefix)
    queue: JobQueue = overrides.get("queue") or (
        ArqQueue(settings.redis_url, settings.queue_name)
        if settings.queue_mode == "redis" and settings.redis_url
        else InlineQueue()
    )
    ingestion = IngestionService(db, store, indexes, queue)
    pipeline = IndexingPipeline(
        db,
        store,
        indexes,
        gateway,
        DownloadSettings(
            allowed_hosts=settings.ingest_allowed_download_hosts,
            max_bytes=settings.ingest_max_download_bytes,
            timeout=settings.ingest_download_timeout_seconds,
            temp_dir=settings.ingest_temp_dir,
        ),
    )
    search = SearchService(db, store, indexes, gateway)
    assistants = AssistantService(db)
    conversations = ConversationService(db)
    policies = PolicyService(kv, audit)
    events = RunEventBus(kv)
    agent = AgentRunner(db, gateway, search, runtime)
    chat = ChatService(db, kv, assistants, conversations, matcher, agent, policies, events, audit)
    tokens = TokenService(settings)
    actions = ActionService(db, assistants, matcher, tokens, audit)
    services = Services(
        settings=settings,
        db=db,
        kv=kv,
        cipher=cipher,
        audit=audit,
        prices=prices,
        recorder=recorder,
        gateway=gateway,
        registry=registry,
        matcher=matcher,
        runtime=runtime,
        store=store,
        indexes=indexes,
        queue=queue,
        ingestion=ingestion,
        pipeline=pipeline,
        search=search,
        assistants=assistants,
        conversations=conversations,
        policies=policies,
        events=events,
        agent=agent,
        chat=chat,
        actions=actions,
        tokens=tokens,
        rate_limiter=RateLimiter(kv),
        sites=SiteDirectory(db),
    )
    if isinstance(queue, InlineQueue):
        from webassistant.jobs import register_inline_jobs

        register_inline_jobs(queue, services)
    return services
