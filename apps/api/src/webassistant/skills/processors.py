"""Post-procesadores del núcleo declarables en el manifiesto de una skill.

Garantizan que lo que llega al renderer procede de fuentes reales del run:
los productos se resuelven contra los resultados RAG o la colección activa
(los identificadores inventados se descartan), las citas se verifican contra
el texto de los chunks y los enlaces apuntan al portal anfitrión.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation
from typing import Any

from webassistant.text import limit_text, normalize
from webassistant_skill_sdk import RetrievedSource, SkillContext

_SAFE_URL = re.compile(r"^(https?://|/)[^\s]*$")
_PRODUCT_META_KEYS = (
    "url",
    "image_url",
    "image_alt",
    "price",
    "price_amount",
    "currency",
    "category",
    "brand",
    "store_name",
    "availability",
    "rating",
    "badges",
)


@dataclass
class ProcessingReport:
    dropped_items: list[str] = field(default_factory=list)
    unverified_citations: int = 0
    used_sources: list[str] = field(default_factory=list)

    def use(self, source_id: str) -> None:
        if source_id and source_id not in self.used_sources:
            self.used_sources.append(source_id)

    def as_dict(self) -> dict[str, Any]:
        return {
            "dropped_items": self.dropped_items,
            "unverified_citations": self.unverified_citations,
            "used_sources": self.used_sources,
        }


def safe_url(value: Any) -> str | None:
    if isinstance(value, str) and _SAFE_URL.match(value.strip()) and len(value) <= 2048:
        return value.strip()
    return None


def _product_index(ctx: SkillContext) -> dict[str, RetrievedSource]:
    index: dict[str, RetrievedSource] = {}
    for source in ctx.sources:
        product_id = str(source.metadata.get("product_id") or source.source_id)
        index.setdefault(product_id, source)
        index.setdefault(source.source_id, source)
    return index


def _format_price(amount: Any, currency: str | None) -> str | None:
    try:
        value = Decimal(str(amount))
    except (InvalidOperation, ValueError):
        return None
    text = f"{value:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    symbol = {"EUR": "€", "USD": "$", "GBP": "£"}.get((currency or "").upper(), currency or "")
    return f"{text} {symbol}".strip()


def _resolve_item(
    item: dict[str, Any],
    index: dict[str, RetrievedSource],
    selection: dict[str, dict[str, Any]],
    report: ProcessingReport,
) -> dict[str, Any] | None:
    product_id = str(item.get("product_id") or "").strip()
    if not product_id:
        return None
    source = index.get(product_id)
    selected = selection.get(product_id)
    if source is None and selected is None:
        report.dropped_items.append(product_id)
        return None
    resolved: dict[str, Any] = {
        "product_id": product_id,
        "title": limit_text(
            str(
                item.get("title")
                or (source.title if source else (selected or {}).get("title", ""))
                or product_id
            ),
            300,
        ),
    }
    variation = item.get("variation_id") or (
        source.metadata.get("variation_id") if source else None
    )
    if variation is None and selected:
        variation = selected.get("variation_id")
    if variation not in (None, ""):
        resolved["variation_id"] = str(variation)
    if item.get("body"):
        resolved["body"] = limit_text(str(item["body"]), 4000)
    if item.get("quantity") is not None:
        try:
            resolved["quantity"] = max(1, min(999, int(item["quantity"])))
        except (TypeError, ValueError):
            resolved["quantity"] = 1
    if source is not None:
        report.use(source.source_id)
        metadata = source.metadata
        for key in _PRODUCT_META_KEYS:
            value = metadata.get(key)
            if value in (None, "", []):
                continue
            if key in {"url", "image_url"}:
                value = safe_url(value)
                if value is None:
                    continue
            resolved[key] = value
        if "url" not in resolved and source.url:
            url = safe_url(source.url)
            if url:
                resolved["url"] = url
        if "price" not in resolved and metadata.get("price_amount") is not None:
            formatted = _format_price(metadata.get("price_amount"), metadata.get("currency"))
            if formatted:
                resolved["price"] = formatted
        if "price_amount" in resolved:
            try:
                resolved["price_amount"] = float(resolved["price_amount"])
            except (TypeError, ValueError):
                resolved.pop("price_amount")
        if "rating" in resolved:
            try:
                resolved["rating"] = max(0.0, min(5.0, float(resolved["rating"])))
            except (TypeError, ValueError):
                resolved.pop("rating")
        if isinstance(resolved.get("badges"), list):
            resolved["badges"] = [str(b)[:40] for b in resolved["badges"][:5]]
        else:
            resolved.pop("badges", None)
        for key in (
            "price",
            "currency",
            "category",
            "brand",
            "store_name",
            "availability",
            "image_alt",
        ):
            if key in resolved:
                resolved[key] = str(resolved[key])[: 3 if key == "currency" else 300]
    return resolved


def catalog_items(
    ctx: SkillContext, result: dict[str, Any], report: ProcessingReport
) -> dict[str, Any]:
    index = _product_index(ctx)
    selection = {str(i["product_id"]): i for i in ctx.active_selection if i.get("product_id")}

    def resolve_list(items: Any) -> list[dict[str, Any]]:
        resolved: list[dict[str, Any]] = []
        seen: set[str] = set()
        for item in items if isinstance(items, list) else []:
            if not isinstance(item, dict):
                continue
            value = _resolve_item(item, index, selection, report)
            if value and value["product_id"] not in seen:
                seen.add(value["product_id"])
                resolved.append(value)
        return resolved

    output = dict(result)
    if "items" in output:
        output["items"] = resolve_list(output.get("items"))
    if isinstance(output.get("categories"), list):
        categories = []
        for category in output["categories"]:
            if isinstance(category, dict):
                items = resolve_list(category.get("items"))
                if items:
                    categories.append({**category, "items": items})
        output["categories"] = categories
    if isinstance(output.get("rows"), list):
        valid_ids = {i["product_id"] for i in output.get("items", [])}
        rows = []
        for row in output["rows"]:
            if not isinstance(row, dict):
                continue
            pid = str(row.get("product_id") or "")
            if pid and pid not in valid_ids and pid not in index:
                report.dropped_items.append(pid)
                continue
            rows.append(
                {
                    **row,
                    "product_id": pid or None,
                    "variation_id": str(row["variation_id"]) if row.get("variation_id") else None,
                }
            )
        output["rows"] = rows
    if isinstance(output.get("points"), list):
        output["points"] = [
            {"title": str(p.get("title", ""))[:300], "body": str(p.get("body", ""))[:4000]}
            for p in output["points"]
            if isinstance(p, dict) and p.get("title")
        ]
    return output


def content_sources(
    ctx: SkillContext, result: dict[str, Any], report: ProcessingReport
) -> dict[str, Any]:
    """Convierte los títulos citados por el modelo en enlaces verificados a fuentes reales."""
    output = dict(result)
    by_title = {normalize(s.title): s for s in ctx.sources}
    links: list[dict[str, Any]] = []
    seen: set[str] = set()
    for entry in result.get("sources") or []:
        title = entry.get("title", "") if isinstance(entry, dict) else entry
        source = by_title.get(normalize(str(title)))
        if source is None:
            # Coincidencia parcial tolerante (el modelo puede acortar el título).
            normalized = normalize(str(title))
            source = next(
                (
                    s
                    for key, s in by_title.items()
                    if normalized and (normalized in key or key in normalized)
                ),
                None,
            )
        if source is None or source.source_id in seen:
            continue
        seen.add(source.source_id)
        report.use(source.source_id)
        link: dict[str, Any] = {"title": source.title[:300], "source_id": source.source_id}
        url = safe_url(source.url)
        if url:
            link["url"] = url
        links.append(link)
    if not links and result.get("answer") and ctx.sources:
        top = ctx.sources[0]
        report.use(top.source_id)
        link = {"title": top.title[:300], "source_id": top.source_id}
        if safe_url(top.url):
            link["url"] = top.url
        links.append(link)
    output["sources"] = links[:10]
    output.setdefault("found", bool(links))
    output.pop("query", None)
    return output


def _quote_matches(quote: str, text: str) -> bool:
    q = normalize(quote)
    t = normalize(text)
    if not q:
        return False
    if q in t:
        return True
    words = q.split()
    if len(words) < 4:
        return False
    matched = sum(1 for w in words if w in t.split())
    return matched / len(words) >= 0.8


def document_citations(
    ctx: SkillContext, result: dict[str, Any], report: ProcessingReport
) -> dict[str, Any]:
    """Verifica cada cita contra los chunks recuperados y enlaza al documento del portal."""
    output = dict(result)
    citations = []
    documents: dict[str, dict[str, Any]] = {}
    for citation in result.get("citations") or []:
        if not isinstance(citation, dict):
            continue
        quote = str(citation.get("quote", ""))
        candidates = [
            s
            for s in ctx.sources
            if not citation.get("source_id") or s.source_id == citation.get("source_id")
        ]
        candidates = candidates or list(ctx.sources)
        match = next((s for s in candidates if _quote_matches(quote, s.text)), None)
        if match is None:
            report.unverified_citations += 1
            continue
        report.use(match.source_id)
        entry: dict[str, Any] = {
            "document_title": match.title[:300],
            "quote": limit_text(quote, 1000),
            "source_id": match.source_id,
            "verified": True,
        }
        if citation.get("location"):
            entry["location"] = str(citation["location"])[:300]
        page = match.page or citation.get("page")
        if isinstance(page, int) and page > 0:
            entry["page"] = page
        url = safe_url(match.url)
        if url:
            entry["document_url"] = url
            documents.setdefault(
                match.source_id,
                {"title": match.title[:300], "url": url, "source_id": match.source_id},
            )
        else:
            documents.setdefault(
                match.source_id, {"title": match.title[:300], "source_id": match.source_id}
            )
        citations.append(entry)
    output["citations"] = citations[:10]
    output["documents"] = list(documents.values())[:10]
    if not citations:
        output["answer"] = (
            output.get("answer")
            or "No he encontrado una cita verificable en los documentos disponibles."
        )
    return output


def source_citations(
    ctx: SkillContext, result: dict[str, Any], report: ProcessingReport
) -> dict[str, Any]:
    for source in ctx.sources[:3]:
        report.use(source.source_id)
    return result


def event_totals(
    ctx: SkillContext, result: dict[str, Any], report: ProcessingReport
) -> dict[str, Any]:
    output = dict(result)
    total = Decimal(0)
    count = 0
    currency = None
    for category in output.get("categories") or []:
        for item in category.get("items") or []:
            quantity = int(item.get("quantity") or 1)
            count += quantity
            if item.get("price_amount") is not None:
                total += Decimal(str(item["price_amount"])) * quantity
                currency = currency or item.get("currency")
    output["total_products"] = count
    if total:
        output["total_amount"] = _format_price(total, currency) or ""
    return output


def clarification_options(
    ctx: SkillContext, result: dict[str, Any], report: ProcessingReport
) -> dict[str, Any]:
    output = dict(result)
    if not output.get("question"):
        output["question"] = str(output.get("description") or "¿Puedes darme algún detalle más?")
    output.pop("description", None)
    output["options"] = [str(o)[:120] for o in (output.get("options") or []) if str(o).strip()][:6]
    return output


PROCESSORS = {
    "catalog_items": catalog_items,
    "content_sources": content_sources,
    "document_citations": document_citations,
    "source_citations": source_citations,
    "event_totals": event_totals,
    "clarification_options": clarification_options,
}
