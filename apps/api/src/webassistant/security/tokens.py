"""Tokens temporales firmados (CG-03.2).

El navegador solo recibe tokens de vida corta con tenant, site, usuario, roles
y scopes. Las credenciales permanentes (secretos de cliente, claves de IA o de
bases de datos) nunca salen del servidor.
"""

from __future__ import annotations

import secrets
from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from typing import Any

import jwt

from webassistant.config import Settings
from webassistant.errors import AppError, ErrorCode

AUD_WIDGET = "webassistant:widget"
AUD_SERVER = "webassistant:server"
AUD_ADMIN = "webassistant:admin"
AUD_CONFIRMATION = "webassistant:confirmation"
AUD_ANON_SESSION = "webassistant:anonymous-session"

ALGORITHM = "HS256"
MAX_WIDGET_TTL = 3600


@dataclass(frozen=True)
class WidgetClaims:
    subject: str
    subject_type: str  # user | anonymous
    tenant_id: str
    site_id: str
    site_public_id: str
    scopes: frozenset[str]
    roles: tuple[str, ...] = ()
    groups: tuple[str, ...] = ()
    locale: str | None = None
    origin: str | None = None
    assistant_id: str | None = None
    token_id: str = ""
    expires_at: datetime | None = None
    display_name: str | None = None

    @property
    def acl_principals(self) -> frozenset[str]:
        """Principales con los que se filtran fuentes RAG y acciones (CG-07.5)."""
        principals = {"public"}
        if self.subject_type == "user":
            principals.add("authenticated")
            principals.add(f"user:{self.subject}")
        principals.update(f"role:{role}" for role in self.roles)
        principals.update(f"group:{group}" for group in self.groups)
        return frozenset(principals)


@dataclass(frozen=True)
class ServerClaims:
    client_id: str
    tenant_id: str
    site_id: str | None
    scopes: frozenset[str]


@dataclass(frozen=True)
class AdminClaims:
    user_id: str
    tenant_id: str | None
    role: str
    email: str = ""


@dataclass(frozen=True)
class ConfirmationClaims:
    subject: str
    execution_id: str
    params_hash: str
    tenant_id: str


