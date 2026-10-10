"""Backend de portal simulado para la demo autónoma (CG-16.3).

Representa lo que hace el servidor de un portal real (Drupal, Liferay, WordPress...):
autentica a su propio usuario y obtiene de WebAssistant un token temporal de widget con
su identidad, roles y permisos. En un portal real se usa ``POST /v1/token`` con
``grant_type=widget_token`` y las credenciales del cliente API, que nunca llegan al
navegador; aquí la demo vive en el mismo despliegue y emite el token directamente.

Solo existe con ``WEBASSISTANT_DEMO_ENABLED=true`` y fuera de producción.
"""

from __future__ import annotations

from typing import Any, Literal

from fastapi import APIRouter, Request
from pydantic import BaseModel

from webassistant.api.deps import ServicesDep, check_site_origin, request_origin
from webassistant.api.routers.public import widget_scopes_for
from webassistant.config import Environment
from webassistant.errors import AppError, ErrorCode
from webassistant.security.scopes import Scope
from webassistant.security.tokens import WidgetClaims

router = APIRouter(include_in_schema=False)

DEMO_SITE = "site_demo"

PROFILES: dict[str, dict[str, Any]] = {
    "cliente": {
        "id": "demo-cliente",
        "display_name": "Cliente de demostración",
        "roles": ["customer"],
        "groups": [],
        "scopes": ["catalog:read", "cart:read", "cart:write", "profile:read"],
    },
    "editor": {
        "id": "demo-editor",
        "display_name": "Editora de demostración",
        "roles": ["editor"],
        "groups": ["editores"],
        "scopes": [
            "catalog:read",
            "cart:read",
            "cart:write",
            "profile:read",
            "content:write",
            "analytics:read",
        ],
    },
}


class DemoLogin(BaseModel):
    profile: Literal["cliente", "editor"]


@router.post("/demo/api/login")
async def demo_login(body: DemoLogin, request: Request, services: ServicesDep) -> dict[str, Any]:
    settings = services.settings
    if not settings.demo_enabled or settings.environment == Environment.PRODUCTION:
        raise AppError(ErrorCode.NOT_FOUND, "Demo deshabilitada")
    site = await services.sites.by_public_id(DEMO_SITE)
    if site is None:
        raise AppError(ErrorCode.NOT_FOUND, "Site de demo no disponible")
    origin = request_origin(request)
    check_site_origin(site, origin)
    profile = PROFILES[body.profile]
    allowed = widget_scopes_for(site, authenticated=True)
    scopes = frozenset(s for s in profile["scopes"] if s in allowed) | {Scope.CHAT_USE}
    token, expires = services.tokens.issue_widget(
        WidgetClaims(
            subject=profile["id"],
            subject_type="user",
            tenant_id=site.tenant_id,
            site_id=site.id,
            site_public_id=site.public_id,
            scopes=scopes,
            roles=tuple(profile["roles"]),
            groups=tuple(profile["groups"]),
            origin=origin,
            display_name=profile["display_name"],
        ),
        ttl_seconds=900,
    )
    return {
        "token": token,
        "expires_at": expires.isoformat(),
        "profile": body.profile,
        "display_name": profile["display_name"],
        "scopes": sorted(scopes),
    }
