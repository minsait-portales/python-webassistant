"""Administrador inicial de plataforma (CG-11.1).

Si se definen ``WEBASSISTANT_BOOTSTRAP_ADMIN_EMAIL`` y ``WEBASSISTANT_BOOTSTRAP_ADMIN_PASSWORD``,
la API crea al arrancar un ``platform_admin`` con ese correo si todavía no existe. Es idempotente
(nunca cambia la contraseña de un usuario existente) y tolera arranques simultáneos de réplicas.
"""

from __future__ import annotations

from typing import Any

from sqlalchemy.exc import IntegrityError

from webassistant import ids
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.observability.logging import get_logger
from webassistant.security.crypto import hash_secret, password_problems

logger = get_logger("webassistant.bootstrap")


async def ensure_bootstrap_admin(services: Any) -> bool:
    """Crea el administrador inicial si está configurado. Devuelve ``True`` si lo ha creado."""
    settings = services.settings
    email = (settings.bootstrap_admin_email or "").strip().lower()
    secret = settings.bootstrap_admin_password
    password = secret.get_secret_value() if secret is not None else ""
    if not email or not password:
        return False
    problems = password_problems(password)
    if problems:
        logger.error(
            "No se crea el administrador inicial: contraseña débil (%s)", " ".join(problems)
        )
        return False
    try:
        async with UnitOfWork(services.db, platform=True) as uow:
            if await uow.admin_users.by_email(email) is not None:
                return False
            await uow.admin_users.add(
                m.AdminUser(
                    id=ids.new_id("admin_user"),
                    tenant_id=None,
                    email=email,
                    name="Administrador de plataforma",
                    role="platform_admin",
                    password_hash=hash_secret(password),
                )
            )
    except IntegrityError:
        return False  # otra réplica lo ha creado a la vez
    await services.audit.record(
        "admin.bootstrapped",
        tenant_id=None,
        actor_type="system",
        actor_id="bootstrap",
        target_type="admin_user",
        target_id=email,
    )
    logger.info("Administrador inicial de plataforma creado")
    return True
