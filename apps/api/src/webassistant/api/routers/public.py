"""Bootstrap del widget, sesiones e intercambio de credenciales por tokens (CG-03.1, CG-03.2, CG-09.2)."""

from __future__ import annotations

import base64
from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, Request

from webassistant import ids
from webassistant.api.deps import (
    ServicesDep,
    authenticate_client,
    check_site_origin,
    client_ip,
    rate_limit,
    request_origin,
)
from webassistant.api.schemas import (
    BootstrapResponse,
    BootstrapTexts,
    SessionRequest,
    SessionResponse,
    TokenRequest,
    TokenResponse,
)
from webassistant.errors import AppError, ErrorCode
from webassistant.security.origins import normalize_origin, origin_allowed
from webassistant.security.principal import SiteInfo
from webassistant.security.ratelimit import Limit
from webassistant.security.scopes import (
    ANONYMOUS_DEFAULT_SCOPES,
    WIDGET_BASE_SCOPES,
    Scope,
    has_scopes,
)
from webassistant.security.tokens import ServerClaims, WidgetClaims
from webassistant.services.container import Services
from webassistant.skills.registry import SkillDefinition

router = APIRouter(prefix="/v1", tags=["public"])

DEFAULT_THEME: dict[str, Any] = {
    "accent": "#480e2a",
    "on_accent": "#ffffff",
    "text": "#260717",
    "surface": "#ffffff",
    "muted_surface": "#f6f5f1",
    "border": "#e3e2da",
    "highlight": "#ff0054",
    "font_family": "forfuture",
    "radius": 8,
    "position": "left",
    "launcher_label": "WebAssistant AI",
    "chat_width": 420,
}

RENDERERS = [
    "text@1",
    "answer@1",
    "product_grid@1",
    "product_comparison@1",
    "product_summary@1",
    "event_selection@1",
    "document_citations@1",
    "clarification@1",
    "steps@1",
    "cart@1",
    "host_action@1",
]


def widget_scopes_for(site: SiteInfo, *, authenticated: bool) -> frozenset[str]:
    settings = site.settings or {}
    if authenticated:
        allowed: list[str] = list(settings.get("allowed_widget_scopes") or [])  # type: ignore[call-overload]
        return frozenset(WIDGET_BASE_SCOPES | {Scope.ACTIONS_EXECUTE} | set(allowed))
    extra: list[str] = list(settings.get("anonymous_scopes") or [])  # type: ignore[call-overload]
    return frozenset(ANONYMOUS_DEFAULT_SCOPES | {Scope.ACTIONS_EXECUTE} | set(extra))


async def _site_or_403(services: Services, public_id: str) -> SiteInfo:
    site = await services.sites.by_public_id(public_id)
    if site is None:
        raise AppError(ErrorCode.ORIGIN_NOT_ALLOWED, "Site no registrado")
    return site


def _host_actions(definitions: list[SkillDefinition]) -> list[str]:
    names = set()
    for definition in definitions:
        for action in definition.actions.values():
            if action.get("type") == "host":
                names.add(str(action["host_action"]))
    return sorted(names)


