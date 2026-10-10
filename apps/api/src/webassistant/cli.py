"""CLI de operación: ``webassistant <comando>``.

Comandos principales:

* ``migrate``: aplica migraciones Alembic.
* ``seed-demo``: crea/actualiza los datos de demostración (idempotente).
* ``config-import`` / ``config-export``: paquete de configuración (``--dry-run``).
* ``create-admin``: crea un usuario de administración.
* ``create-client``: crea un cliente API server-to-server (muestra el secreto una vez).
* ``retention``: aplica la política de retención.
* ``rag-eval``: evalúa la calidad de recuperación con un dataset.
* ``export-openapi``: escribe el contrato OpenAPI en ``packages/contracts/openapi``.
* ``generate-key``: genera una clave Fernet para ``WEBASSISTANT_ENCRYPTION_KEY``.
"""

from __future__ import annotations

import asyncio
import json
import sys
from collections.abc import Awaitable, Callable
from pathlib import Path
from typing import Any, TypeVar

import typer
import yaml

from webassistant.config import get_settings

app = typer.Typer(help="Operación de WebAssistant AI", no_args_is_help=True)
T = TypeVar("T")


def _run(factory: Callable[[Any], Awaitable[T]]) -> T:
    from webassistant.services.container import build_services

    async def runner() -> T:
        services = build_services(get_settings())
        await services.startup(create_schema=services.db.is_sqlite)
        try:
            return await factory(services)
        finally:
            await services.shutdown()

    return asyncio.run(runner())


@app.command()
def migrate(revision: str = "head") -> None:
    """Aplica las migraciones de PostgreSQL."""
    from alembic import command
    from alembic.config import Config

    candidates = [Path("/app/alembic.ini"), Path(__file__).resolve().parents[2] / "alembic.ini"]
    ini = next((c for c in candidates if c.is_file()), None)
    if ini is None:
        typer.echo("No se encuentra alembic.ini", err=True)
        raise typer.Exit(1)
    config = Config(str(ini))
    command.upgrade(config, revision)
    typer.echo(f"Migraciones aplicadas hasta {revision}")


@app.command("seed-demo")
def seed_demo(ingest: bool = True) -> None:
    """Crea o actualiza los datos de la demo."""
    from webassistant.seeds.demo import ensure_demo

    async def action(services: Any) -> dict[str, Any]:
        result = await ensure_demo(services, ingest=ingest)
        queue = services.queue
        if hasattr(queue, "drain"):
            await queue.drain()
        return result

    typer.echo(json.dumps(_run(action), indent=2, ensure_ascii=False))


@app.command("config-import")
def config_import(path: Path, dry_run: bool = typer.Option(False, "--dry-run")) -> None:
    """Importa un paquete de configuración YAML/JSON (idempotente)."""
    from webassistant.services.config_package import ConfigPackageService

    package = yaml.safe_load(path.read_text(encoding="utf-8"))

    async def action(services: Any) -> dict[str, Any]:
        return await ConfigPackageService(services.db, services.assistants).import_package(
            package, dry_run=dry_run
        )

    typer.echo(json.dumps(_run(action), indent=2, ensure_ascii=False))


@app.command("config-export")
def config_export(tenant_slug: str, output: Path | None = None) -> None:
    """Exporta la configuración de un tenant (sin secretos)."""
    from webassistant.db.repositories import UnitOfWork
    from webassistant.services.config_package import ConfigPackageService

    async def action(services: Any) -> dict[str, Any]:
        async with UnitOfWork(services.db, platform=True) as uow:
            tenant = await uow.tenants.by_slug(tenant_slug)
            if tenant is None:
                raise typer.BadParameter("Tenant no encontrado")
            tenant_id = tenant.id
        return await ConfigPackageService(services.db, services.assistants).export_package(
            tenant_id
        )

    data = yaml.safe_dump(_run(action), allow_unicode=True, sort_keys=False)
    if output:
        output.write_text(data, encoding="utf-8")
    else:
        sys.stdout.write(data)


