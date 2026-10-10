"""CLI del SDK: ``webassistant-skill new <nombre>`` y ``webassistant-skill validate <ruta>``."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

from webassistant_skill_sdk.testing import inspect_skill_package

MANIFEST_TEMPLATE = """# Skill {name}: creada con webassistant-skill-sdk.
api_version: webassistant.ai/v1
name: {name}
version: 0.1.0
title: {title}
group: custom
description: Describe en una frase qué resuelve la skill.
intent: Usar cuando el usuario pida ...
when_to_use:
  - Caso de uso principal.
examples:
  - Frase de ejemplo del usuario
keywords: []
requires_rag: true
permissions: []
input_schema: schemas/input.json
response_example: examples/response.json
renderer: answer@1
processors: [content_sources]
chat_reply_fallback: He preparado la respuesta en el panel.
dependencies:
  platform: ">=1.0,<2.0"
"""

INPUT_SCHEMA = {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": False,
    "required": ["answer"],
    "properties": {
        "answer": {"type": "string", "maxLength": 4000},
        "steps": {
            "type": "array",
            "maxItems": 20,
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["title"],
                "properties": {
                    "title": {"type": "string", "maxLength": 300},
                    "body": {"type": "string", "maxLength": 4000},
                },
            },
        },
        "outro": {"type": "string", "maxLength": 4000},
        "sources": {"type": "array", "items": {"type": "string", "maxLength": 300}, "maxItems": 10},
    },
}

EXAMPLE = {
    "chat_reply": "Respuesta breve para el chat.",
    "result": {
        "answer": "Respuesta basada en las fuentes recuperadas.",
        "steps": [],
        "outro": "",
        "sources": ["Título exacto de la fuente"],
    },
}

HANDLER_TEMPLATE = '''"""Handler opcional de la skill {name}."""

from webassistant_skill_sdk import SkillContext, SkillHandler


class Handler(SkillHandler):
    def build_chat_reply(self, ctx: SkillContext, result: dict, chat_reply: str) -> str:
        return chat_reply
'''


def scaffold(name: str, target: Path) -> Path:
    if not re.match(r"^[a-z][a-z0-9_]{1,62}$", name):
        raise SystemExit("El nombre debe ser snake_case (a-z, 0-9, _)")
    root = target / name
    if root.exists():
        raise SystemExit(f"Ya existe {root}")
    (root / "schemas").mkdir(parents=True)
    (root / "examples").mkdir()
    (root / "skill.yaml").write_text(
        MANIFEST_TEMPLATE.format(name=name, title=name.replace("_", " ").capitalize()),
        encoding="utf-8",
    )
    (root / "schemas" / "input.json").write_text(
        json.dumps(INPUT_SCHEMA, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    (root / "examples" / "response.json").write_text(
        json.dumps(EXAMPLE, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    (root / "handler.py.example").write_text(HANDLER_TEMPLATE.format(name=name), encoding="utf-8")
    return root


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="webassistant-skill")
    sub = parser.add_subparsers(dest="command", required=True)
    new = sub.add_parser("new", help="Crear un paquete de skill desde la plantilla")
    new.add_argument("name")
    new.add_argument("--dir", default=".")
    validate = sub.add_parser("validate", help="Validar uno o varios paquetes de skill")
    validate.add_argument("paths", nargs="+")
    args = parser.parse_args(argv)
    if args.command == "new":
        path = scaffold(args.name, Path(args.dir))
        print(f"Skill creada en {path}")
        return 0
    status = 0
    for raw in args.paths:
        path = Path(raw)
        if not path.is_dir() or path.name.startswith(("_", ".")):
            continue
        errors, warnings = inspect_skill_package(path)
        print(f"{'✗' if errors else '✓'} {raw}")
        for problem in errors:
            print(f"  - error: {problem}")
        for warning in warnings:
            print(f"  - aviso: {warning}")
        if errors:
            status = 1
    return status


if __name__ == "__main__":
    sys.exit(main())
