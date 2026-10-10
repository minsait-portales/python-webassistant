"""Perfiles de modelo: qué parámetros acepta cada familia (CG-04.2).

Se envían solo los parámetros soportados; los heredados incompatibles se
descartan en lugar de provocar errores del proveedor.
"""

from __future__ import annotations

from webassistant.gateway.base import ModelCapabilities

_REASONING = ModelCapabilities(
    temperature=False,
    reasoning_effort=True,
    allowed_params=frozenset({"reasoning_effort", "verbosity", "seed", "service_tier"}),
    context_window=400_000,
)

_CLASSIC = ModelCapabilities(
    temperature=True,
    reasoning_effort=False,
    allowed_params=frozenset(
        {"temperature", "top_p", "presence_penalty", "frequency_penalty", "seed"}
    ),
    context_window=128_000,
)

_LUNA = ModelCapabilities(
    temperature=False,
    reasoning_effort=True,
    tool_reasoning_effort="none",
    allowed_params=frozenset({"reasoning_effort", "verbosity", "seed"}),
    context_window=400_000,
)

_PREFIX_PROFILES: list[tuple[str, ModelCapabilities]] = [
    ("gpt-6-luna", _LUNA),
    ("gpt-6", _REASONING),
    ("gpt-5", _REASONING),
    ("o1", _REASONING),
    ("o3", _REASONING),
    ("o4", _REASONING),
    ("gpt-4.1", _CLASSIC),
    ("gpt-4o", _CLASSIC),
    ("gpt-4", _CLASSIC),
    ("gpt-3.5", _CLASSIC),
]


def capabilities_for(model: str) -> ModelCapabilities:
    for prefix, profile in _PREFIX_PROFILES:
        if model.startswith(prefix):
            return profile
    return _CLASSIC


def filter_params(model: str, params: dict[str, object], *, has_tools: bool) -> dict[str, object]:
    profile = capabilities_for(model)
    filtered = {k: v for k, v in params.items() if k in profile.allowed_params}
    if not profile.temperature:
        filtered.pop("temperature", None)
        filtered.pop("top_p", None)
    if profile.reasoning_effort and has_tools and profile.tool_reasoning_effort is not None:
        filtered["reasoning_effort"] = profile.tool_reasoning_effort
    return filtered
