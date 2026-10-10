"""Skills incluidas con el producto, migradas desde el módulo Drupal (CG-06.6).

Cada subdirectorio es un paquete portable con ``skill.yaml``, esquemas y
ejemplo. Se registran mediante el entry point ``webassistant.skills``.
"""

from __future__ import annotations

from pathlib import Path

BUILTIN_DIR = Path(__file__).resolve().parent


def skill_paths() -> list[Path]:
    return sorted(p for p in BUILTIN_DIR.iterdir() if p.is_dir() and (p / "skill.yaml").is_file())
