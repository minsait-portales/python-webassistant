#!/usr/bin/env python3
"""Genera los activos estáticos de la web de demostración (CG-16.3) desde las semillas.

    python scripts/generate_demo_site.py

Produce ``demo/catalog.json`` (catálogo de productos), ``demo/guides.json`` (guías de
ayuda), ``demo/img/*.svg`` (imágenes por categoría) y ``demo/documentos/*.pdf`` (PDFs
originales cuyo texto se indexa). Las semillas son la fuente de verdad: un test
comprueba que los ficheros generados están sincronizados.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SEEDS = ROOT / "apps" / "api" / "src" / "webassistant" / "seeds" / "demo_documents.json"
DEMO = ROOT / "demo"
BASE = "http://localhost:8000"

CATEGORY_STYLE = {
    "alternativas": ("#2f6b3a", "Veggie"),
    "barbacoas": ("#480e2a", "BBQ"),
    "bebidas": ("#1d5c8c", "Bebidas"),
    "carnes": ("#8c2d1d", "Carnes"),
    "electronica": ("#30305e", "Sport tech"),
    "encendido": ("#a34a00", "Fuego"),
    "guarniciones": ("#5c6b1d", "Guarnición"),
    "menaje": ("#5e4a30", "Menaje"),
    "moviles": ("#1d4f5c", "Móviles"),
    "portatiles": ("#3d3d3d", "Portátiles"),
    "running": ("#a3003d", "Running"),
}


def relative(url: str) -> str:
    return url[len(BASE) :] if url.startswith(BASE) else url


def svg(color: str, label: str) -> str:
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200" role="img" '
        f'aria-label="{label}">\n'
        f'  <rect width="320" height="200" rx="16" fill="{color}"/>\n'
        '  <circle cx="160" cy="82" r="44" fill="#ffffff" opacity=".18"/>\n'
        '  <circle cx="160" cy="82" r="26" fill="#ffffff" opacity=".35"/>\n'
        '  <text x="160" y="164" fill="#ffffff" font-family="Arial, sans-serif" font-size="26" '
        f'font-weight="700" text-anchor="middle">{label}</text>\n'
        "</svg>\n"
    )


def pdf(title: str, text: str) -> bytes:
    """PDF mínimo de una página (Helvetica, WinAnsi) con el texto del documento."""

    def esc(value: str) -> str:
        return value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")

    lines: list[tuple[int, str]] = [(18, title), (11, "")]
    for paragraph in text.split("\n"):
        words, current = paragraph.split(), ""
        for word in words:
            if len(current) + len(word) + 1 > 88:
                lines.append((11, current))
                current = word
            else:
                current = f"{current} {word}".strip()
        lines.append((11, current))
    ops = ["BT", "/F1 11 Tf", "14 TL", "56 790 Td"]
    for size, line in lines:
        ops.append(f"/F1 {size} Tf ({esc(line)}) Tj T*")
    ops.append("ET")
    stream = "\n".join(ops).encode("cp1252")
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
        b"/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
        b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"\nendstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
    ]
    out = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += f"{number} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode()
    for offset in offsets:
        out += f"{offset:010d} 00000 n \n".encode()
    out += (
        f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    )
    return bytes(out)


def build() -> dict[Path, bytes]:
    documents = json.loads(SEEDS.read_text(encoding="utf-8"))
    products, guides, files = [], [], {}
    for doc in documents:
        meta = doc.get("metadata") or {}
        if doc["type"] == "product":
            products.append(
                {
                    "id": meta["product_id"],
                    "variation_id": meta.get("variation_id"),
                    "title": doc["title"],
                    "description": doc["text"].split(". ", 1)[-1],
                    "price": meta.get("price_amount"),
                    "currency": meta.get("currency", "EUR"),
                    "category": meta.get("category"),
                    "brand": meta.get("brand"),
                    "availability": meta.get("availability"),
                    "image": meta.get("image_url"),
                    "url": relative(doc["canonical_url"]),
                }
            )
        elif doc["type"] == "guide":
            sections = []
            for block in doc["text"].split("# ")[1:]:
                heading, _, body = block.partition("\n")
                sections.append({"heading": heading.strip(), "body": body.strip()})
            guides.append({"id": doc["source_id"], "title": doc["title"], "sections": sections})
        elif doc["type"] == "document":
            files[DEMO / "documentos" / f"{doc['source_id']}.pdf"] = pdf(doc["title"], doc["text"])
    files[DEMO / "catalog.json"] = (
        json.dumps(products, ensure_ascii=False, indent=2) + "\n"
    ).encode()
    files[DEMO / "guides.json"] = (json.dumps(guides, ensure_ascii=False, indent=2) + "\n").encode()
    for name, (color, label) in CATEGORY_STYLE.items():
        files[DEMO / "img" / f"{name}.svg"] = svg(color, label).encode()
    return files


def main() -> None:
    for path, content in build().items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        print(f"escrito {path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
