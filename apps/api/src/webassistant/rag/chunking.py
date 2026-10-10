"""División en chunks configurable por fuente (CG-07.4).

Cada chunk conserva título, jerarquía de encabezados, posición, página y URL
(con ancla cuando la hay) para que las citas reconstruyan el origen exacto.
"""

from __future__ import annotations

import itertools
import re
from dataclasses import dataclass, field
from typing import Any

from webassistant.text import clean_whitespace, strip_html

_MD_HEADING = re.compile(r"^(#{1,6})\s+(.+?)\s*#*\s*$")
_HTML_HEADING = re.compile(r"(?is)<h([1-6])[^>]*?(?:id=[\"']([^\"']+)[\"'])?[^>]*>(.*?)</h\1>")


@dataclass
class ChunkingConfig:
    strategy: str = "heading"  # heading | paragraph | whole
    max_chars: int = 1400
    overlap_chars: int = 150
    min_chars: int = 40

    @classmethod
    def from_dict(cls, data: dict[str, Any] | None) -> ChunkingConfig:
        data = data or {}
        return cls(
            strategy=str(data.get("strategy", "heading")),
            max_chars=max(200, min(8000, int(data.get("max_chars", 1400)))),
            overlap_chars=max(0, min(1000, int(data.get("overlap_chars", 150)))),
            min_chars=max(0, int(data.get("min_chars", 40))),
        )


@dataclass
class Section:
    heading_path: list[str]
    text: str
    page: int | None = None
    anchor: str | None = None


@dataclass
class ChunkDraft:
    position: int
    text: str
    title: str
    heading_path: list[str] = field(default_factory=list)
    page: int | None = None
    anchor: str | None = None


def _slug(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")[:80]


def sections_from_markdown(text: str) -> list[Section]:
    sections: list[Section] = []
    path: list[str] = []
    buffer: list[str] = []
    anchor: str | None = None

    def flush() -> None:
        body = clean_whitespace("\n".join(buffer))
        if body:
            sections.append(Section(list(path), body, anchor=anchor))
        buffer.clear()

    for line in text.splitlines():
        match = _MD_HEADING.match(line.strip())
        if match:
            flush()
            level = len(match.group(1))
            title = match.group(2).strip()
            path = [*path[: level - 1], title]
            anchor = _slug(title)
            continue
        buffer.append(line)
    flush()
    return sections


def sections_from_html(html_text: str) -> list[Section]:
    marked = _HTML_HEADING.sub(
        lambda m: f"\n{'#' * int(m.group(1))} {strip_html(m.group(3)).strip()}\n", html_text
    )
    return sections_from_markdown(clean_whitespace(strip_html(marked)))


def sections_from_pages(pages: list[str]) -> list[Section]:
    return [
        Section([], clean_whitespace(text), page=index + 1)
        for index, text in enumerate(pages)
        if text.strip()
    ]


def _split(text: str, max_chars: int, overlap: int) -> list[str]:
    if len(text) <= max_chars:
        return [text]
    paragraphs = [p.strip() for p in re.split(r"\n{2,}", text) if p.strip()]
    pieces: list[str] = []
    current = ""
    for original in paragraphs:
        paragraph = original
        while len(paragraph) > max_chars:
            cut = paragraph.rfind(". ", 0, max_chars)
            cut = cut + 1 if cut > max_chars // 2 else max_chars
            head, paragraph = paragraph[:cut].strip(), paragraph[cut:].strip()
            if current:
                pieces.append(current)
                current = ""
            pieces.append(head)
        if not current:
            current = paragraph
        elif len(current) + len(paragraph) + 2 <= max_chars:
            current = f"{current}\n\n{paragraph}"
        else:
            pieces.append(current)
            current = paragraph
    if current:
        pieces.append(current)
    if overlap <= 0 or len(pieces) < 2:
        return pieces
    overlapped = [pieces[0]]
    for previous, piece in itertools.pairwise(pieces):
        tail = previous[-overlap:]
        space = tail.find(" ")
        tail = tail[space + 1 :] if space >= 0 else tail
        overlapped.append(f"{tail} {piece}".strip())
    return overlapped


def chunk_document(
    *,
    title: str,
    text: str,
    content_format: str,
    config: ChunkingConfig,
    pages: list[str] | None = None,
) -> list[ChunkDraft]:
    if pages:
        sections = sections_from_pages(pages)
    elif content_format == "html":
        sections = sections_from_html(text)
    elif content_format == "markdown" or config.strategy == "heading":
        sections = sections_from_markdown(text)
    else:
        sections = [Section([], clean_whitespace(text))]

    if config.strategy == "whole":
        joined = "\n\n".join(s.text for s in sections)
        sections = [Section([], joined[: config.max_chars * 4])]
    elif config.strategy == "paragraph":
        sections = [Section([], "\n\n".join(s.text for s in sections))] if not pages else sections

    drafts: list[ChunkDraft] = []
    for section in sections:
        for piece in _split(section.text, config.max_chars, config.overlap_chars):
            if len(piece) < config.min_chars and drafts:
                drafts[-1].text = f"{drafts[-1].text}\n\n{piece}"
                continue
            heading = " › ".join(section.heading_path)
            prefix = f"{title}" + (f" — {heading}" if heading else "")
            drafts.append(
                ChunkDraft(
                    position=len(drafts),
                    text=piece,
                    title=prefix[:500],
                    heading_path=section.heading_path,
                    page=section.page,
                    anchor=section.anchor,
                )
            )
    if not drafts and text.strip():
        drafts.append(
            ChunkDraft(
                position=0, text=clean_whitespace(text)[: config.max_chars], title=title[:500]
            )
        )
    return drafts
