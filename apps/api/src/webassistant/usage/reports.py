"""Informes de uso y coste (CG-12.5) y trazabilidad de soporte (CG-12.3).

Los totales se calculan directamente sobre ``usage_events`` y por tanto son
conciliables con los eventos individuales.
"""

from __future__ import annotations

import csv
import io
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any

from sqlalchemy import String, func, select

from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork

DIMENSIONS = {
    "tenant": m.UsageEvent.tenant_id,
    "site": m.UsageEvent.site_id,
    "assistant": m.UsageEvent.assistant_id,
    "model": m.UsageEvent.model,
    "provider": m.UsageEvent.provider,
    "operation": m.UsageEvent.operation,
    "skill": m.UsageEvent.skill,
    "subject": m.UsageEvent.subject,
    "status": m.UsageEvent.status,
    "day": func.date(m.UsageEvent.created_at, type_=String),
}


def period_bounds(date_from: str | None, date_to: str | None) -> tuple[datetime, datetime]:
    end = (
        datetime.fromisoformat(date_to).replace(tzinfo=UTC) + timedelta(days=1)
        if date_to
        else datetime.now(UTC)
    )
    start = (
        datetime.fromisoformat(date_from).replace(tzinfo=UTC)
        if date_from
        else end - timedelta(days=30)
    )
    return start, end


async def usage_report(
    uow: UnitOfWork,
    *,
    group_by: list[str],
    date_from: str | None = None,
    date_to: str | None = None,
    filters: dict[str, str] | None = None,
) -> dict[str, Any]:
    start, end = period_bounds(date_from, date_to)
    columns: list[Any] = [DIMENSIONS[d].label(d) for d in group_by if d in DIMENSIONS]
    aggregates = [
        func.count().label("calls"),
        func.coalesce(func.sum(m.UsageEvent.input_tokens), 0).label("input_tokens"),
        func.coalesce(func.sum(m.UsageEvent.cached_tokens), 0).label("cached_tokens"),
        func.coalesce(func.sum(m.UsageEvent.output_tokens), 0).label("output_tokens"),
        func.coalesce(func.sum(m.UsageEvent.reasoning_tokens), 0).label("reasoning_tokens"),
        func.coalesce(func.sum(m.UsageEvent.input_cost), 0).label("input_cost"),
        func.coalesce(func.sum(m.UsageEvent.cached_cost), 0).label("cached_cost"),
        func.coalesce(func.sum(m.UsageEvent.output_cost), 0).label("output_cost"),
        func.coalesce(func.sum(m.UsageEvent.total_cost), 0).label("total_cost"),
        func.coalesce(func.avg(m.UsageEvent.latency_ms), 0).label("avg_latency_ms"),
    ]
    stmt = select(*columns, *aggregates).where(
        m.UsageEvent.created_at >= start, m.UsageEvent.created_at < end
    )
    if uow.tenant_id is not None:
        stmt = stmt.where(m.UsageEvent.tenant_id == uow.tenant_id)
    for key, value in (filters or {}).items():
        if key in DIMENSIONS and value:
            stmt = stmt.where(DIMENSIONS[key] == value)
    if columns:
        stmt = stmt.group_by(*[DIMENSIONS[d] for d in group_by if d in DIMENSIONS]).order_by(
            *columns
        )
    rows = (await uow.session.execute(stmt)).mappings().all()
    items = []
    totals: dict[str, Any] = {
        "calls": 0,
        "input_tokens": 0,
        "cached_tokens": 0,
        "output_tokens": 0,
        "reasoning_tokens": 0,
        "total_cost": Decimal(0),
    }
    for row in rows:
        item = {
            key: (str(value) if isinstance(value, Decimal | datetime) or key == "day" else value)
            for key, value in row.items()
        }
        item["avg_latency_ms"] = round(float(row["avg_latency_ms"] or 0), 1)
        for key in ("input_cost", "cached_cost", "output_cost", "total_cost"):
            item[key] = f"{Decimal(str(row[key])):.10f}"
        items.append(item)
        for key in ("calls", "input_tokens", "cached_tokens", "output_tokens", "reasoning_tokens"):
            totals[key] += int(row[key] or 0)
        totals["total_cost"] += Decimal(str(row["total_cost"] or 0))
    totals["total_cost"] = f"{totals['total_cost']:.10f}"
    return {
        "from": start.isoformat(),
        "to": end.isoformat(),
        "group_by": group_by,
        "items": items,
        "totals": totals,
    }


