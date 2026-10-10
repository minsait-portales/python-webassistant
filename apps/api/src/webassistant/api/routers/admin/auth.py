"""Autenticación de la consola de administración."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, Request

from webassistant.api.deps import AdminDep, ServicesDep, client_ip, rate_limit
from webassistant.api.routers.admin.schemas import LoginBody, PasswordChange
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode, validation
from webassistant.security.crypto import hash_secret, password_problems, verify_secret
from webassistant.security.ratelimit import Limit
from webassistant.security.tokens import AdminClaims

router = APIRouter(prefix="/v1/admin", tags=["admin"])

MAX_FAILED_LOGINS = 5


@router.post("/auth/login", summary="Iniciar sesión en la consola")
async def login(body: LoginBody, request: Request, services: ServicesDep) -> dict[str, Any]:
    ip = client_ip(request, services.settings.trusted_proxies)
    await rate_limit(
        services,
        Limit("admin_login", ip, 10),
        Limit("admin_login_user", body.email.lower(), 10),
        scope="admin_login",
    )
    async with UnitOfWork(services.db, platform=True) as uow:
        user = await uow.admin_users.by_email(body.email)
        if user is None or user.status != "active" or user.failed_logins >= MAX_FAILED_LOGINS:
            ok = False
        else:
            ok = verify_secret(body.password, user.password_hash)
            if ok:
                user.failed_logins = 0
                user.last_login_at = datetime.now(UTC)
            else:
                user.failed_logins += 1
        snapshot = (
            (user.id, user.tenant_id, user.role, user.email, user.name) if ok and user else None
        )
    await services.audit.record(
        "admin.login",
        tenant_id=snapshot[1] if snapshot else None,
        actor_type="admin",
        actor_id=snapshot[0] if snapshot else body.email.lower(),
        outcome="success" if snapshot else "denied",
        ip=ip,
    )
    if snapshot is None:
        raise AppError(
            ErrorCode.UNAUTHORIZED,
            "Credenciales no válidas",
            user_message="Correo o contraseña incorrectos.",
        )
    token, expires = services.tokens.issue_admin(
        AdminClaims(snapshot[0], snapshot[1], snapshot[2], snapshot[3])
    )
    return {
        "access_token": token,
        "token_type": "Bearer",
        "expires_at": expires.isoformat(),
        "user": {
            "id": snapshot[0],
            "tenant_id": snapshot[1],
            "role": snapshot[2],
            "email": snapshot[3],
            "name": snapshot[4],
        },
    }


@router.get("/me", summary="Usuario actual y permisos")
async def me(principal: AdminDep) -> dict[str, Any]:
    return {
        "id": principal.user_id,
        "tenant_id": principal.tenant_id,
        "role": principal.role,
        "email": principal.email,
        "permissions": sorted(principal.permissions),
    }


@router.post("/me/password", status_code=204, summary="Cambiar la contraseña propia")
async def change_password(body: PasswordChange, principal: AdminDep, services: ServicesDep) -> None:
    problems = password_problems(body.new_password)
    if problems:
        raise validation(
            "Contraseña débil", [{"path": "/new_password", "message": p} for p in problems]
        )
    async with UnitOfWork(services.db, platform=True) as uow:
        user = await uow.admin_users.require(principal.user_id)
        if not verify_secret(body.current_password, user.password_hash):
            raise AppError(
                ErrorCode.FORBIDDEN,
                "La contraseña actual no es correcta",
                user_message="La contraseña actual no es correcta.",
            )
        user.password_hash = hash_secret(body.new_password)
    await services.audit.record(
        "admin.password_changed",
        tenant_id=principal.tenant_id,
        actor_type="admin",
        actor_id=principal.user_id,
    )
