"""Acciones de skills: preparación, confirmación/ejecución idempotente y resultado de host actions (CG-09.5)."""

from __future__ import annotations

import re
from typing import Any

from fastapi import APIRouter, Header

from webassistant.api.deps import ServicesDep, WidgetDep
from webassistant.api.schemas import ActionExecute, ActionPrepare, ActionReport
from webassistant.errors import AppError, ErrorCode

router = APIRouter(prefix="/v1/actions", tags=["actions"])

_IDEMPOTENCY_RE = re.compile(r"^[A-Za-z0-9_.:-]{8,128}$")


@router.post(
    "/prepare",
    summary="Preparar una acción y obtener la propuesta (y token de confirmación si aplica)",
)
async def prepare_action(
    body: ActionPrepare, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    return await services.actions.prepare(
        principal,
        conversation_id=body.conversation_id,
        run_id=body.run_id,
        skill_name=body.skill,
        action=body.action,
        params=body.params,
    )


@router.post(
    "/{execution_id}/execute", summary="Ejecutar una acción preparada (requiere Idempotency-Key)"
)
async def execute_action(
    execution_id: str,
    body: ActionExecute,
    principal: WidgetDep,
    services: ServicesDep,
    idempotency_key: str | None = Header(default=None, alias="Idempotency-Key"),
) -> dict[str, Any]:
    if idempotency_key is not None and not _IDEMPOTENCY_RE.match(idempotency_key):
        raise AppError(ErrorCode.VALIDATION_ERROR, "Idempotency-Key no válida")
    return await services.actions.execute(
        principal,
        execution_id,
        idempotency_key=f"{principal.subject}:{idempotency_key}" if idempotency_key else None,
        confirmation_token=body.confirmation_token,
    )


@router.post("/{execution_id}/result", summary="Notificar el resultado de una host action")
async def report_action(
    execution_id: str, body: ActionReport, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    return await services.actions.report(
        principal, execution_id, status=body.status, result=body.result
    )
