"""Extracción de texto de originales del portal (CG-07.3).

Cuando la ingesta recibe una referencia en lugar de texto, el worker descarga
el original con acceso autorizado a un fichero temporal, extrae el texto y
elimina la copia al terminar, también si el procesamiento falla. Las
credenciales temporales no se persisten ni aparecen en citas.
"""

from __future__ import annotations

import os
import tempfile
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlsplit

import httpx

from webassistant.errors import AppError, ErrorCode
from webassistant.text import clean_whitespace, strip_html

PDF = "application/pdf"
DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"


@dataclass
class ExtractedText:
    text: str
    content_format: str = "text"
    pages: list[str] = field(default_factory=list)


@asynccontextmanager
async def temporary_download(
    url: str,
    *,
    access_token: str | None,
    allowed_hosts: list[str],
    max_bytes: int,
    timeout: float,
    temp_dir: Path | None = None,
) -> AsyncIterator[Path]:
    host = (urlsplit(url).hostname or "").lower()
    if allowed_hosts and not any(host == h or host.endswith("." + h) for h in allowed_hosts):
        raise AppError(ErrorCode.FORBIDDEN, f"Host de descarga no permitido: {host}")
    headers = {"Authorization": f"Bearer {access_token}"} if access_token else {}
    fd, name = tempfile.mkstemp(prefix="wa-ingest-", dir=str(temp_dir) if temp_dir else None)
    path = Path(name)
    try:
        with os.fdopen(fd, "wb") as handle:
            async with (
                httpx.AsyncClient(timeout=timeout, follow_redirects=False) as client,
                client.stream("GET", url, headers=headers) as response,
            ):
                if response.status_code != 200:
                    raise AppError(
                        ErrorCode.TOOL_FAILED, f"Descarga fallida: HTTP {response.status_code}"
                    )
                size = 0
                async for chunk in response.aiter_bytes():
                    size += len(chunk)
                    if size > max_bytes:
                        raise AppError(
                            ErrorCode.PAYLOAD_TOO_LARGE, "El documento supera el tamaño máximo"
                        )
                    handle.write(chunk)
        yield path
    finally:
        path.unlink(missing_ok=True)  # noqa: ASYNC240 - limpieza local, no bloqueante en la práctica


def extract_file(path: Path, media_type: str) -> ExtractedText:
    if media_type == PDF:
        from pypdf import PdfReader

        reader = PdfReader(str(path))
        pages = [clean_whitespace(page.extract_text() or "") for page in reader.pages]
        return ExtractedText(
            text="\n\n".join(p for p in pages if p), content_format="text", pages=pages
        )
    if media_type == DOCX:
        from docx import Document as DocxDocument

        document = DocxDocument(str(path))
        lines: list[str] = []
        for paragraph in document.paragraphs:
            text = paragraph.text.strip()
            if not text:
                continue
            style = (paragraph.style.name if paragraph.style is not None else "").lower()
            if style.startswith("heading"):
                level = "".join(ch for ch in style if ch.isdigit()) or "1"
                lines.append(f"{'#' * min(6, int(level))} {text}")
            else:
                lines.append(text)
        return ExtractedText(text="\n\n".join(lines), content_format="markdown")
    raw = path.read_text(encoding="utf-8", errors="replace")
    if media_type == "text/html":
        return ExtractedText(text=raw, content_format="html")
    if media_type == "text/markdown":
        return ExtractedText(text=raw, content_format="markdown")
    return ExtractedText(text=clean_whitespace(raw), content_format="text")


def normalize_inline(text: str, content_format: str) -> ExtractedText:
    if content_format == "html":
        return ExtractedText(text=text, content_format="html")
    if content_format == "markdown":
        return ExtractedText(text=text, content_format="markdown")
    return ExtractedText(
        text=clean_whitespace(strip_html(text) if "<" in text else text), content_format="text"
    )
