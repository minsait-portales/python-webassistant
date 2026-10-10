"""Pruebas sobre PostgreSQL real: migraciones y Row Level Security (CG-02.3, CG-02.4).

Se ejecutan si ``WEBASSISTANT_TEST_PG_ADMIN_URL`` apunta a un PostgreSQL con
permisos para crear bases de datos (por ejemplo, el de ``docker compose``).
"""

from __future__ import annotations

import os
import subprocess
import sys
import uuid
from pathlib import Path

import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import create_async_engine

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database

ADMIN_URL = os.environ.get("WEBASSISTANT_TEST_PG_ADMIN_URL")
API_DIR = Path(__file__).resolve().parents[1]

pytestmark = [
    pytest.mark.postgres,
    pytest.mark.skipif(not ADMIN_URL, reason="Sin PostgreSQL de pruebas"),
]


@pytest.fixture
async def database_url() -> str:  # type: ignore[misc]
    assert ADMIN_URL
    name = f"wa_test_{uuid.uuid4().hex[:8]}"
    admin = create_async_engine(ADMIN_URL, isolation_level="AUTOCOMMIT")
    async with admin.connect() as conn:
        await conn.execute(text(f'CREATE DATABASE "{name}"'))
    base = ADMIN_URL.rsplit("/", 1)[0]
    url = f"{base}/{name}"
    yield url
    async with admin.connect() as conn:
        await conn.execute(text(f'DROP DATABASE IF EXISTS "{name}" WITH (FORCE)'))
    await admin.dispose()


def _alembic(url: str, *args: str) -> None:
    env = {**os.environ, "WEBASSISTANT_DATABASE_URL": url}
    subprocess.run(
        [sys.executable, "-m", "alembic", *args],
        cwd=API_DIR,
        env=env,
        check=True,
        capture_output=True,
    )


async def test_migrations_upgrade_downgrade_and_rls(database_url: str) -> None:
    _alembic(database_url, "upgrade", "head")
    _alembic(database_url, "downgrade", "base")
    _alembic(database_url, "upgrade", "head")
    db = Database(database_url)
    try:
        assert await db.current_revision() == "0001"
        tenants = []
        async with UnitOfWork(db, platform=True) as uow:
            for slug in ("alfa", "beta"):
                tenant = m.Tenant(id=ids.new_id("tenant"), slug=slug, name=slug)
                await uow.tenants.add(tenant)
                tenants.append(tenant.id)
        for tenant_id in tenants:
            async with UnitOfWork(db, tenant_id) as uow:
                await uow.sites.add(
                    m.Site(
                        id=ids.new_id("site"),
                        tenant_id=tenant_id,
                        public_id=f"site_{tenant_id[-6:]}",
                        name="s",
                        allowed_origins=[],
                    )
                )
        alfa, beta = tenants
        # Consulta SQL sin filtro: RLS limita igualmente al tenant del contexto.
        async with db.session(alfa) as session:
            rows = (await session.execute(text("SELECT tenant_id FROM sites"))).scalars().all()
        assert set(rows) == {alfa}
        async with db.session(None) as session:
            assert (await session.execute(text("SELECT count(*) FROM sites"))).scalar() == 0
        async with db.session(alfa) as session:
            with pytest.raises(Exception):
                await session.execute(
                    text(
                        "INSERT INTO sites (id, tenant_id, public_id, name, status, access_mode, allowed_origins, default_locale, languages, theme, settings, capabilities, created_at, updated_at) "
                        "VALUES ('site_x', :t, 'site_forjado', 'x', 'active', 'public', '[]', 'es', '[]', '{}', '{}', '[]', now(), now())"
                    ),
                    {"t": beta},
                )
        async with db.session(bypass_rls=True) as session:
            assert (await session.execute(text("SELECT count(*) FROM sites"))).scalar() == 2
    finally:
        await db.dispose()
