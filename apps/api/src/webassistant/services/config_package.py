"""Paquete de configuración versionado: exportación e importación idempotente (CG-16.1, CG-16.2).

Incluye assistants, agentes, skills activas, precios, mensajes, límites, sites,
bases de conocimiento y retención. Nunca incluye secretos (clientes API,
usuarios, claves). El importador valida el paquete completo antes de modificar
datos, admite ``dry_run`` y dos ejecuciones producen el mismo estado.
"""

from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from webassistant import ids
from webassistant.contracts import validate
from webassistant.db import models as m
from webassistant.db.repositories import UnitOfWork
from webassistant.db.session import Database
from webassistant.errors import validation
from webassistant.orchestrator.assistants import (
    DEFAULT_AGENT_POLICY,
    AssistantService,
    merge_config,
)
from webassistant.security.origins import validate_origin_pattern

_SECRET_KEYS = {"secret", "password", "api_key", "token", "client_secret", "access_token"}


def _contains_secret(value: Any) -> bool:
    if isinstance(value, dict):
        return any(k.lower() in _SECRET_KEYS or _contains_secret(v) for k, v in value.items())
    if isinstance(value, list):
        return any(_contains_secret(v) for v in value)
    return False


def _dt(value: Any) -> datetime:
    if isinstance(value, datetime):
        return value if value.tzinfo else value.replace(tzinfo=UTC)
    return datetime.fromisoformat(str(value).replace("Z", "+00:00"))


