"""Datos semilla de la demo autónoma (CG-01.5, CG-16.3). Idempotente.

Crea el tenant ``demo`` con su site, assistant publicado, bases de
conocimiento y documentos; un usuario ``platform_admin`` y un cliente API de
portal. Las credenciales salen de variables de entorno; en desarrollo hay
valores por defecto documentados que nunca deben usarse fuera de local.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

import yaml

from webassistant import ids
from webassistant.config import Environment
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.observability.logging import get_logger
from webassistant.security.crypto import hash_secret
from webassistant.services.config_package import ConfigPackageService

logger = get_logger("webassistant.seeds")
HERE = Path(__file__).resolve().parent

DEV_ADMIN_EMAIL = "admin@demo.local"
DEV_ADMIN_PASSWORD = "Demo-Admin-2026"  # solo desarrollo local
DEV_CLIENT_ID = "wa_demo_portal"
DEV_CLIENT_SECRET = "demo-portal-secret-change-me"  # solo desarrollo local


def load_package(extra_origins: list[str] | None = None) -> dict[str, Any]:
    package = yaml.safe_load((HERE / "demo_package.yaml").read_text(encoding="utf-8"))
    origins = list(package["sites"][0]["allowed_origins"])
    for origin in extra_origins or []:
        if origin and origin not in origins:
            origins.append(origin)
    package["sites"][0]["allowed_origins"] = origins
    return package


DEMO_BASE_URL = "http://localhost:8000"


def load_documents(base_url: str = DEMO_BASE_URL) -> list[dict[str, Any]]:
    """Documentos de la demo con las URLs (fichas, guías, PDFs e imágenes) apuntando a la
    URL pública del despliegue, para que los enlaces funcionen en cualquier entorno."""
    base = base_url.rstrip("/") if base_url.startswith(("http://", "https://")) else DEMO_BASE_URL
    raw = (HERE / "demo_documents.json").read_text(encoding="utf-8")
    documents: list[dict[str, Any]] = json.loads(raw.replace(DEMO_BASE_URL, base))
    for document in documents:
        metadata = document.get("metadata") or {}
        image = metadata.get("image_url")
        if isinstance(image, str) and image.startswith("/"):
            metadata["image_url"] = f"{base}{image}"
    return documents


async def ensure_demo(services: Any, *, ingest: bool = True) -> dict[str, Any]:
    settings = services.settings
    extra = [
        o.strip() for o in os.environ.get("WEBASSISTANT_DEMO_ORIGINS", "").split(",") if o.strip()
    ]
    base = settings.public_base_url.rstrip("/")
    if base.startswith("http"):
        extra.append(base)
    package = load_package(extra)
    if settings.default_provider == "openai":
        for assistant in package["assistants"]:
            assistant["config"]["model"] = {
                "provider": "openai",
                "model_id": settings.default_chat_model,
                "params": {},
            }
    result = await ConfigPackageService(services.db, services.assistants).import_package(
        package, actor_id="seed"
    )
    tenant_id = result["tenant_id"]

    production = settings.environment == Environment.PRODUCTION
    admin_email = os.environ.get("WEBASSISTANT_BOOTSTRAP_ADMIN_EMAIL") or (
        None if production else DEV_ADMIN_EMAIL
    )
    admin_password = os.environ.get("WEBASSISTANT_BOOTSTRAP_ADMIN_PASSWORD") or (
        None if production else DEV_ADMIN_PASSWORD
    )
    client_secret = os.environ.get("WEBASSISTANT_DEMO_CLIENT_SECRET") or (
        None if production else DEV_CLIENT_SECRET
    )
    # El administrador de plataforma no pertenece a ningún tenant: se busca sin filtro de
    # tenant para que la semilla sea idempotente entre reinicios.
    async with UnitOfWork(services.db, platform=True) as uow:
        if admin_email and admin_password and await uow.admin_users.by_email(admin_email) is None:
            await uow.admin_users.add(
                m.AdminUser(
                    id=ids.new_id("admin_user"),
                    tenant_id=None,
                    email=admin_email.lower(),
                    name="Administrador de plataforma",
                    role="platform_admin",
                    password_hash=hash_secret(admin_password),
                )
            )
            logger.info("Usuario platform_admin de demo creado: %s", admin_email)
    async with UnitOfWork(services.db, tenant_id) as uow:
        site = await uow.sites.by_public_id("site_demo")
        if (
            client_secret
            and site is not None
            and await uow.api_clients.by_client_id(DEV_CLIENT_ID) is None
        ):
            await uow.api_clients.add(
                m.ApiClient(
                    id=ids.new_id("api_client"),
                    tenant_id=tenant_id,
                    site_id=site.id,
                    name="Portal de demostración",
                    client_id=DEV_CLIENT_ID,
                    secret_hash=hash_secret(client_secret),
                    scopes=["token:issue", "ingest:write", "ingest:read", "search:read"],
                )
            )
            logger.info("Cliente API de demo creado: %s", DEV_CLIENT_ID)
        documents_count = await uow.documents.count()
    services.sites.invalidate()
    services.assistants.invalidate(tenant_id)
    ingested = 0
    if ingest and documents_count == 0:
        documents = load_documents(base)
        for start in range(0, len(documents), 50):
            outcomes = await services.ingestion.upsert_many(
                tenant_id, documents[start : start + 50]
            )
            ingested += sum(1 for o in outcomes if o.operation in {"created", "updated"})
        logger.info("Documentos de demo encolados para indexación: %s", ingested)
    return {"tenant_id": tenant_id, "changes": len(result["changes"]), "documents_queued": ingested}