@app.command("create-admin")
def create_admin(
    email: str,
    role: str = "platform_admin",
    tenant_slug: str | None = None,
    password: str = typer.Option(..., prompt=True, hide_input=True),
) -> None:
    """Crea un usuario de administración."""
    from webassistant import ids
    from webassistant.db import models as m
    from webassistant.db.repositories import UnitOfWork
    from webassistant.security.crypto import hash_secret, password_problems

    problems = password_problems(password)
    if problems:
        raise typer.BadParameter(" ".join(problems))

    async def action(services: Any) -> str:
        async with UnitOfWork(services.db, platform=True) as uow:
            tenant_id = None
            if tenant_slug:
                tenant = await uow.tenants.by_slug(tenant_slug)
                if tenant is None:
                    raise typer.BadParameter("Tenant no encontrado")
                tenant_id = tenant.id
            if await uow.admin_users.by_email(email):
                raise typer.BadParameter("El usuario ya existe")
            user = m.AdminUser(
                id=ids.new_id("admin_user"),
                tenant_id=tenant_id,
                email=email.lower(),
                role=role,
                password_hash=hash_secret(password),
            )
            await uow.admin_users.add(user)
            return user.id

    typer.echo(f"Usuario creado: {_run(action)}")


@app.command("create-client")
def create_client(
    tenant_slug: str,
    name: str,
    site_public_id: str | None = None,
    scopes: str = "token:issue,ingest:write,ingest:read,search:read",
) -> None:
    """Crea un cliente API server-to-server; el secreto se muestra una sola vez."""
    from webassistant import ids
    from webassistant.db import models as m
    from webassistant.db.repositories import UnitOfWork
    from webassistant.security.crypto import hash_secret

    async def action(services: Any) -> dict[str, str]:
        async with UnitOfWork(services.db, platform=True) as uow:
            tenant = await uow.tenants.by_slug(tenant_slug)
            if tenant is None:
                raise typer.BadParameter("Tenant no encontrado")
            site = await uow.sites.by_public_id(site_public_id) if site_public_id else None
            secret = ids.new_secret(32)
            client = m.ApiClient(
                id=ids.new_id("api_client"),
                tenant_id=tenant.id,
                site_id=site.id if site else None,
                name=name,
                client_id="wa_" + ids.new_secret(12).replace("-", "").replace("_", "")[:16].lower(),
                secret_hash=hash_secret(secret),
                scopes=[s.strip() for s in scopes.split(",") if s.strip()],
            )
            await uow.api_clients.add(client)
            return {"client_id": client.client_id, "client_secret": secret}

    typer.echo(json.dumps(_run(action), indent=2))


@app.command()
def retention(tenant_slug: str | None = None) -> None:
    """Aplica la política de retención a uno o todos los tenants."""
    from webassistant.db.repositories import UnitOfWork
    from webassistant.retention.service import RetentionService

    async def action(services: Any) -> list[dict[str, Any]]:
        tenant_id = None
        if tenant_slug:
            async with UnitOfWork(services.db, platform=True) as uow:
                tenant = await uow.tenants.by_slug(tenant_slug)
                tenant_id = tenant.id if tenant else None
        return await RetentionService(services.db, services.audit).apply(tenant_id)

    typer.echo(json.dumps(_run(action), indent=2, default=str))


@app.command("rag-eval")
def rag_eval(
    dataset: Path,
    tenant_slug: str = "demo",
    site_public_id: str = "site_demo",
    k: int = 5,
    min_hit_rate: float = 0.8,
) -> None:
    """Evalúa la recuperación: hit rate@k, MRR y fuentes inesperadas (CG-07.7)."""
    from webassistant.rag.evaluation import evaluate

    data = yaml.safe_load(dataset.read_text(encoding="utf-8"))

    async def action(services: Any) -> dict[str, Any]:
        return await evaluate(
            services, data, tenant_slug=tenant_slug, site_public_id=site_public_id, k=k
        )

    report = _run(action)
    typer.echo(json.dumps(report, indent=2, ensure_ascii=False))
    if report["hit_rate"] < min_hit_rate:
        typer.echo(
            f"Hit rate {report['hit_rate']:.2f} por debajo del umbral {min_hit_rate}", err=True
        )
        raise typer.Exit(1)


@app.command("export-openapi")
def export_openapi(output: Path = Path("packages/contracts/openapi/openapi.json")) -> None:
    """Escribe el contrato OpenAPI 3.1 de la API v1."""
    from webassistant.api.app import create_app
    from webassistant.services.container import build_services

    settings = get_settings()
    application = create_app(settings, build_services(settings))
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(
        json.dumps(application.openapi(), indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    typer.echo(f"OpenAPI escrito en {output}")


@app.command("generate-key")
def generate_key() -> None:
    """Genera una clave Fernet aleatoria."""
    from webassistant.security.crypto import generate_encryption_key

    typer.echo(generate_encryption_key())


if __name__ == "__main__":
    app()