class ConfigPackageService:
    def __init__(self, db: Database, assistants: AssistantService) -> None:
        self.db = db
        self.assistants = assistants

    def check(self, package: dict[str, Any]) -> list[dict[str, Any]]:
        errors = validate("config-package.v1.schema.json", package)
        if _contains_secret(package):
            errors.append({"path": "/", "message": "El paquete no puede contener secretos"})
        for index, site in enumerate(package.get("sites") or []):
            for origin in site.get("allowed_origins") or []:
                try:
                    validate_origin_pattern(origin)
                except ValueError as exc:
                    errors.append({"path": f"/sites/{index}/allowed_origins", "message": str(exc)})
        for index, assistant in enumerate(package.get("assistants") or []):
            try:
                AssistantService.validate_config(assistant.get("config") or {})
            except Exception as exc:
                errors.append({"path": f"/assistants/{index}/config", "message": str(exc)})
        return errors

    async def import_package(
        self, package: dict[str, Any], *, dry_run: bool = False, actor_id: str = "cli"
    ) -> dict[str, Any]:
        errors = self.check(package)
        if errors:
            raise validation("Paquete de configuración no válido", errors)
        changes: list[dict[str, Any]] = []
        tenant_data = package["tenant"]
        async with UnitOfWork(self.db, platform=True) as platform_uow:
            tenant = await platform_uow.tenants.by_slug(tenant_data["slug"])
            if tenant is None:
                changes.append(
                    {"entity": "tenant", "key": tenant_data["slug"], "operation": "create"}
                )
                tenant = m.Tenant(
                    id=ids.new_id("tenant"),
                    slug=tenant_data["slug"],
                    name=tenant_data["name"],
                    settings=tenant_data.get("settings") or {},
                )
                if not dry_run:
                    await platform_uow.tenants.add(tenant)
            elif tenant.name != tenant_data["name"] or tenant.settings != (
                tenant_data.get("settings") or {}
            ):
                changes.append({"entity": "tenant", "key": tenant.slug, "operation": "update"})
                if not dry_run:
                    tenant.name = tenant_data["name"]
                    tenant.settings = tenant_data.get("settings") or {}
            for price in package.get("model_prices") or []:
                valid_from = _dt(price["valid_from"])
                existing = await platform_uow.model_prices.first(
                    m.ModelPrice.provider == price["provider"],
                    m.ModelPrice.model == price["model"],
                    m.ModelPrice.valid_from == valid_from,
                )
                if existing is None:
                    changes.append(
                        {
                            "entity": "model_price",
                            "key": f"{price['provider']}:{price['model']}",
                            "operation": "create",
                        }
                    )
                    if not dry_run:
                        await platform_uow.model_prices.add(
                            m.ModelPrice(
                                id=ids.new_id("price"),
                                provider=price["provider"],
                                model=price["model"],
                                input_per_million=Decimal(str(price["input_per_million"])),
                                cached_per_million=Decimal(str(price.get("cached_per_million", 0))),
                                output_per_million=Decimal(str(price["output_per_million"])),
                                currency=price.get("currency", "USD"),
                                valid_from=valid_from,
                                valid_to=_dt(price["valid_to"]) if price.get("valid_to") else None,
                                source=price.get("source", "config-package"),
                                created_by=actor_id,
                            )
                        )
            tenant_id = tenant.id
        if dry_run and not await self._tenant_exists(tenant_id):
            # Sin tenant persistido no se pueden evaluar el resto de entidades con RLS: se informan como altas.
            for section, key in (
                ("sites", "public_id"),
                ("agent_configs", "key"),
                ("knowledge_bases", "key"),
                ("assistants", "key"),
            ):
                for item in package.get(section) or []:
                    changes.append(
                        {"entity": section[:-1], "key": item[key], "operation": "create"}
                    )
            return {"dry_run": True, "tenant_id": None, "changes": changes}

        async with UnitOfWork(self.db, tenant_id) as uow:
            row: Any
            agent_ids: dict[str, str] = {}
            for agent in package.get("agent_configs") or []:
                row = await uow.agent_configs.by_key(agent["key"])
                if row is None:
                    changes.append(
                        {"entity": "agent_config", "key": agent["key"], "operation": "create"}
                    )
                    row = m.AgentConfig(
                        id=ids.new_id("agent_config"),
                        tenant_id=tenant_id,
                        key=agent["key"],
                        name=agent.get("name", agent["key"]),
                        policy=agent["policy"],
                    )
                    if not dry_run:
                        await uow.agent_configs.add(row)
                elif row.policy != agent["policy"] or row.name != agent.get("name", agent["key"]):
                    changes.append(
                        {"entity": "agent_config", "key": agent["key"], "operation": "update"}
                    )
                    if not dry_run:
                        row.policy = agent["policy"]
                        row.name = agent.get("name", agent["key"])
                agent_ids[agent["key"]] = row.id
            assistant_ids: dict[str, str] = {}
            for item in package.get("assistants") or []:
                row = await uow.assistants.by_key(item["key"])
                agent_id = (
                    agent_ids.get(item.get("agent_config", ""))
                    if item.get("agent_config")
                    else None
                )
                if row is None:
                    changes.append(
                        {"entity": "assistant", "key": item["key"], "operation": "create"}
                    )
                    row = m.Assistant(
                        id=ids.new_id("assistant"),
                        tenant_id=tenant_id,
                        key=item["key"],
                        name=item["name"],
                        draft_config=item["config"],
                        agent_config_id=agent_id,
                        status="draft",
                    )
                    if not dry_run:
                        await uow.assistants.add(row)
                elif (
                    row.draft_config != item["config"]
                    or row.name != item["name"]
                    or row.agent_config_id != agent_id
                ):
                    changes.append(
                        {"entity": "assistant", "key": item["key"], "operation": "update"}
                    )
                    if not dry_run:
                        row.draft_config = item["config"]
                        row.name = item["name"]
                        row.agent_config_id = agent_id
                assistant_ids[item["key"]] = row.id
                if item.get("publish", True) and not dry_run:
                    published = (
                        await uow.assistant_versions.by_number(row.id, row.published_version)
                        if row.published_version
                        else None
                    )
                    current = AssistantService.validate_config(row.draft_config)
                    agent = await uow.agent_configs.get(agent_id) if agent_id else None
                    policy = merge_config(DEFAULT_AGENT_POLICY, agent.policy if agent else {})
                    if (
                        published is None
                        or published.config != current
                        or published.agent_policy != policy
                    ):
                        await self.assistants.publish(
                            uow, row, actor_id=actor_id, note="config-package"
                        )
                        changes.append(
                            {
                                "entity": "assistant_version",
                                "key": item["key"],
                                "operation": "publish",
                            }
                        )
            for site in package.get("sites") or []:
                row = await uow.sites.by_public_id(site["public_id"])
                values = {
                    "name": site["name"],
                    "allowed_origins": [
                        validate_origin_pattern(o) for o in site["allowed_origins"]
                    ],
                    "access_mode": site.get("access_mode", "public"),
                    "default_locale": site.get("default_locale", "es"),
                    "languages": site.get("languages") or [site.get("default_locale", "es")],
                    "theme": site.get("theme") or {},
                    "settings": site.get("settings") or {},
                    "capabilities": site.get("capabilities") or [],
                    "status": site.get("status", "active"),
                    "default_assistant_id": assistant_ids.get(site.get("default_assistant", ""))
                    or (row.default_assistant_id if row else None),
                }
                if row is None:
                    changes.append(
                        {"entity": "site", "key": site["public_id"], "operation": "create"}
                    )
                    if not dry_run:
                        await uow.sites.add(
                            m.Site(
                                id=ids.new_id("site"),
                                tenant_id=tenant_id,
                                public_id=site["public_id"],
                                **values,
                            )
                        )
                elif any(getattr(row, k) != v for k, v in values.items()):
                    changes.append(
                        {"entity": "site", "key": site["public_id"], "operation": "update"}
                    )
                    if not dry_run:
                        for key, value in values.items():
                            setattr(row, key, value)
            for kb in package.get("knowledge_bases") or []:
                row = await uow.knowledge_bases.by_key(kb["key"])
                site_row = await uow.sites.by_public_id(kb["site"]) if kb.get("site") else None
                values = {
                    "name": kb["name"],
                    "description": kb.get("description", ""),
                    "default_locale": kb.get("default_locale", "es"),
                    "chunking": kb.get("chunking") or {},
                    "site_id": site_row.id if site_row else None,
                    "status": kb.get("status", "active"),
                }
                if row is None:
                    changes.append(
                        {"entity": "knowledge_base", "key": kb["key"], "operation": "create"}
                    )
                    if not dry_run:
                        await uow.knowledge_bases.add(
                            m.KnowledgeBase(
                                id=ids.new_id("knowledge_base"),
                                tenant_id=tenant_id,
                                key=kb["key"],
                                **values,
                            )
                        )
                elif any(getattr(row, k) != v for k, v in values.items()):
                    changes.append(
                        {"entity": "knowledge_base", "key": kb["key"], "operation": "update"}
                    )
                    if not dry_run:
                        for key, value in values.items():
                            setattr(row, key, value)
            for policy in package.get("policies") or []:
                assistant_id = (
                    assistant_ids.get(policy.get("assistant", ""))
                    if policy.get("assistant")
                    else None
                )
                row = await uow.policies.first(
                    m.TenantPolicy.assistant_id.is_(None)
                    if assistant_id is None
                    else m.TenantPolicy.assistant_id == assistant_id
                )
                values = {k: v for k, v in policy.items() if k != "assistant"}
                for money in ("daily_budget", "monthly_budget"):
                    if values.get(money) is not None:
                        values[money] = Decimal(str(values[money]))
                if row is None:
                    changes.append(
                        {
                            "entity": "policy",
                            "key": policy.get("assistant", "tenant"),
                            "operation": "create",
                        }
                    )
                    if not dry_run:
                        await uow.policies.add(
                            m.TenantPolicy(
                                id=ids.new_id("policy"),
                                tenant_id=tenant_id,
                                assistant_id=assistant_id,
                                **values,
                            )
                        )
                elif any(getattr(row, k) != v for k, v in values.items()):
                    changes.append(
                        {
                            "entity": "policy",
                            "key": policy.get("assistant", "tenant"),
                            "operation": "update",
                        }
                    )
                    if not dry_run:
                        for key, value in values.items():
                            setattr(row, key, value)
            if package.get("retention"):
                row = await uow.retention_policies.first()
                values = dict(package["retention"])
                if row is None:
                    changes.append(
                        {"entity": "retention", "key": tenant_data["slug"], "operation": "create"}
                    )
                    if not dry_run:
                        await uow.retention_policies.add(
                            m.RetentionPolicy(
                                id=ids.new_id("policy"), tenant_id=tenant_id, **values
                            )
                        )
                elif any(getattr(row, k) != v for k, v in values.items()):
                    changes.append(
                        {"entity": "retention", "key": tenant_data["slug"], "operation": "update"}
                    )
                    if not dry_run:
                        for key, value in values.items():
                            setattr(row, key, value)
            if dry_run:
                await uow.session.rollback()
        return {"dry_run": dry_run, "tenant_id": tenant_id, "changes": changes}

    async def _tenant_exists(self, tenant_id: str) -> bool:
        async with UnitOfWork(self.db, platform=True) as uow:
            return await uow.tenants.get(tenant_id) is not None

    async def export_package(self, tenant_id: str) -> dict[str, Any]:
        async with UnitOfWork(self.db, tenant_id) as uow:
            tenant = await uow.tenants.require(tenant_id)
            agents = await uow.agent_configs.list(order_by=m.AgentConfig.key)
            agent_keys = {a.id: a.key for a in agents}
            assistants = await uow.assistants.list(order_by=m.Assistant.key)
            assistant_keys = {a.id: a.key for a in assistants}
            sites = await uow.sites.list(order_by=m.Site.public_id)
            site_keys = {s.id: s.public_id for s in sites}
            kbs = await uow.knowledge_bases.list(order_by=m.KnowledgeBase.key)
            policies = await uow.policies.list()
            retention = await uow.retention_policies.first()
        async with UnitOfWork(self.db, platform=True) as platform_uow:
            prices = await platform_uow.model_prices.list(
                order_by=[m.ModelPrice.model, m.ModelPrice.valid_from]
            )
        package: dict[str, Any] = {
            "format": "webassistant.config-package",
            "version": "1",
            "exported_at": datetime.now(UTC).isoformat(),
            "tenant": {"slug": tenant.slug, "name": tenant.name, "settings": tenant.settings},
            "agent_configs": [{"key": a.key, "name": a.name, "policy": a.policy} for a in agents],
            "assistants": [
                {
                    "key": a.key,
                    "name": a.name,
                    "config": a.draft_config,
                    "agent_config": agent_keys.get(a.agent_config_id or ""),
                    "publish": a.published_version is not None,
                }
                for a in assistants
            ],
            "sites": [
                {
                    "public_id": s.public_id,
                    "name": s.name,
                    "allowed_origins": s.allowed_origins,
                    "access_mode": s.access_mode,
                    "default_locale": s.default_locale,
                    "languages": s.languages,
                    "theme": s.theme,
                    "settings": s.settings,
                    "capabilities": s.capabilities,
                    "status": s.status,
                    "default_assistant": assistant_keys.get(s.default_assistant_id or ""),
                }
                for s in sites
            ],
            "knowledge_bases": [
                {
                    "key": k.key,
                    "name": k.name,
                    "description": k.description,
                    "default_locale": k.default_locale,
                    "chunking": k.chunking,
                    "site": site_keys.get(k.site_id or ""),
                    "status": k.status,
                }
                for k in kbs
            ],
            "policies": [
                {
                    "assistant": assistant_keys.get(p.assistant_id or "")
                    if p.assistant_id
                    else None,
                    "allowed_models": p.allowed_models,
                    "max_input_tokens_per_run": p.max_input_tokens_per_run,
                    "max_output_tokens_per_call": p.max_output_tokens_per_call,
                    "daily_budget": str(p.daily_budget) if p.daily_budget is not None else None,
                    "monthly_budget": str(p.monthly_budget)
                    if p.monthly_budget is not None
                    else None,
                    "currency": p.currency,
                    "alert_threshold_pct": p.alert_threshold_pct,
                    "max_concurrent_runs": p.max_concurrent_runs,
                    "max_concurrent_runs_per_subject": p.max_concurrent_runs_per_subject,
                }
                for p in policies
            ],
            "model_prices": [
                {
                    "provider": p.provider,
                    "model": p.model,
                    "input_per_million": str(p.input_per_million),
                    "cached_per_million": str(p.cached_per_million),
                    "output_per_million": str(p.output_per_million),
                    "currency": p.currency,
                    "valid_from": p.valid_from.isoformat(),
                    "valid_to": p.valid_to.isoformat() if p.valid_to else None,
                    "source": p.source,
                }
                for p in prices
            ],
        }
        for policy in package["policies"]:
            if policy["assistant"] is None:
                policy.pop("assistant")
        if retention is not None:
            package["retention"] = {
                "conversations_days": retention.conversations_days,
                "payloads_days": retention.payloads_days,
                "audit_days": retention.audit_days,
                "usage_days": retention.usage_days,
                "mode": retention.mode,
            }
        for price in package["model_prices"]:
            if price["valid_to"] is None:
                price.pop("valid_to")
        return package
