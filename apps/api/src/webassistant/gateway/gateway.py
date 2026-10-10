"""Gateway de modelos: registro de proveedores, resiliencia y registro de uso."""

from __future__ import annotations

import time
from collections.abc import AsyncIterator, Callable

from webassistant.config import Settings
from webassistant.errors import AppError, ErrorCode
from webassistant.gateway.base import (
    CancelToken,
    ChatRequest,
    ChatResult,
    EmbeddingResult,
    ModelProvider,
    ProviderError,
    StreamDelta,
)
from webassistant.gateway.openai_provider import OpenAIProvider
from webassistant.gateway.resilience import Resilience, RetryPolicy
from webassistant.gateway.simulated import SIMULATED_EMBEDDING_MODEL, SimulatedProvider
from webassistant.observability import metrics
from webassistant.observability.tracing import traced
from webassistant.usage.recorder import UsageRecorder, UsageScope, UsageSummary


class ModelGateway:
    def __init__(
        self,
        providers: dict[str, ModelProvider],
        resilience: Resilience,
        recorder: UsageRecorder | None,
    ) -> None:
        self.providers = providers
        self.resilience = resilience
        self.recorder = recorder

    def provider(self, name: str) -> ModelProvider:
        provider = self.providers.get(name)
        if provider is None:
            raise AppError(ErrorCode.PROVIDER_UNAVAILABLE, f"Proveedor no configurado: {name}")
        return provider

    def _error_hook(
        self,
        scope: UsageScope | None,
        provider: str,
        model: str,
        operation: str,
        summary: UsageSummary | None,
    ) -> Callable[[ProviderError, int], object]:
        async def hook(exc: ProviderError, attempt: int) -> None:
            breaker = self.resilience.breaker(f"{provider}:{model}")
            metrics.CIRCUIT_OPEN.labels(f"{provider}:{model}").set(
                1 if breaker.state == "open" else 0
            )
            if scope is not None and self.recorder is not None:
                await self.recorder.record_error(
                    scope,
                    provider=provider,
                    model=model,
                    operation=operation,
                    error_code=exc.code.value,
                    summary=summary,
                )

        return hook

    @traced(
        "model.chat",
        lambda self, provider_name, request, **_: {
            "provider": provider_name,
            "model": request.model,
        },
    )
    async def chat(
        self,
        provider_name: str,
        request: ChatRequest,
        *,
        scope: UsageScope | None = None,
        summary: UsageSummary | None = None,
        cancel: CancelToken | None = None,
        system_prompt: str | None = None,
    ) -> ChatResult:
        provider = self.provider(provider_name)
        result = await self.resilience.call(
            f"{provider_name}:{request.model}",
            lambda: provider.chat(request),
            cancel=cancel,
            on_error=self._error_hook(scope, provider_name, request.model, "chat", summary),  # type: ignore[arg-type]
        )
        if scope is not None and self.recorder is not None:
            await self.recorder.record_chat(
                scope, result, summary=summary, system_prompt=system_prompt
            )
        return result

    async def stream_chat(
        self,
        provider_name: str,
        request: ChatRequest,
        *,
        scope: UsageScope | None = None,
        summary: UsageSummary | None = None,
        cancel: CancelToken | None = None,
        system_prompt: str | None = None,
    ) -> AsyncIterator[StreamDelta | ChatResult]:
        """Streaming con cancelación. Sin reintentos una vez emitidos fragmentos."""
        provider = self.provider(provider_name)
        breaker = self.resilience.breaker(f"{provider_name}:{request.model}")
        if not breaker.allow():
            raise ProviderError(ErrorCode.PROVIDER_UNAVAILABLE, "Circuit breaker abierto")
        started = time.perf_counter()
        try:
            async for item in provider.stream_chat(request):
                if cancel is not None:
                    cancel.raise_if_cancelled()
                if isinstance(item, ChatResult):
                    breaker.record_success()
                    if scope is not None and self.recorder is not None:
                        await self.recorder.record_chat(
                            scope, item, summary=summary, system_prompt=system_prompt
                        )
                yield item
                if time.perf_counter() - started > self.resilience.policy.timeout:
                    raise ProviderError(
                        ErrorCode.PROVIDER_TIMEOUT, "Streaming excedió el tiempo máximo"
                    )
        except ProviderError as exc:
            breaker.record_failure()
            hook = self._error_hook(scope, provider_name, request.model, "chat", summary)
            await hook(exc, 0)  # type: ignore[misc]
            raise

    @traced(
        "model.embedding",
        lambda self, provider_name, model, texts, **_: {
            "provider": provider_name,
            "model": model,
            "inputs": len(texts),
        },
    )
    async def embed(
        self,
        provider_name: str,
        model: str,
        texts: list[str],
        *,
        scope: UsageScope | None = None,
        summary: UsageSummary | None = None,
    ) -> EmbeddingResult:
        provider = self.provider(provider_name)
        result = await self.resilience.call(
            f"{provider_name}:{model}",
            lambda: provider.embed(model, texts),
            on_error=self._error_hook(scope, provider_name, model, "embedding", summary),  # type: ignore[arg-type]
        )
        if scope is not None and self.recorder is not None:
            await self.recorder.record_embedding(scope, result, summary=summary)
        return result

    async def close(self) -> None:
        for provider in self.providers.values():
            await provider.close()


def build_providers(settings: Settings) -> dict[str, ModelProvider]:
    providers: dict[str, ModelProvider] = {"simulated": SimulatedProvider()}
    if settings.openai_api_key:
        providers["openai"] = OpenAIProvider(
            settings.openai_api_key.get_secret_value(),
            base_url=settings.openai_base_url,
            organization=settings.openai_organization,
            timeout=settings.provider_timeout_seconds,
        )
    return providers


def build_resilience(settings: Settings) -> Resilience:
    return Resilience(
        RetryPolicy(
            max_retries=settings.provider_max_retries, timeout=settings.provider_timeout_seconds
        ),
        failure_threshold=settings.circuit_breaker_failures,
        reset_seconds=settings.circuit_breaker_reset_seconds,
    )


def default_embedding(settings: Settings) -> tuple[str, str]:
    """Proveedor y modelo de embeddings por defecto según el proveedor configurado."""
    if settings.default_provider == "simulated":
        return "simulated", SIMULATED_EMBEDDING_MODEL
    return settings.default_provider, settings.default_embedding_model