@dataclass
class TokenService:
    settings: Settings
    _now: Any = field(default=None, repr=False)

    def now(self) -> datetime:
        return self._now() if self._now else datetime.now(UTC)

    @property
    def _secret(self) -> str:
        return self.settings.effective_jwt_secret()

    def _verification_secrets(self) -> list[str]:
        return [self._secret] + [s.get_secret_value() for s in self.settings.jwt_previous_secrets]

    def _encode(
        self, audience: str, subject: str, ttl_seconds: int, claims: dict[str, Any]
    ) -> tuple[str, datetime]:
        issued = self.now()
        expires = issued + timedelta(seconds=ttl_seconds)
        payload = {
            "iss": self.settings.jwt_issuer,
            "aud": audience,
            "sub": subject,
            "iat": int(issued.timestamp()),
            "nbf": int(issued.timestamp()) - 5,
            "exp": int(expires.timestamp()),
            "jti": secrets.token_urlsafe(12),
            **claims,
        }
        return jwt.encode(payload, self._secret, algorithm=ALGORITHM), expires

    def decode(self, token: str, audience: str) -> dict[str, Any]:
        last_error: Exception | None = None
        for secret in self._verification_secrets():
            try:
                return jwt.decode(
                    token,
                    secret,
                    algorithms=[ALGORITHM],
                    audience=audience,
                    issuer=self.settings.jwt_issuer,
                    options={"require": ["exp", "iat", "sub", "aud", "iss"]},
                    leeway=10,
                )
            except jwt.ExpiredSignatureError as exc:
                raise AppError(ErrorCode.UNAUTHORIZED, "Token caducado") from exc
            except jwt.InvalidSignatureError as exc:
                last_error = exc
                continue
            except jwt.PyJWTError as exc:
                raise AppError(ErrorCode.UNAUTHORIZED, "Token inválido") from exc
        raise AppError(ErrorCode.UNAUTHORIZED, "Token inválido") from last_error

    # --- Widget ---------------------------------------------------------------

    def issue_widget(
        self, claims: WidgetClaims, ttl_seconds: int | None = None
    ) -> tuple[str, datetime]:
        ttl = min(ttl_seconds or self.settings.widget_token_ttl_seconds, MAX_WIDGET_TTL)
        return self._encode(
            AUD_WIDGET,
            claims.subject,
            ttl,
            {
                "typ": "widget",
                "sbt": claims.subject_type,
                "tid": claims.tenant_id,
                "sid": claims.site_id,
                "spid": claims.site_public_id,
                "aid": claims.assistant_id,
                "scp": sorted(claims.scopes),
                "rol": list(claims.roles),
                "grp": list(claims.groups),
                "loc": claims.locale,
                "org": claims.origin,
                "nam": claims.display_name,
            },
        )

    def decode_widget(self, token: str) -> WidgetClaims:
        data = self.decode(token, AUD_WIDGET)
        if data.get("typ") != "widget":
            raise AppError(ErrorCode.UNAUTHORIZED, "Tipo de token incorrecto")
        return WidgetClaims(
            subject=str(data["sub"]),
            subject_type=str(data.get("sbt", "anonymous")),
            tenant_id=str(data["tid"]),
            site_id=str(data["sid"]),
            site_public_id=str(data.get("spid", "")),
            scopes=frozenset(data.get("scp", [])),
            roles=tuple(data.get("rol", [])),
            groups=tuple(data.get("grp", [])),
            locale=data.get("loc"),
            origin=data.get("org"),
            assistant_id=data.get("aid"),
            token_id=str(data.get("jti", "")),
            expires_at=datetime.fromtimestamp(int(data["exp"]), UTC),
            display_name=data.get("nam"),
        )

    # --- Sesión anónima ------------------------------------------------------

    def issue_anonymous_session(self, anonymous_id: str, site_id: str) -> str:
        token, _ = self._encode(
            AUD_ANON_SESSION,
            anonymous_id,
            self.settings.anonymous_session_ttl_days * 86400,
            {"typ": "anon", "sid": site_id},
        )
        return token

    def decode_anonymous_session(self, token: str, site_id: str) -> str | None:
        try:
            data = self.decode(token, AUD_ANON_SESSION)
        except AppError:
            return None
        if data.get("typ") != "anon" or data.get("sid") != site_id:
            return None
        return str(data["sub"])

    # --- Server-to-server ----------------------------------------------------

    def issue_server(self, claims: ServerClaims) -> tuple[str, datetime]:
        return self._encode(
            AUD_SERVER,
            claims.client_id,
            self.settings.server_token_ttl_seconds,
            {
                "typ": "server",
                "tid": claims.tenant_id,
                "sid": claims.site_id,
                "scp": sorted(claims.scopes),
            },
        )

    def decode_server(self, token: str) -> ServerClaims:
        data = self.decode(token, AUD_SERVER)
        if data.get("typ") != "server":
            raise AppError(ErrorCode.UNAUTHORIZED, "Tipo de token incorrecto")
        return ServerClaims(
            client_id=str(data["sub"]),
            tenant_id=str(data["tid"]),
            site_id=data.get("sid"),
            scopes=frozenset(data.get("scp", [])),
        )

    # --- Administración ------------------------------------------------------

    def issue_admin(self, claims: AdminClaims) -> tuple[str, datetime]:
        return self._encode(
            AUD_ADMIN,
            claims.user_id,
            self.settings.admin_token_ttl_seconds,
            {"typ": "admin", "tid": claims.tenant_id, "rol": claims.role, "eml": claims.email},
        )

    def decode_admin(self, token: str) -> AdminClaims:
        data = self.decode(token, AUD_ADMIN)
        if data.get("typ") != "admin":
            raise AppError(ErrorCode.UNAUTHORIZED, "Tipo de token incorrecto")
        return AdminClaims(
            user_id=str(data["sub"]),
            tenant_id=data.get("tid"),
            role=str(data["rol"]),
            email=str(data.get("eml", "")),
        )

    # --- Confirmación de acciones (CG-05.5) ---------------------------------

    def issue_confirmation(self, claims: ConfirmationClaims) -> tuple[str, datetime]:
        return self._encode(
            AUD_CONFIRMATION,
            claims.subject,
            self.settings.confirmation_token_ttl_seconds,
            {
                "typ": "confirm",
                "eid": claims.execution_id,
                "ph": claims.params_hash,
                "tid": claims.tenant_id,
            },
        )

    def decode_confirmation(self, token: str) -> ConfirmationClaims:
        try:
            data = self.decode(token, AUD_CONFIRMATION)
        except AppError as exc:
            raise AppError(
                ErrorCode.CONFIRMATION_INVALID, "Confirmación caducada o inválida"
            ) from exc
        if data.get("typ") != "confirm":
            raise AppError(ErrorCode.CONFIRMATION_INVALID, "Confirmación inválida")
        return ConfirmationClaims(
            subject=str(data["sub"]),
            execution_id=str(data["eid"]),
            params_hash=str(data["ph"]),
            tenant_id=str(data["tid"]),
        )
