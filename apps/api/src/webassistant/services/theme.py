"""Validación de tokens de tema (CG-10.7, CG-14.5).

Solo tokens limitados y validados (sin CSS arbitrario). Se comprueba el
contraste WCAG 2.1 AA (4,5:1 para texto normal) entre los pares que el widget
usa para texto, y 3:1 para componentes de interfaz.
"""

from __future__ import annotations

from typing import Any

from webassistant.contracts import validate
from webassistant.errors import validation

TEXT_PAIRS = [
    ("text", "surface", 4.5),
    ("text", "muted_surface", 4.5),
    ("on_accent", "accent", 4.5),
    ("accent", "surface", 3.0),
]


def _channel(value: int) -> float:
    c = value / 255
    return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4


def luminance(hex_color: str) -> float:
    hex_color = hex_color.lstrip("#")
    r, g, b = (int(hex_color[i : i + 2], 16) for i in (0, 2, 4))
    return 0.2126 * _channel(r) + 0.7152 * _channel(g) + 0.0722 * _channel(b)


def contrast_ratio(a: str, b: str) -> float:
    la, lb = luminance(a), luminance(b)
    lighter, darker = max(la, lb), min(la, lb)
    return (lighter + 0.05) / (darker + 0.05)


def validate_theme(theme: dict[str, Any], defaults: dict[str, Any]) -> dict[str, Any]:
    errors = validate("theme.v1.schema.json", theme)
    if errors:
        raise validation("Tema no válido", errors)
    merged = {**defaults, **theme}
    problems = []
    for foreground, background, minimum in TEXT_PAIRS:
        fg, bg = merged.get(foreground), merged.get(background)
        if isinstance(fg, str) and isinstance(bg, str):
            ratio = contrast_ratio(fg, bg)
            if ratio < minimum:
                problems.append(
                    {
                        "path": f"/{foreground}",
                        "message": f"Contraste {ratio:.2f}:1 entre {foreground} y {background}; mínimo {minimum}:1 (WCAG AA)",
                    }
                )
    if problems:
        raise validation("El tema no cumple el contraste mínimo WCAG 2.1 AA", problems)
    return theme
