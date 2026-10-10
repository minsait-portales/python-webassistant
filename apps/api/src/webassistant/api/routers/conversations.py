"""API de conversación v1: crear, enviar mensaje (JSON o SSE), historial, cancelar,
resetear y borrar (CG-09.3, CG-09.4, CG-08.4)."""

from __future__ import annotations

import json
from collections.abc import AsyncIterator
from typing import Any

from fastapi import APIRouter, Header, Request, Response
from fastapi.responses import StreamingResponse

from webassistant.api.deps import ServicesDep, WidgetDep, rate_limit
from webassistant.api.schemas import ChatResponse, ConversationCreate, MessageCreate, ResetRequest
from webassistant.conversations.service import conversation_view
from webassistant.db.repositories import UnitOfWork
from webassistant.errors import AppError, ErrorCode
from webassistant.orchestrator.events import RunEventBus
from webassistant.security.principal import WidgetPrincipal
from webassistant.security.ratelimit import Limit
from webassistant.services.container import Services

router = APIRouter(prefix="/v1/conversations", tags=["conversations"])


def _sse(event: dict[str, Any]) -> str:
    payload = json.dumps(event, ensure_ascii=False, default=str)
    return f"id: {event.get('seq', 0)}\nevent: {event['type']}\ndata: {payload}\n\n"


async def _stream(bus: RunEventBus, run_id: str, after: int) -> AsyncIterator[str]:
    yield "retry: 2000\n\n"
    async for event in bus.events(run_id, after=after):
        yield _sse(event)


def _sse_response(bus: RunEventBus, run_id: str, after: int = 0) -> StreamingResponse:
    return StreamingResponse(
        _stream(bus, run_id, after),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-store",
            "X-Accel-Buffering": "no",
            "Connection": "keep-alive",
        },
    )


async def _assistant_ref(
    services: Services, principal: WidgetPrincipal, requested: str | None
) -> str:
    ref = requested or principal.claims.assistant_id or principal.site.default_assistant_id
    if not ref:
        raise AppError(ErrorCode.VALIDATION_ERROR, "No se ha indicado assistant")
    if (
        principal.claims.assistant_id
        and requested
        and requested not in {principal.claims.assistant_id}
    ):
        published = await services.assistants.published(principal.tenant_id, requested)
        if principal.claims.assistant_id not in {published.assistant_id, published.key}:
            raise AppError(ErrorCode.FORBIDDEN, "El token está limitado a otro assistant")
    published = await services.assistants.published(principal.tenant_id, ref)
    return published.assistant_id


def _locale(principal: WidgetPrincipal, requested: str | None) -> str:
    return (requested or principal.claims.locale or principal.site.default_locale or "es")[:10]


@router.post("", status_code=201, summary="Crear conversación")
async def create_conversation(
    body: ConversationCreate, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    assistant_id = await _assistant_ref(services, principal, body.assistant_id)
    conversation = await services.conversations.create(
        principal, assistant_id, _locale(principal, body.locale)
    )
    return conversation_view(conversation)


@router.get("", summary="Conversaciones del usuario actual en este site")
async def list_conversations(principal: WidgetDep, services: ServicesDep) -> dict[str, Any]:
    return {"items": await services.conversations.list_for(principal)}


@router.get("/{conversation_id}", summary="Historial visible de una conversación")
async def get_conversation(
    conversation_id: str, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    return await services.conversations.history(principal, conversation_id)


@router.post(
    "/{conversation_id}/messages",
    response_model=ChatResponse,
    summary="Enviar mensaje (respuesta JSON completa o SSE con Accept: text/event-stream)",
    responses={200: {"content": {"text/event-stream": {}}}},
)
async def send_message(
    conversation_id: str,
    body: MessageCreate,
    request: Request,
    principal: WidgetDep,
    services: ServicesDep,
) -> Any:
    await rate_limit(
        services,
        Limit(
            "messages",
            f"{principal.site.id}:{principal.subject}",
            services.settings.rate_limit_subject_per_minute,
        ),
        scope="messages",
    )
    prepared = await services.chat.prepare(principal, conversation_id, body.message)
    if "text/event-stream" in request.headers.get("accept", ""):
        services.chat.start_background(prepared)
        return _sse_response(services.events, prepared.run_id)
    return await services.chat.execute(prepared, stream=False)


@router.get("/{conversation_id}/runs/{run_id}/events", summary="Reanudar el stream SSE de un run")
async def run_events(
    conversation_id: str,
    run_id: str,
    principal: WidgetDep,
    services: ServicesDep,
    last_event_id: str | None = Header(default=None, alias="Last-Event-ID"),
    after: int = 0,
) -> StreamingResponse:
    await services.chat.run_owned(principal, conversation_id, run_id)
    cursor = after
    if last_event_id and last_event_id.isdigit():
        cursor = max(cursor, int(last_event_id))
    return _sse_response(services.events, run_id, cursor)


@router.get("/{conversation_id}/runs/{run_id}", summary="Estado de un run")
async def run_status(
    conversation_id: str, run_id: str, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    run = await services.chat.run_owned(principal, conversation_id, run_id)
    return {
        "run_id": run.id,
        "status": run.status,
        "skill": run.skill,
        "error_code": run.error_code,
        "started_at": run.started_at.isoformat(),
        "finished_at": run.finished_at.isoformat() if run.finished_at else None,
    }


@router.post("/{conversation_id}/runs/{run_id}/cancel", summary="Cancelar un run en curso")
async def cancel_run(
    conversation_id: str, run_id: str, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    return await services.chat.cancel(principal, conversation_id, run_id)


@router.post(
    "/{conversation_id}/reset", status_code=201, summary="Archivar y empezar una conversación nueva"
)
async def reset_conversation(
    conversation_id: str, body: ResetRequest, principal: WidgetDep, services: ServicesDep
) -> dict[str, Any]:
    async with UnitOfWork(services.db, principal.tenant_id) as uow:
        current = await services.conversations.get_owned(uow, principal, conversation_id)
        assistant_id = current.assistant_id
    conversation = await services.conversations.reset(
        principal, conversation_id, assistant_id, _locale(principal, body.locale)
    )
    return conversation_view(conversation)


@router.delete(
    "/{conversation_id}", status_code=204, summary="Borrar una conversación por privacidad"
)
async def delete_conversation(
    conversation_id: str, principal: WidgetDep, services: ServicesDep
) -> Response:
    await services.conversations.delete(principal, conversation_id)
    await services.audit.record(
        "conversation.deleted",
        tenant_id=principal.tenant_id,
        actor_type="subject",
        actor_id=principal.subject,
        target_type="conversation",
        target_id=conversation_id,
    )
    return Response(status_code=204)