def report_csv(report: dict[str, Any]) -> str:
    buffer = io.StringIO()
    items = report["items"]
    fieldnames = list(items[0].keys()) if items else [*report["group_by"], "calls", "total_cost"]
    writer = csv.DictWriter(buffer, fieldnames=fieldnames, extrasaction="ignore")
    writer.writeheader()
    for item in items:
        writer.writerow(item)
    return buffer.getvalue()


async def conversation_trace(uow: UnitOfWork, conversation_id: str) -> dict[str, Any]:
    """Conversación -> run -> llamada -> tool -> acción, sin contenido sensible en claro."""
    conversation = await uow.conversations.require(conversation_id)
    runs = await uow.runs.list(m.Run.conversation_id == conversation_id, order_by=m.Run.started_at)
    run_ids = [r.id for r in runs]
    tool_calls = (
        await uow.tool_calls.list(m.ToolCall.run_id.in_(run_ids), order_by=m.ToolCall.created_at)
        if run_ids
        else []
    )
    usage = (
        await uow.usage_events.list(
            m.UsageEvent.run_id.in_(run_ids), order_by=m.UsageEvent.created_at
        )
        if run_ids
        else []
    )
    actions = await uow.action_executions.list(m.ActionExecution.conversation_id == conversation_id)
    payloads = await uow.audit_payloads.list(m.AuditPayload.run_id.in_(run_ids)) if run_ids else []
    by_run: dict[str, dict[str, Any]] = {}
    for run in runs:
        by_run[run.id] = {
            "run_id": run.id,
            "status": run.status,
            "skill": run.skill,
            "error_code": run.error_code,
            "assistant_version": run.assistant_version,
            "iterations": run.iterations,
            "tool_calls_count": run.tool_calls_count,
            "rag_used": run.rag_used,
            "repaired": run.repaired,
            "latency_ms": run.latency_ms,
            "started_at": run.started_at.isoformat(),
            "usage": run.usage_summary,
            "context": run.context_report,
            "tool_calls": [],
            "provider_calls": [],
            "actions": [],
            "payloads": [],
        }
    for call in tool_calls:
        by_run[call.run_id]["tool_calls"].append(
            {
                "id": call.id,
                "iteration": call.iteration,
                "name": call.name,
                "status": call.status,
                "arguments": call.arguments,
                "result": call.result_summary,
                "latency_ms": call.latency_ms,
                "error_code": call.error_code,
            }
        )
    for event in usage:
        if event.run_id in by_run:
            by_run[event.run_id]["provider_calls"].append(
                {
                    "id": event.id,
                    "iteration": event.iteration,
                    "provider": event.provider,
                    "model": event.model,
                    "operation": event.operation,
                    "status": event.status,
                    "error_code": event.error_code,
                    "input_tokens": event.input_tokens,
                    "cached_tokens": event.cached_tokens,
                    "output_tokens": event.output_tokens,
                    "total_cost": f"{event.total_cost:.10f}",
                    "currency": event.currency,
                    "latency_ms": event.latency_ms,
                }
            )
    for action in actions:
        if action.run_id in by_run:
            by_run[action.run_id]["actions"].append(
                {
                    "execution_id": action.id,
                    "skill": action.skill,
                    "action": action.action,
                    "type": action.type,
                    "status": action.status,
                }
            )
    for payload in payloads:
        if payload.run_id in by_run:
            by_run[payload.run_id]["payloads"].append(
                {"id": payload.id, "kind": payload.kind, "preview": payload.redacted_preview[:300]}
            )
    messages = await uow.messages.list(
        m.Message.conversation_id == conversation_id, order_by=m.Message.created_at
    )
    return {
        "conversation": {
            "id": conversation.id,
            "site_id": conversation.site_id,
            "assistant_id": conversation.assistant_id,
            "subject_type": conversation.subject_type,
            "status": conversation.status,
            "created_at": conversation.created_at.isoformat(),
        },
        "messages": [
            {
                "role": x.role,
                "content": x.content,
                "run_id": x.run_id,
                "created_at": x.created_at.isoformat(),
            }
            for x in messages
        ],
        "runs": list(by_run.values()),
    }