@router.get(
    "/bootstrap", response_model=BootstrapResponse, summary="Configuración pública del widget"
)
async def bootstrap(
    request: Request,
    services: ServicesDep,
    site_id: str,
    assistant_id: str | None = None,
    locale: str | None = None,
) -> BootstrapResponse:
    settings = services.settings
    await rate_limit(
        services,
        Limit(
            "bootstrap_ip",
            client_ip(request, settings.trusted_proxies),
            settings.rate_limit_ip_per_minute,
        ),
        scope="bootstrap",
    )
    site = await _site_or_403(services, site_id)
    check_site_origin(site, request_origin(request))
    assistant_ref = assistant_id or site.default_assistant_id
    if not assistant_ref:
        raise AppError(ErrorCode.NOT_FOUND, "El site no tiene assistant por defecto")
    assistant = await services.assistants.published(site.tenant_id, assistant_ref)
    config = assistant.config
    messages = assistant.messages
    locales = list(site.languages) or list(config.get("locales") or [site.default_locale])
    chosen_locale = (
        locale
        if locale and locale.split("-")[0] in {x.split("-")[0] for x in locales}
        else site.default_locale
    )
    theme = {**DEFAULT_THEME, **(site.theme or {})}
    definitions = [
        d
        for name in [s["name"] for s in assistant.skills if s.get("enabled", True)]
        if (d := services.registry.get(name))
    ]
    base = settings.public_base_url.rstrip("/")
    return BootstrapResponse(
        site={"id": site.public_id, "name": site.name},
        assistant={
            "id": assistant.key,
            "name": assistant.name,
            "version": assistant.version,
            "model": assistant.model_id,
        },
        locale=chosen_locale,
        locales=locales,
        texts=BootstrapTexts(
            welcome=str(messages.get("welcome", "")),
            input_placeholder=str(messages.get("input_placeholder", "")),
            privacy_notice=str(messages.get("privacy_notice", "")),
            launcher_label=str(theme.get("launcher_label") or "WebAssistant AI"),
            login_required=str(messages.get("login_required", "")),
            access_denied=str(messages.get("access_denied", "")),
        ),
        theme=theme,
        capabilities={
            "streaming": True,
            "history": True,
            "actions": True,
            "renderers": RENDERERS,
            "host_actions": _host_actions(definitions),
            "site_capabilities": list(site.capabilities),
        },
        suggestions=list(config.get("suggestions") or [])[:8],
        limits={"max_message_length": int(assistant.limits.get("max_message_length", 800))},
        auth={"mode": site.access_mode, "session_endpoint": f"{base}/v1/sessions"},
        endpoints={
            "sessions": f"{base}/v1/sessions",
            "conversations": f"{base}/v1/conversations",
            "actions": f"{base}/v1/actions",
        },
        layout={
            "desktop_always_open": bool((site.settings or {}).get("desktop_always_open", False)),
            "result_target": (site.settings or {}).get("result_target_selector"),
            "header_offset_selector": (site.settings or {}).get("header_sticky"),
        },
    )


@router.post(
    "/sessions",
    response_model=SessionResponse,
    summary="Sesión del widget (anónima o con token del portal)",
)
async def create_session(
    body: SessionRequest, request: Request, services: ServicesDep
) -> SessionResponse:
    settings = services.settings
    ip = client_ip(request, settings.trusted_proxies)
    await rate_limit(
        services, Limit("session_ip", ip, settings.rate_limit_ip_per_minute), scope="session"
    )
    site = await _site_or_403(services, body.site_id)
    origin = request_origin(request)
    check_site_origin(site, origin)
    tokens = services.tokens
    linked = 0

    if body.host_token:
        claims = tokens.decode_widget(body.host_token)
        if claims.site_id != site.id:
            raise AppError(ErrorCode.FORBIDDEN, "El token pertenece a otro site")
        if claims.origin and claims.origin != origin:
            raise AppError(ErrorCode.ORIGIN_NOT_ALLOWED, "El token está ligado a otro origen")
        if (
            body.anonymous_session
            and claims.subject_type == "user"
            and (site.settings or {}).get("link_anonymous_on_login", True)
        ):
            anonymous_id = tokens.decode_anonymous_session(body.anonymous_session, site.id)
            if anonymous_id:
                linked = await services.conversations.link_anonymous(
                    site.tenant_id, site.id, anonymous_id, claims.subject
                )
        token, expires = tokens.issue_widget(
            WidgetClaims(
                **{
                    **claims.__dict__,
                    "origin": claims.origin or origin,
                    "token_id": "",
                    "expires_at": None,
                }
            )
        )
        return SessionResponse(
            access_token=token,
            expires_in=int((expires - datetime.now(UTC)).total_seconds()),
            expires_at=expires.isoformat(),
            subject_type="user" if claims.subject_type == "user" else "anonymous",
            scopes=sorted(claims.scopes),
            linked_conversations=linked,
        )

    if site.access_mode != "public":
        raise AppError(
            ErrorCode.UNAUTHORIZED, "Este asistente requiere iniciar sesión en el portal"
        )
    anonymous_id = (
        tokens.decode_anonymous_session(body.anonymous_session, site.id)
        if body.anonymous_session
        else None
    )
    anonymous_id = anonymous_id or ids.new_id("anonymous")
    scopes = widget_scopes_for(site, authenticated=False)
    token, expires = tokens.issue_widget(
        WidgetClaims(
            subject=anonymous_id,
            subject_type="anonymous",
            tenant_id=site.tenant_id,
            site_id=site.id,
            site_public_id=site.public_id,
            scopes=scopes,
            locale=body.locale,
            origin=origin,
        )
    )
    return SessionResponse(
        access_token=token,
        expires_in=int((expires - datetime.now(UTC)).total_seconds()),
        expires_at=expires.isoformat(),
        subject_type="anonymous",
        scopes=sorted(scopes),
        anonymous_session=tokens.issue_anonymous_session(anonymous_id, site.id),
    )


