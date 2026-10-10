"""Publicación de activos del widget, consola y demo (CG-10.8, CG-16.3).

- ``/widget/v1/loader.js``: caché corta (5 min) para poder desplegar versiones.
- ``/widget/v1/assets/<fichero con hash>``: caché larga e inmutable. Los
  bundles anteriores se conservan en el directorio de activos para no romper
  widgets ya abiertos.
- ``/admin/``: consola de administración (SPA).
- ``/demo/``: web HTML mínima de demostración (solo si ``WEBASSISTANT_DEMO_ENABLED``).
"""

from __future__ import annotations

import mimetypes
import re
from pathlib import Path

from fastapi import APIRouter, Request
from fastapi.responses import FileResponse, HTMLResponse, RedirectResponse, Response

from webassistant.errors import AppError, ErrorCode

router = APIRouter(include_in_schema=False)

_SAFE_NAME = re.compile(r"^[A-Za-z0-9._-]{1,120}$")
_HERE = Path(__file__).resolve()


def _first_existing(*candidates: Path | None) -> Path | None:
    for candidate in candidates:
        if candidate is not None and candidate.is_dir():
            return candidate
    return None


def _repo_dir(*parts: str) -> Path | None:
    for parent in _HERE.parents:
        candidate = parent.joinpath(*parts)
        if candidate.is_dir():
            return candidate
    return None


def widget_dir(request: Request) -> Path | None:
    settings = request.app.state.services.settings
    return _first_existing(
        settings.widget_dist_dir, Path("/app/static/widget"), _repo_dir("apps", "widget", "dist")
    )


def admin_dir(request: Request) -> Path | None:
    settings = request.app.state.services.settings
    return _first_existing(
        settings.admin_dist_dir, Path("/app/static/admin"), _repo_dir("apps", "admin", "dist")
    )


def demo_dir() -> Path | None:
    return _first_existing(Path("/app/static/demo"), _repo_dir("demo"))


def _serve(base: Path | None, name: str, cache: str) -> Response:
    if base is None or not _SAFE_NAME.match(name):
        raise AppError(ErrorCode.NOT_FOUND, "Recurso no encontrado")
    path = (base / name).resolve()
    if base.resolve() not in path.parents or not path.is_file():
        raise AppError(ErrorCode.NOT_FOUND, "Recurso no encontrado")
    media_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
    if path.suffix == ".js":
        media_type = "text/javascript; charset=utf-8"
    return FileResponse(path, media_type=media_type, headers={"Cache-Control": cache})


@router.get("/widget/v1/loader.js")
async def loader(request: Request) -> Response:
    return _serve(widget_dir(request), "loader.js", "public, max-age=300, must-revalidate")


@router.get("/widget/v1/manifest.json")
async def manifest(request: Request) -> Response:
    return _serve(widget_dir(request), "manifest.json", "public, max-age=60, must-revalidate")


@router.get("/widget/v1/assets/{name}")
async def widget_asset(name: str, request: Request) -> Response:
    base = widget_dir(request)
    return _serve(base / "assets" if base else None, name, "public, max-age=31536000, immutable")


@router.get("/admin")
async def admin_redirect() -> RedirectResponse:
    return RedirectResponse("/admin/", status_code=308)


@router.get("/admin/{name:path}")
async def admin_console(name: str, request: Request) -> Response:
    base = admin_dir(request)
    if base is None:
        return HTMLResponse(
            "<!doctype html><title>WebAssistant</title><p>Consola no compilada (apps/admin).</p>",
            status_code=404,
        )
    if name and _SAFE_NAME.match(name.rsplit("/", maxsplit=1)[-1]) and (base / name).is_file():
        cache = "public, max-age=31536000, immutable" if name.startswith("assets/") else "no-cache"
        return _serve(base / Path(name).parent if "/" in name else base, Path(name).name, cache)
    return _serve(base, "index.html", "no-cache")


@router.get("/demo")
async def demo_redirect() -> RedirectResponse:
    return RedirectResponse("/demo/", status_code=308)


@router.get("/demo/{name:path}")
async def demo(name: str, request: Request) -> Response:
    if not request.app.state.services.settings.demo_enabled:
        raise AppError(ErrorCode.NOT_FOUND, "Demo deshabilitada")
    return _serve(demo_dir(), name or "index.html", "no-cache")
