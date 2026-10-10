"""Errores normalizados del producto (CG-05.6) y su representación problem+json (CG-09.1).

Cada fallo de proveedor, cuota, rate limit, salida inválida, tool fallida o
timeout se traduce a un código propio con mensaje recuperable para el usuario
final. El detalle técnico se registra en logs/auditoría, nunca en la respuesta.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import StrEnum
from typing import Any


class ErrorCode(StrEnum):
    VALIDATION_ERROR = "validation_error"
    UNAUTHORIZED = "unauthorized"
    FORBIDDEN = "forbidden"
    NOT_FOUND = "not_found"
    CONFLICT = "conflict"
    ORIGIN_NOT_ALLOWED = "origin_not_allowed"
    SITE_DISABLED = "site_disabled"
    RATE_LIMITED = "rate_limited"
    PAYLOAD_TOO_LARGE = "payload_too_large"
    IDEMPOTENCY_CONFLICT = "idempotency_conflict"
    IDEMPOTENCY_KEY_REQUIRED = "idempotency_key_required"
    CONFIRMATION_REQUIRED = "confirmation_required"
    CONFIRMATION_INVALID = "confirmation_invalid"
    PROVIDER_UNAVAILABLE = "provider_unavailable"
    PROVIDER_TIMEOUT = "provider_timeout"
    PROVIDER_QUOTA = "provider_quota"
    PROVIDER_RATE_LIMITED = "provider_rate_limited"
    PROVIDER_BAD_REQUEST = "provider_bad_request"
    INVALID_MODEL_OUTPUT = "invalid_model_output"
    TOOL_FAILED = "tool_failed"
    TOOL_NOT_ALLOWED = "tool_not_allowed"
    ITERATION_LIMIT = "iteration_limit"
    BUDGET_EXCEEDED = "budget_exceeded"
    POLICY_VIOLATION = "policy_violation"
    CONCURRENCY_LIMIT = "concurrency_limit"
    RUN_CANCELLED = "run_cancelled"
    MESSAGE_TOO_LONG = "message_too_long"
    SKILL_UNAVAILABLE = "skill_unavailable"
    ACTION_UNAVAILABLE = "action_unavailable"
    INTERNAL_ERROR = "internal_error"
    SERVICE_UNAVAILABLE = "service_unavailable"


_STATUS: dict[ErrorCode, int] = {
    ErrorCode.VALIDATION_ERROR: 422,
    ErrorCode.UNAUTHORIZED: 401,
    ErrorCode.FORBIDDEN: 403,
    ErrorCode.NOT_FOUND: 404,
    ErrorCode.CONFLICT: 409,
    ErrorCode.ORIGIN_NOT_ALLOWED: 403,
    ErrorCode.SITE_DISABLED: 403,
    ErrorCode.RATE_LIMITED: 429,
    ErrorCode.PAYLOAD_TOO_LARGE: 413,
    ErrorCode.IDEMPOTENCY_CONFLICT: 409,
    ErrorCode.IDEMPOTENCY_KEY_REQUIRED: 428,
    ErrorCode.CONFIRMATION_REQUIRED: 428,
    ErrorCode.CONFIRMATION_INVALID: 403,
    ErrorCode.PROVIDER_UNAVAILABLE: 503,
    ErrorCode.PROVIDER_TIMEOUT: 504,
    ErrorCode.PROVIDER_QUOTA: 503,
    ErrorCode.PROVIDER_RATE_LIMITED: 503,
    ErrorCode.PROVIDER_BAD_REQUEST: 502,
    ErrorCode.INVALID_MODEL_OUTPUT: 502,
    ErrorCode.TOOL_FAILED: 502,
    ErrorCode.TOOL_NOT_ALLOWED: 403,
    ErrorCode.ITERATION_LIMIT: 422,
    ErrorCode.BUDGET_EXCEEDED: 402,
    ErrorCode.POLICY_VIOLATION: 403,
    ErrorCode.CONCURRENCY_LIMIT: 429,
    ErrorCode.RUN_CANCELLED: 409,
    ErrorCode.MESSAGE_TOO_LONG: 422,
    ErrorCode.SKILL_UNAVAILABLE: 404,
    ErrorCode.ACTION_UNAVAILABLE: 404,
    ErrorCode.INTERNAL_ERROR: 500,
    ErrorCode.SERVICE_UNAVAILABLE: 503,
}

_RETRYABLE = {
    ErrorCode.RATE_LIMITED,
    ErrorCode.PROVIDER_UNAVAILABLE,
    ErrorCode.PROVIDER_TIMEOUT,
    ErrorCode.PROVIDER_RATE_LIMITED,
    ErrorCode.CONCURRENCY_LIMIT,
    ErrorCode.SERVICE_UNAVAILABLE,
    ErrorCode.INVALID_MODEL_OUTPUT,
    ErrorCode.TOOL_FAILED,
}

# Mensajes por defecto mostrables al usuario final. El Assistant puede
# sobrescribirlos por código en ``messages.errors``.
DEFAULT_USER_MESSAGES: dict[ErrorCode, str] = {
    ErrorCode.VALIDATION_ERROR: "Revisa los datos enviados.",
    ErrorCode.NOT_FOUND: "No se ha encontrado lo que buscas.",
    ErrorCode.ORIGIN_NOT_ALLOWED: "Este sitio no está autorizado para usar el asistente.",
    ErrorCode.SITE_DISABLED: "El asistente está desactivado en este sitio.",
    ErrorCode.PAYLOAD_TOO_LARGE: "El contenido enviado es demasiado grande.",
    ErrorCode.CONFLICT: "La operación entra en conflicto con el estado actual.",
    ErrorCode.SKILL_UNAVAILABLE: "Esta función no está disponible.",
    ErrorCode.ACTION_UNAVAILABLE: "Esta acción no está disponible.",
    ErrorCode.IDEMPOTENCY_KEY_REQUIRED: "Falta la clave de idempotencia de la operación.",
    ErrorCode.POLICY_VIOLATION: "La operación no está permitida por la política configurada.",
    ErrorCode.RATE_LIMITED: "Has alcanzado el límite temporal de consultas. Inténtalo de nuevo más tarde.",
    ErrorCode.PROVIDER_UNAVAILABLE: "El asistente no está disponible en este momento. Inténtalo de nuevo en unos minutos.",
    ErrorCode.PROVIDER_TIMEOUT: "La respuesta está tardando demasiado. Inténtalo de nuevo.",
    ErrorCode.PROVIDER_QUOTA: "El asistente ha alcanzado su capacidad disponible. Inténtalo más tarde.",
    ErrorCode.PROVIDER_RATE_LIMITED: "Hay mucha demanda en este momento. Inténtalo de nuevo en unos segundos.",
    ErrorCode.PROVIDER_BAD_REQUEST: "No se pudo procesar la consulta. Prueba a reformularla.",
    ErrorCode.INVALID_MODEL_OUTPUT: "No se pudo preparar la respuesta. Prueba a reformular la consulta.",
    ErrorCode.TOOL_FAILED: "No se pudo consultar la información necesaria. Inténtalo de nuevo.",
    ErrorCode.ITERATION_LIMIT: "La consulta es demasiado exigente para resolverla de una sola vez. Prueba a concretarla un poco más.",
    ErrorCode.BUDGET_EXCEEDED: "El asistente ha alcanzado el límite de uso configurado. Inténtalo más tarde.",
    ErrorCode.CONCURRENCY_LIMIT: "Ya hay una respuesta en curso. Espera a que termine.",
    ErrorCode.MESSAGE_TOO_LONG: "La consulta es demasiado larga.",
    ErrorCode.RUN_CANCELLED: "Has cancelado la respuesta.",
    ErrorCode.UNAUTHORIZED: "Tu sesión ha caducado. Vuelve a cargar la página.",
    ErrorCode.FORBIDDEN: "No tienes permisos para usar este asistente.",
    ErrorCode.CONFIRMATION_REQUIRED: "Esta acción necesita tu confirmación.",
    ErrorCode.CONFIRMATION_INVALID: "La confirmación ha caducado. Vuelve a intentarlo.",
    ErrorCode.INTERNAL_ERROR: "Se produjo un error al generar la respuesta. Inténtalo de nuevo.",
    ErrorCode.SERVICE_UNAVAILABLE: "El servicio no está disponible temporalmente.",
}

_TITLES: dict[ErrorCode, str] = {
    ErrorCode.VALIDATION_ERROR: "Solicitud no válida",
    ErrorCode.UNAUTHORIZED: "No autenticado",
    ErrorCode.FORBIDDEN: "Acceso denegado",
    ErrorCode.NOT_FOUND: "No encontrado",
    ErrorCode.RATE_LIMITED: "Demasiadas solicitudes",
    ErrorCode.INTERNAL_ERROR: "Error interno",
}


@dataclass
class AppError(Exception):
    code: ErrorCode
    detail: str = ""
    user_message: str | None = None
    errors: list[dict[str, Any]] = field(default_factory=list)
    headers: dict[str, str] = field(default_factory=dict)
    status: int | None = None

    def __post_init__(self) -> None:
        super().__init__(self.detail or self.code.value)

    @property
    def http_status(self) -> int:
        return self.status or _STATUS.get(self.code, 400)

    @property
    def retryable(self) -> bool:
        return self.code in _RETRYABLE

    def resolved_user_message(self, overrides: dict[str, str] | None = None) -> str:
        if overrides and overrides.get(self.code.value):
            return overrides[self.code.value]
        if self.user_message:
            return self.user_message
        return DEFAULT_USER_MESSAGES.get(self.code, DEFAULT_USER_MESSAGES[ErrorCode.INTERNAL_ERROR])

    def to_problem(
        self, correlation_id: str | None = None, instance: str | None = None
    ) -> dict[str, Any]:
        problem: dict[str, Any] = {
            "type": f"https://webassistant.ai/problems/{self.code.value}",
            "title": _TITLES.get(self.code, self.code.value.replace("_", " ").capitalize()),
            "status": self.http_status,
            "code": self.code.value,
            "retryable": self.retryable,
            "user_message": self.resolved_user_message(),
        }
        if self.detail:
            problem["detail"] = self.detail
        if instance:
            problem["instance"] = instance
        if correlation_id:
            problem["correlation_id"] = correlation_id
        if self.errors:
            problem["errors"] = self.errors
        return problem


def not_found(what: str) -> AppError:
    return AppError(ErrorCode.NOT_FOUND, f"{what} no encontrado")


def forbidden(detail: str = "Operación no permitida") -> AppError:
    return AppError(ErrorCode.FORBIDDEN, detail)


def validation(detail: str, errors: list[dict[str, Any]] | None = None) -> AppError:
    return AppError(ErrorCode.VALIDATION_ERROR, detail, errors=errors or [])
