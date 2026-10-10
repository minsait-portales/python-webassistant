"""Motor y sesiones con contexto de tenant (CG-02.3).

En PostgreSQL cada transacción fija ``app.tenant_id`` mediante ``set_config``
(ámbito local a la transacción). Las políticas RLS creadas en la migración
inicial impiden ver o escribir filas de otro tenant aunque una consulta olvide
el filtro. Las operaciones de plataforma usan ``bypass_rls`` explícitamente.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from pathlib import Path

from sqlalchemy import event, text
from sqlalchemy.engine import make_url
from sqlalchemy.ext.asyncio import (
    AsyncEngine,
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)
from sqlalchemy.pool import StaticPool

from webassistant.db.base import Base


class Database:
    def __init__(
        self,
        url: str,
        *,
        echo: bool = False,
        pool_size: int = 10,
        max_overflow: int = 10,
        rls: bool = True,
    ) -> None:
        self.url = url
        parsed = make_url(url)
        self.is_postgres = parsed.get_backend_name() == "postgresql"
        self.is_sqlite = parsed.get_backend_name() == "sqlite"
        self.rls = rls and self.is_postgres
        if self.is_sqlite:
            database = parsed.database or ""
            if database and database != ":memory:":
                Path(database).parent.mkdir(parents=True, exist_ok=True)
            kwargs: dict[str, object] = {"connect_args": {"check_same_thread": False}}
            if not database or database == ":memory:":
                kwargs["poolclass"] = StaticPool
            self.engine: AsyncEngine = create_async_engine(url, echo=echo, **kwargs)
            event.listen(self.engine.sync_engine, "connect", _sqlite_pragmas)
        else:
            self.engine = create_async_engine(
                url,
                echo=echo,
                pool_size=pool_size,
                max_overflow=max_overflow,
                pool_pre_ping=True,
            )
        self._sessionmaker = async_sessionmaker(
            self.engine, expire_on_commit=False, autoflush=False
        )

    @asynccontextmanager
    async def session(
        self, tenant_id: str | None = None, *, bypass_rls: bool = False
    ) -> AsyncIterator[AsyncSession]:
        """Sesión transaccional: confirma al salir sin error, revierte si hay excepción."""
        async with self._sessionmaker() as session, session.begin():
            if self.rls:
                await session.execute(
                    text(
                        "SELECT set_config('app.tenant_id', :tenant, true), set_config('app.bypass_rls', :bypass, true)"
                    ),
                    {"tenant": tenant_id or "", "bypass": "on" if bypass_rls else "off"},
                )
            yield session

    async def create_all(self) -> None:
        """Crea el esquema directamente (pruebas y SQLite). En PostgreSQL usar Alembic."""
        async with self.engine.begin() as conn:
            await conn.run_sync(Base.metadata.create_all)

    async def drop_all(self) -> None:
        async with self.engine.begin() as conn:
            await conn.run_sync(Base.metadata.drop_all)

    async def ping(self) -> None:
        async with self.engine.connect() as conn:
            await conn.execute(text("SELECT 1"))

    async def current_revision(self) -> str | None:
        async with self.engine.connect() as conn:
            try:
                result = await conn.execute(text("SELECT version_num FROM alembic_version"))
            except Exception:
                return None
            row = result.first()
            return str(row[0]) if row else None

    async def dispose(self) -> None:
        await self.engine.dispose()


def _sqlite_pragmas(dbapi_connection, connection_record) -> None:  # type: ignore[no-untyped-def]
    cursor = dbapi_connection.cursor()
    cursor.execute("PRAGMA foreign_keys=ON")
    cursor.execute("PRAGMA journal_mode=WAL")
    cursor.execute("PRAGMA busy_timeout=10000")
    cursor.close()
