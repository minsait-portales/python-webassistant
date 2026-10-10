"""Interfaz común de proveedores de modelos (CG-04.1).

Operaciones: chat (con tools), salida estructurada JSON, streaming,
embeddings, uso normalizado y errores normalizados. Un proveedor simulado
puede sustituir al real en pruebas y en la demo autónoma.
"""

from __future__ import annotations

import asyncio
from abc import ABC, abstractmethod
from collections.abc import AsyncIterator
from dataclasses import dataclass, field
from typing import Any, Literal

from webassistant.errors import AppError, ErrorCode

Role = Literal["system", "user", "assistant", "tool"]


@dataclass
class ToolCallRequest:
    id: str
    name: str
    arguments: dict[str, Any]
    raw_arguments: str = ""


@dataclass
class ChatMessage:
    role: Role
    content: str | None = None
    tool_calls: list[ToolCallRequest] = field(default_factory=list)
    tool_call_id: str | None = None
    name: str | None = None


@dataclass
class ToolSpec:
    name: str
    description: str
    parameters: dict[str, Any]


@dataclass
class ChatRequest:
    model: str
    messages: list[ChatMessage]
    tools: list[ToolSpec] = field(default_factory=list)
    tool_choice: str | dict[str, Any] = "auto"
    response_format: Literal["text", "json"] = "json"
    max_output_tokens: int | None = None
    params: dict[str, Any] = field(default_factory=dict)
    prompt_cache_key: str | None = None
    user_hash: str | None = None
    # Pistas estructuradas que solo usa el proveedor simulado (los reales las ignoran).
    hints: dict[str, Any] = field(default_factory=dict)


@dataclass
class TokenUsage:
    input: int = 0
    cached: int = 0
    output: int = 0
    reasoning: int = 0

    @property
    def total(self) -> int:
        return self.input + self.output

    def add(self, other: TokenUsage) -> None:
        self.input += other.input
        self.cached += other.cached
        self.output += other.output
        self.reasoning += other.reasoning


@dataclass
class ChatResult:
    content: str | None
    tool_calls: list[ToolCallRequest]
    finish_reason: str
    usage: TokenUsage
    model: str
    provider: str
    provider_request_id: str | None = None
    latency_ms: int = 0
    raw_request: dict[str, Any] = field(default_factory=dict)
    raw_response: dict[str, Any] = field(default_factory=dict)


@dataclass
class StreamDelta:
    """Fragmento incremental de texto durante el streaming."""

    text: str


@dataclass
class EmbeddingResult:
    vectors: list[list[float]]
    usage: TokenUsage
    model: str
    provider: str
    dimension: int
    latency_ms: int = 0


@dataclass(frozen=True)
class ModelCapabilities:
    tools: bool = True
    json_mode: bool = True
    streaming: bool = True
    temperature: bool = True
    reasoning_effort: bool = False
    # Valor obligatorio de reasoning_effort cuando hay tools (gpt-6-luna en Chat Completions).
    tool_reasoning_effort: str | None = None
    max_tokens_param: str = "max_completion_tokens"
    allowed_params: frozenset[str] = frozenset()
    context_window: int = 128_000


class ProviderError(AppError):
    """Error normalizado de proveedor."""


class CancelToken:
    """Señal de cancelación cooperativa compartida entre API, orquestador y proveedor."""

    def __init__(self) -> None:
        self._event = asyncio.Event()

    def cancel(self) -> None:
        self._event.set()

    @property
    def cancelled(self) -> bool:
        return self._event.is_set()

    def raise_if_cancelled(self) -> None:
        if self._event.is_set():
            raise AppError(ErrorCode.RUN_CANCELLED, "Run cancelado")

    async def wait(self) -> None:
        await self._event.wait()


class ModelProvider(ABC):
    name: str = "base"

    @abstractmethod
    def capabilities(self, model: str) -> ModelCapabilities: ...

    @abstractmethod
    async def chat(self, request: ChatRequest) -> ChatResult: ...

    async def stream_chat(self, request: ChatRequest) -> AsyncIterator[StreamDelta | ChatResult]:
        """Streaming por defecto: una sola respuesta completa."""
        result = await self.chat(request)
        if result.content:
            yield StreamDelta(result.content)
        yield result

    @abstractmethod
    async def embed(self, model: str, texts: list[str]) -> EmbeddingResult: ...

    def embedding_dimension(self, model: str) -> int:
        return 1536

    async def health(self) -> bool:
        return True

    async def close(self) -> None:  # noqa: B027
        pass


def approx_tokens(text: str | None) -> int:
    if not text:
        return 0
    return max(1, len(text) // 4)
