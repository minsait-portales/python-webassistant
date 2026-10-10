#!/usr/bin/env python3
"""Valida que el mensaje de commit siga Conventional Commits (gancho commit-msg)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

PATTERN = re.compile(
    r"^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)"
    r"(\([a-z0-9._/-]+\))?!?: \S.{0,99}$"
)


def main(path: str) -> int:
    first = (
        Path(path).read_text(encoding="utf-8").splitlines()[0] if Path(path).stat().st_size else ""
    )
    if first.startswith(("Merge ", "Revert ", "fixup! ", "squash! ")) or PATTERN.match(first):
        return 0
    print(
        "El mensaje debe seguir Conventional Commits, p. ej. 'feat(widget): anuncia el estado'.\n"
        f"Recibido: {first!r}",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1]))
