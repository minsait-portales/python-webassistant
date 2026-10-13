#!/usr/bin/env python3
"""Cadenas traducibles del conector Drupal y su traducción al español.

    python3 integraciones/drupal/scripts/i18n.py check     # faltan, sobran o placeholders distintos
    python3 integraciones/drupal/scripts/i18n.py pot       # plantilla .pot por la salida estándar

Extrae las cadenas de t(), TranslatableMarkup, formatPlural, Drupal.t y Drupal.formatPlural (PHP y
JavaScript, sin las pruebas) y los títulos y descripciones de los YAML de rutas, enlaces y permisos.
La traducción vive en webassistant_connector/translations/es.po (patrón declarado en los .info.yml).
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

MODULE = Path(__file__).resolve().parents[1] / "webassistant_connector"
PO_FILE = MODULE / "translations" / "es.po"

STRING = r"""(['"])((?:\\.|(?!\1).)*)\1"""
CALL = re.compile(r"""(?<![\w$])(?:t|->t|TranslatableMarkup|Drupal\.t)\(\s*""" + STRING, re.S)
PLURAL = re.compile(
    r"""(?:formatPlural|PluralTranslatableMarkup|Drupal\.formatPlural)\(\s*[^,]+,\s*"""
    + STRING
    + r"""\s*,\s*"""
    + STRING,
    re.S,
)
YAML = re.compile(
    r"""^\s*(?:title|description|_title|admin_label|label):\s*"""
    r"""(?:'((?:''|[^'])*)'|"([^"]*)"|([^'"\n#][^\n#]*?))\s*$""",
    re.M,
)
PLACEHOLDER = re.compile(r"[@%:][a-z_]+")


ESCAPES = {"n": "\n", "t": "\t", '"': '"', "\\": "\\", "$": "$"}


def unquote(quote: str, value: str) -> str:
    """Valor en tiempo de ejecución de un literal PHP o JavaScript sin sus comillas."""
    if quote == "'":
        return re.sub(r"\\([\\'])", r"\1", value)
    # Entre comillas dobles: \n, \t, \", \\ y \$ (PHP); cualquier otra barra se conserva.
    return re.sub(r"\\(.)", lambda m: ESCAPES.get(m.group(1), m.group(0)), value, flags=re.S)


def extract() -> tuple[set[str], set[tuple[str, str]]]:
    singles: set[str] = set()
    plurals: set[tuple[str, str]] = set()
    for path in sorted(MODULE.rglob("*")):
        if "tests" in path.parts or not path.is_file():
            continue
        if path.suffix in {".php", ".module", ".install", ".js"}:
            text = path.read_text(encoding="utf-8")
            singles.update(unquote(m.group(1), m.group(2)) for m in CALL.finditer(text))
            plurals.update(
                (unquote(m.group(1), m.group(2)), unquote(m.group(3), m.group(4)))
                for m in PLURAL.finditer(text)
            )
        elif (
            path.suffix == ".yml"
            and "config" not in path.parts
            and not path.name.endswith((".info.yml", ".services.yml"))
        ):
            for m in YAML.finditer(path.read_text(encoding="utf-8")):
                if m.group(1) is not None:
                    value = m.group(1).replace("''", "'")
                else:
                    value = m.group(2) if m.group(2) is not None else m.group(3)
                if value and value.strip():
                    singles.add(value.strip())
    singles.discard("")
    return singles, plurals


def po_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def po_unescape(value: str) -> str:
    return value.replace('\\"', '"').replace("\\n", "\n").replace("\\\\", "\\")


def read_po(path: Path) -> tuple[dict[str, str], dict[str, tuple[str, list[str]]]]:
    """Devuelve las traducciones simples y las plurales (msgid → (msgid_plural, formas))."""
    singles: dict[str, str] = {}
    plurals: dict[str, tuple[str, list[str]]] = {}
    entry: dict[str, object] = {}
    current: str | None = None

    def flush() -> None:
        msgid = entry.get("msgid")
        if isinstance(msgid, str) and msgid:
            if "msgid_plural" in entry:
                forms = [str(entry.get(f"msgstr[{i}]", "")) for i in range(2)]
                plurals[msgid] = (str(entry["msgid_plural"]), forms)
            else:
                singles[msgid] = str(entry.get("msgstr", ""))

    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line:
            flush()
            entry, current = {}, None
        elif line.startswith("#"):
            continue
        elif line.startswith('"'):
            if current is not None:
                entry[current] = str(entry[current]) + po_unescape(line[1:-1])
        else:
            key, _, value = line.partition(" ")
            current = key
            entry[key] = po_unescape(value.strip()[1:-1])
    flush()
    return singles, plurals


def placeholders(text: str) -> set[str]:
    return set(PLACEHOLDER.findall(text))


def check() -> int:
    singles, plurals = extract()
    translated, translated_plurals = read_po(PO_FILE)
    problems: list[str] = []
    for msgid in sorted(singles):
        msgstr = translated.get(msgid)
        if not msgstr:
            problems.append(f"sin traducir: {msgid!r}")
        elif placeholders(msgid) != placeholders(msgstr):
            problems.append(f"placeholders distintos: {msgid!r} → {msgstr!r}")
    for singular, plural in sorted(plurals):
        entry = translated_plurals.get(singular)
        if not entry or not all(entry[1]):
            problems.append(f"plural sin traducir: {singular!r}")
        elif placeholders(plural) - {"@count"} != placeholders(entry[1][1]) - {"@count"}:
            problems.append(f"placeholders distintos (plural): {plural!r}")
    obsolete = set(translated) - singles
    obsolete_plurals = set(translated_plurals) - {s for s, _ in plurals}
    problems += [f"obsoleta: {msgid!r}" for msgid in sorted(obsolete | obsolete_plurals)]
    for problem in problems:
        print(problem)
    print(f"{len(singles)} cadenas y {len(plurals)} plurales; {len(problems)} problemas.")
    return 1 if problems else 0


def pot() -> int:
    singles, plurals = extract()
    out = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: webassistant_connector\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=utf-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
        "",
    ]
    for msgid in sorted(singles):
        out += [f'msgid "{po_escape(msgid)}"', 'msgstr ""', ""]
    for singular, plural in sorted(plurals):
        out += [
            f'msgid "{po_escape(singular)}"',
            f'msgid_plural "{po_escape(plural)}"',
            'msgstr[0] ""',
            'msgstr[1] ""',
            "",
        ]
    sys.stdout.write("\n".join(out))
    return 0


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else "check"
    raise SystemExit({"check": check, "pot": pot}.get(command, check)())