@router.post(
    "/token",
    response_model=TokenResponse,
    summary="Credenciales server-to-server por token temporal",
)
async def issue_token(body: TokenRequest, request: Request, services: ServicesDep) -> TokenResponse:
    client_id, client_secret = body.client_id, body.client_secret
    header = request.headers.get("authorization", "")
    if header.lower().startswith("basic "):
        try:
            client_id, _, client_secret = base64.b64decode(header[6:]).decode().partition(":")
        except (ValueError, UnicodeDecodeError) as exc:
            raise AppError(ErrorCode.UNAUTHORIZED, "Cabecera Basic no válida") from exc
    if not client_id or not client_secret:
        raise AppError(ErrorCode.UNAUTHORIZED, "Faltan credenciales de cliente")
    principal = await authenticate_client(services, client_id, client_secret)
    tokens = services.tokens
    if body.grant_type == "client_credentials":
        token, expires = tokens.issue_server(
            ServerClaims(
                principal.client_id, principal.tenant_id, principal.site_id, principal.scopes
            )
        )
        return TokenResponse(
            access_token=token,
            expires_in=int((expires - datetime.now(UTC)).total_seconds()),
            expires_at=expires.isoformat(),
            scopes=sorted(principal.scopes),
        )
    if not has_scopes(principal.scopes, [Scope.TOKEN_ISSUE]):
        raise AppError(ErrorCode.FORBIDDEN, "El cliente no puede emitir tokens de widget")
    if body.user is None:
        raise AppError(ErrorCode.VALIDATION_ERROR, "Falta el usuario")
    site_public = body.site_id
    site = (
        await services.sites.by_public_id(site_public)
        if site_public
        else (await services.sites.by_id(principal.site_id) if principal.site_id else None)
    )
    if site is None or site.tenant_id != principal.tenant_id:
        raise AppError(ErrorCode.FORBIDDEN, "Site no válido para este cliente")
    if principal.site_id and site.id != principal.site_id:
        raise AppError(ErrorCode.FORBIDDEN, "El cliente está limitado a otro site")
    origin = normalize_origin(body.origin) if body.origin else None
    if body.origin and (not origin or not origin_allowed(origin, list(site.allowed_origins))):
        raise AppError(ErrorCode.ORIGIN_NOT_ALLOWED, "Origen no registrado para el site")
    allowed = widget_scopes_for(site, authenticated=True)
    requested = set(body.scopes) if body.scopes else set(allowed)
    scopes = frozenset(s for s in requested if s in allowed) | frozenset({Scope.CHAT_USE})
    token, expires = tokens.issue_widget(
        WidgetClaims(
            subject=body.user.id,
            subject_type="user",
            tenant_id=site.tenant_id,
            site_id=site.id,
            site_public_id=site.public_id,
            scopes=scopes,
            roles=tuple(body.user.roles),
            groups=tuple(body.user.groups),
            locale=body.user.locale,
            origin=origin,
            assistant_id=body.assistant_id,
            display_name=body.user.display_name,
        ),
        ttl_seconds=body.ttl_seconds,
    )
    await services.audit.record(
        "token.widget_issued",
        tenant_id=site.tenant_id,
        actor_type="api_client",
        actor_id=principal.client_id,
        target_type="subject",
        target_id=body.user.id,
        details={"scopes": sorted(scopes), "site": site.public_id},
    )
    return TokenResponse(
        access_token=token,
        expires_in=int((expires - datetime.now(UTC)).total_seconds()),
        expires_at=expires.isoformat(),
        scopes=sorted(scopes),
    )
