"""Directorio de sites con caché corta (resolución por identificador público).

La resolución de ``public_id`` ocurre antes de conocer el tenant, por eso usa
una sesión de plataforma de solo lectura. Todo lo demás se hace ya dentro del
contexto del tenant resuelto.
"""

from __future__ import annotations

import time

from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.security.principal import SiteInfo


def site_info(site: m.Site) -> SiteInfo:
    return SiteInfo(
        id=site.id,
        public_id=site.public_id,
        tenant_id=site.tenant_id,
        name=site.name,
        status=site.status,
        access_mode=site.access_mode,
        allowed_origins=tuple(site.allowed_origins or []),
        default_assistant_id=site.default_assistant_id,
        default_locale=site.default_locale,
        languages=tuple(site.languages or []),
        theme=dict(site.theme or {}),
        settings=dict(site.settings or {}),
        capabilities=tuple(site.capabilities or []),
    )


class SiteDirectory:
    def __init__(self, db: Database, ttl_seconds: float = 30.0) -> None:
        self.db = db
        self.ttl = ttl_seconds
        self._by_public: dict[str, tuple[float, SiteInfo | None]] = {}
        self._by_id: dict[str, tuple[float, SiteInfo | None]] = {}
        self._origins: tuple[float, frozenset[str]] = (0.0, frozenset())

    async def by_public_id(self, public_id: str) -> SiteInfo | None:
        cached = self._by_public.get(public_id)
        if cached and time.monotonic() - cached[0] < self.ttl:
            return cached[1]
        async with UnitOfWork(self.db, platform=True) as uow:
            site = await uow.sites.by_public_id(public_id)
            tenant = await uow.tenants.get(site.tenant_id) if site else None
            info = site_info(site) if site and tenant and tenant.status == "active" else None
        self._by_public[public_id] = (time.monotonic(), info)
        if info:
            self._by_id[info.id] = (time.monotonic(), info)
        return info

    async def by_id(self, site_id: str) -> SiteInfo | None:
        cached = self._by_id.get(site_id)
        if cached and time.monotonic() - cached[0] < self.ttl:
            return cached[1]
        async with UnitOfWork(self.db, platform=True) as uow:
            site = await uow.sites.get(site_id)
            info = site_info(site) if site else None
        self._by_id[site_id] = (time.monotonic(), info)
        return info

    async def all_origins(self) -> frozenset[str]:
        """Orígenes de todos los sites activos (respuesta a preflight CORS)."""
        cached_at, origins = self._origins
        if time.monotonic() - cached_at < self.ttl:
            return origins
        async with UnitOfWork(self.db, platform=True) as uow:
            sites = await uow.sites.list(m.Site.status == "active")
            collected = frozenset(
                origin for site in sites for origin in (site.allowed_origins or [])
            )
        self._origins = (time.monotonic(), collected)
        return collected

    def invalidate(self) -> None:
        self._by_public.clear()
        self._by_id.clear()
        self._origins = (0.0, frozenset())
