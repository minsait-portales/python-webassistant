#!/usr/bin/env python3
"""Ensayo de carga y aislamiento del piloto (CG-15.10).

Simula usuarios concurrentes del widget contra un despliegue con la demo y el proveedor IA
simulado: bootstrap, sesión, conversación, mensajes representativos, historial y acciones del
portal (preparar, ejecutar y reportar). Cada usuario intenta además leer la conversación de
otro usuario: cualquier lectura con éxito cuenta como fuga.

    uv run --project apps/api python scripts/loadtest.py \
        --base-url http://localhost:8000 --users 20 --duration 1800 \
        --report docs/pruebas/evidencias/carga.json

Criterios de aceptación (docs/requisitos/nfr.md): p95 de endpoints locales <= 2 s, menos del
1 % de respuestas 5xx y cero fugas. Sale con código 1 si alguno no se cumple.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import random
import statistics
import time
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import httpx

QUERIES = [
    "Organiza una barbacoa para 10 personas",
    "Recomiéndame unas zapatillas para empezar a correr",
    "¿Cómo puedo devolver un pedido?",
    "¿Cuánto tarda el envío?",
    "¿Qué dice el manual sobre la batería?",
    "Compara las zapatillas Ligera 3 y Maratón Pro",
    "Enséñame el carrito",
    "¿Qué garantía tienen los productos?",
    "Busco un portátil ligero para la universidad",
    "¿Quién ganó el mundial de 1982?",
]


@dataclass
class Stats:
    latencies: dict[str, list[float]] = field(default_factory=lambda: defaultdict(list))
    statuses: dict[str, dict[int, int]] = field(
        default_factory=lambda: defaultdict(lambda: defaultdict(int))
    )
    errors: list[str] = field(default_factory=list)
    leaks: int = 0
    isolation_checks: int = 0
    skills: dict[str, int] = field(default_factory=lambda: defaultdict(int))

    def record(self, name: str, status: int, seconds: float) -> None:
        self.latencies[name].append(seconds)
        self.statuses[name][status] += 1


def percentile(values: list[float], pct: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, round(pct / 100 * len(ordered) + 0.5) - 1))
    return ordered[index]


class VirtualUser:
    def __init__(self, number: int, args: argparse.Namespace, stats: Stats, registry: list[str]):
        self.number = number
        self.args = args
        self.stats = stats
        self.registry = registry
        self.token = ""
        self.conversation = ""

    async def call(
        self, client: httpx.AsyncClient, name: str, method: str, path: str, **kwargs: Any
    ) -> httpx.Response | None:
        headers = {"Origin": self.args.origin, **kwargs.pop("headers", {})}
        if self.args.distinct_ips:
            # Cada usuario virtual llega con su IP (rango de pruebas 198.18.0.0/15), como detrás
            # del gateway; requiere que la red del gateway figure en WEBASSISTANT_TRUSTED_PROXIES.
            headers["X-Forwarded-For"] = f"198.18.{self.number // 250}.{self.number % 250 + 1}"
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        start = time.perf_counter()
        try:
            response = await client.request(method, path, headers=headers, **kwargs)
        except httpx.HTTPError as exc:
            self.stats.record(name, 599, time.perf_counter() - start)
            self.stats.errors.append(f"{name}: {type(exc).__name__}")
            return None
        self.stats.record(name, response.status_code, time.perf_counter() - start)
        if response.status_code >= 500:
            self.stats.errors.append(f"{name}: {response.status_code} {response.text[:160]}")
        return response

    async def start_session(self, client: httpx.AsyncClient) -> bool:
        """Nuevo visitante: token y conversación propios (reintenta si recibe 429)."""
        for attempt in range(4):
            self.token = ""
            self.conversation = ""
            await self.call(client, "bootstrap", "GET", f"/v1/bootstrap?site_id={self.args.site}")
            session = await self.call(
                client, "session", "POST", "/v1/sessions", json={"site_id": self.args.site}
            )
            if session is not None and session.status_code == 200:
                self.token = session.json()["access_token"]
                created = await self.call(
                    client, "conversation.create", "POST", "/v1/conversations", json={}
                )
                if created is not None and created.status_code == 201:
                    self.conversation = created.json()["conversation_id"]
                    self.registry.append(self.conversation)
                    return True
            await asyncio.sleep(2 * (attempt + 1))
        return False

    async def isolation_probe(self, client: httpx.AsyncClient) -> None:
        others = [c for c in self.registry if c != self.conversation]
        if not others:
            return
        target = random.choice(others)
        response = await self.call(client, "isolation.probe", "GET", f"/v1/conversations/{target}")
        self.stats.isolation_checks += 1
        if response is not None and response.status_code == 200:
            self.stats.leaks += 1
            self.stats.errors.append(f"FUGA: usuario {self.number} leyó {target}")

    async def run_action(self, client: httpx.AsyncClient, body: dict[str, Any]) -> None:
        actions = [a for a in body.get("actions", []) if a.get("type") == "host"]
        if not actions:
            return
        action = random.choice(actions)
        params = dict(action.get("params") or {})
        if action.get("target") == "item":
            items = (body.get("presentation") or {}).get("data", {}).get("items") or []
            if not items:
                return
            params = {"product_id": items[0]["product_id"], "quantity": 1}
        prepared = await self.call(
            client,
            "action.prepare",
            "POST",
            "/v1/actions/prepare",
            json={
                "conversation_id": self.conversation,
                "run_id": body.get("run_id"),
                "skill": action["skill"],
                "action": action["name"],
                "params": params,
            },
        )
        if prepared is None or prepared.status_code not in {200, 201}:
            return
        execution = prepared.json()
        executed = await self.call(
            client,
            "action.execute",
            "POST",
            f"/v1/actions/{execution['execution_id']}/execute",
            json={"confirmation_token": execution.get("confirmation_token")},
            headers={"Idempotency-Key": f"load-{self.number}-{time.time_ns()}"},
        )
        if (
            executed is not None
            and executed.status_code == 200
            and executed.json().get("host_action")
        ):
            await self.call(
                client,
                "action.result",
                "POST",
                f"/v1/actions/{execution['execution_id']}/result",
                json={"status": "succeeded", "result": {"items_count": 1}},
            )

    async def run(self, deadline: float) -> None:
        async with httpx.AsyncClient(base_url=self.args.base_url, timeout=60) as client:
            await asyncio.sleep(random.uniform(0, self.args.ramp_up))
            if not await self.start_session(client):
                return
            while time.monotonic() < deadline:
                if not self.conversation and not await self.start_session(client):
                    return
                query = random.choice(QUERIES)
                response = await self.call(
                    client,
                    "message",
                    "POST",
                    f"/v1/conversations/{self.conversation}/messages",
                    json={"message": query},
                )
                if response is not None and response.status_code == 401:
                    await self.start_session(client)
                    continue
                if response is not None and response.status_code == 200:
                    body = response.json()
                    self.stats.skills[str(body.get("skill"))] += 1
                    if random.random() < self.args.action_ratio:
                        await self.run_action(client, body)
                await self.call(client, "history", "GET", f"/v1/conversations/{self.conversation}")
                if random.random() < 0.2:
                    await self.isolation_probe(client)
                if random.random() < 0.05 and not await self.start_session(client):
                    return  # nuevo visitante en el mismo puesto
                await asyncio.sleep(random.uniform(*self.args.think_time))


def summarize(stats: Stats, args: argparse.Namespace, elapsed: float) -> dict[str, Any]:
    endpoints = {}
    total = server_errors = transport_errors = rate_limited = 0
    for name, values in sorted(stats.latencies.items()):
        statuses = dict(stats.statuses[name])
        count = len(values)
        total += count
        server_errors += sum(n for code, n in statuses.items() if 500 <= code < 599)
        transport_errors += statuses.get(599, 0)
        rate_limited += statuses.get(429, 0)
        endpoints[name] = {
            "requests": count,
            "statuses": {str(k): v for k, v in sorted(statuses.items())},
            "p50_s": round(percentile(values, 50), 4),
            "p95_s": round(percentile(values, 95), 4),
            "p99_s": round(percentile(values, 99), 4),
            "mean_s": round(statistics.fmean(values), 4) if values else 0,
            "max_s": round(max(values), 4) if values else 0,
        }
    worst_p95 = max((e["p95_s"] for e in endpoints.values()), default=0.0)
    error_rate = (server_errors + transport_errors) / total if total else 0.0
    criteria = {
        "p95_locales_max_2s": worst_p95 <= args.max_p95,
        "errores_5xx_menos_1pct": error_rate < args.max_error_rate,
        "cero_fugas_entre_usuarios": stats.leaks == 0,
    }
    return {
        "generated_at": datetime.now(UTC).isoformat(),
        "base_url": args.base_url,
        "profile": {
            "users": args.users,
            "duration_s": args.duration,
            "elapsed_s": round(elapsed, 1),
            "think_time_s": list(args.think_time),
            "provider": "simulado (sin latencia de OpenAI ni del portal)",
            "distinct_ips": args.distinct_ips,
        },
        "totals": {
            "requests": total,
            "throughput_rps": round(total / elapsed, 2) if elapsed else 0,
            "server_errors": server_errors,
            "transport_errors": transport_errors,
            "rate_limited_429": rate_limited,
            "error_rate": round(error_rate, 5),
            "worst_p95_s": worst_p95,
            "isolation_checks": stats.isolation_checks,
            "leaks": stats.leaks,
        },
        "skills": dict(sorted(stats.skills.items())),
        "endpoints": endpoints,
        "criteria": criteria,
        "passed": all(criteria.values()),
        "sample_errors": stats.errors[:20],
    }


async def main() -> int:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("--base-url", default="http://localhost:8000")
    parser.add_argument("--origin", default=None, help="Origin del widget (por defecto, base-url)")
    parser.add_argument("--site", default="site_demo")
    parser.add_argument("--users", type=int, default=20)
    parser.add_argument("--duration", type=int, default=1800, help="segundos")
    parser.add_argument("--ramp-up", type=float, default=20.0)
    parser.add_argument("--think-time", type=float, nargs=2, default=(3.0, 6.0))
    parser.add_argument("--action-ratio", type=float, default=0.3)
    parser.add_argument("--max-p95", type=float, default=2.0)
    parser.add_argument("--max-error-rate", type=float, default=0.01)
    parser.add_argument(
        "--same-ip",
        dest="distinct_ips",
        action="store_false",
        help="no simular IPs distintas por usuario (X-Forwarded-For)",
    )
    parser.add_argument("--report", type=Path, default=None)
    args = parser.parse_args()
    args.origin = args.origin or args.base_url
    stats = Stats()
    registry: list[str] = []
    started = time.monotonic()
    deadline = started + args.duration
    users = [VirtualUser(i, args, stats, registry) for i in range(args.users)]
    await asyncio.gather(*(user.run(deadline) for user in users))
    report = summarize(stats, args, time.monotonic() - started)
    text = json.dumps(report, ensure_ascii=False, indent=2)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(text + "\n", encoding="utf-8")
    print(text)
    return 0 if report["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
