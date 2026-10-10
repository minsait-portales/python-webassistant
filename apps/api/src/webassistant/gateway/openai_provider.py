"""Proveedor OpenAI (Chat Completions + Embeddings) con soporte de ``gpt-6-luna`` (CG-04.2)."""

from __future__ import annotations

import json
import time
from collections.abc import AsyncIterator
from typing import Any

import openai
from openai import AsyncOpenAI

from webassistant.errors import ErrorCode
from webassistant.gateway.base import (
    ChatMessage,
    ChatRequest,
    ChatResult,
    EmbeddingResult,
    ModelCapabilities,
    ModelProvider,
    ProviderError,
    StreamDelta,
    TokenUsage,
    ToolCallRequest,
)
from webassistant.gateway.profiles import capabilities_for, filter_params

_EMBEDDING_DIMENSIONS = {
    "text-embedding-3-small": 1536,
    "text-embedding-3-large": 3072,
    "text-embedding-ada-002": 1536,
}


def normalize_openai_error(exc: Exception) -> ProviderError:
    detail = f"{type(exc).__name__}: {str(exc)[:300]}"
    if isinstance(exc, openai.APITimeoutError):
        return ProviderError(ErrorCode.PROVIDER_TIMEOUT, detail)
    if isinstance(exc, openai.RateLimitError):
        body = getattr(exc, "body", None) or {}
        code = body.get("code") if isinstance(body, dict) else None
        if code == "insufficient_quota" or "quota" in str(exc).lower():
            return ProviderError(ErrorCode.PROVIDER_QUOTA, detail)
        return ProviderError(ErrorCode.PROVIDER_RATE_LIMITED, detail)
    if isinstance(exc, openai.BadRequestError | openai.UnprocessableEntityError):
        return ProviderError(ErrorCode.PROVIDER_BAD_REQUEST, detail)
    if isinstance(
        exc, openai.AuthenticationError | openai.PermissionDeniedError | openai.NotFoundError
    ):
        return ProviderError(
            ErrorCode.PROVIDER_UNAVAILABLE, "Configuración del proveedor inválida: " + detail
        )
    if isinstance(
        exc, openai.APIConnectionError | openai.InternalServerError | openai.APIStatusError
    ):
        return ProviderError(ErrorCode.PROVIDER_UNAVAILABLE, detail)
    return ProviderError(ErrorCode.PROVIDER_UNAVAILABLE, detail)


def _message_to_openai(message: ChatMessage) -> dict[str, Any]:
    data: dict[str, Any] = {"role": message.role}
    if message.role == "tool":
        data["tool_call_id"] = message.tool_call_id
        data["content"] = message.content or ""
        return data
    data["content"] = message.content or ("" if not message.tool_calls else None)
    if message.tool_calls:
        data["tool_calls"] = [
            {
                "id": call.id,
                "type": "function",
                "function": {
                    "name": call.name,
                    "arguments": call.raw_arguments or json.dumps(call.arguments),
                },
            }
            for call in message.tool_calls
        ]
    return data


def _parse_arguments(raw: str) -> dict[str, Any]:
    try:
        parsed = json.loads(raw or "{}")
    except json.JSONDecodeError:
        return {"__invalid_json__": raw[:500]}
    return parsed if isinstance(parsed, dict) else {"value": parsed}


def _usage(raw: Any) -> TokenUsage:
    if raw is None:
        return TokenUsage()
    prompt_details = getattr(raw, "prompt_tokens_details", None)
    completion_details = getattr(raw, "completion_tokens_details", None)
    return TokenUsage(
        input=int(getattr(raw, "prompt_tokens", 0) or 0),
        cached=int(getattr(prompt_details, "cached_tokens", 0) or 0) if prompt_details else 0,
        output=int(getattr(raw, "completion_tokens", 0) or 0),
        reasoning=int(getattr(completion_details, "reasoning_tokens", 0) or 0)
        if completion_details
        else 0,
    )


class OpenAIProvider(ModelProvider):
    name = "openai"

    def __init__(
        self,
        api_key: str,
        *,
        base_url: str | None = None,
        organization: str | None = None,
        timeout: float = 60.0,
    ) -> None:
        # Los reintentos los gestiona la capa de resiliencia, no el SDK.
        self.client = AsyncOpenAI(
            api_key=api_key,
            base_url=base_url,
            organization=organization,
            timeout=timeout,
            max_retries=0,
        )

    def capabilities(self, model: str) -> ModelCapabilities:
        return capabilities_for(model)

    def embedding_dimension(self, model: str) -> int:
        return _EMBEDDING_DIMENSIONS.get(model, 1536)

    def build_payload(self, request: ChatRequest, *, stream: bool = False) -> dict[str, Any]:
        profile = self.capabilities(request.model)
        payload: dict[str, Any] = {
            "model": request.model,
            "messages": [_message_to_openai(m) for m in request.messages],
        }
        if request.tools:
            payload["tools"] = [
                {
                    "type": "function",
                    "function": {
                        "name": t.name,
                        "description": t.description,
                        "parameters": t.parameters,
                    },
                }
                for t in request.tools
            ]
            if isinstance(request.tool_choice, dict):
                payload["tool_choice"] = {
                    "type": "function",
                    "function": {"name": request.tool_choice["name"]},
                }
            else:
                payload["tool_choice"] = request.tool_choice
            payload["parallel_tool_calls"] = True
        if request.response_format == "json" and profile.json_mode:
            payload["response_format"] = {"type": "json_object"}
        if request.max_output_tokens:
            payload[profile.max_tokens_param] = request.max_output_tokens
        if request.prompt_cache_key:
            payload["prompt_cache_key"] = request.prompt_cache_key
        if request.user_hash:
            payload["safety_identifier"] = request.user_hash
        payload.update(filter_params(request.model, request.params, has_tools=bool(request.tools)))
        if stream:
            payload["stream"] = True
            payload["stream_options"] = {"include_usage": True}
        return payload

    async def chat(self, request: ChatRequest) -> ChatResult:
        payload = self.build_payload(request)
        started = time.perf_counter()
        try:
            response = await self.client.chat.completions.create(**payload)
        except Exception as exc:
            raise normalize_openai_error(exc) from exc
        latency = int((time.perf_counter() - started) * 1000)
        choice = response.choices[0]
        tool_calls = [
            ToolCallRequest(
                id=call.id,
                name=call.function.name,
                arguments=_parse_arguments(call.function.arguments),
                raw_arguments=call.function.arguments,
            )
            for call in (choice.message.tool_calls or [])
            if getattr(call, "function", None) is not None
        ]
        return ChatResult(
            content=choice.message.content,
            tool_calls=tool_calls,
            finish_reason=str(choice.finish_reason or "stop"),
            usage=_usage(response.usage),
            model=response.model or request.model,
            provider=self.name,
            provider_request_id=response.id,
            latency_ms=latency,
            raw_request=payload,
            raw_response=response.model_dump(mode="json", exclude_none=True),
        )

    async def stream_chat(self, request: ChatRequest) -> AsyncIterator[StreamDelta | ChatResult]:
        payload = self.build_payload(request, stream=True)
        started = time.perf_counter()
        content_parts: list[str] = []
        calls: dict[int, dict[str, str]] = {}
        usage = TokenUsage()
        finish_reason = "stop"
        response_id: str | None = None
        model = request.model
        try:
            stream = await self.client.chat.completions.create(**payload)
            async for chunk in stream:
                response_id = response_id or chunk.id
                model = chunk.model or model
                if chunk.usage is not None:
                    usage = _usage(chunk.usage)
                for choice in chunk.choices or []:
                    delta = choice.delta
                    if delta and delta.content:
                        content_parts.append(delta.content)
                        yield StreamDelta(delta.content)
                    for call in (delta.tool_calls or []) if delta else []:
                        slot = calls.setdefault(call.index, {"id": "", "name": "", "arguments": ""})
                        if call.id:
                            slot["id"] = call.id
                        if call.function and call.function.name:
                            slot["name"] = call.function.name
                        if call.function and call.function.arguments:
                            slot["arguments"] += call.function.arguments
                    if choice.finish_reason:
                        finish_reason = str(choice.finish_reason)
        except Exception as exc:
            raise normalize_openai_error(exc) from exc
        tool_calls = [
            ToolCallRequest(
                id=s["id"],
                name=s["name"],
                arguments=_parse_arguments(s["arguments"]),
                raw_arguments=s["arguments"],
            )
            for _, s in sorted(calls.items())
        ]
        content = "".join(content_parts) or None
        yield ChatResult(
            content=content,
            tool_calls=tool_calls,
            finish_reason=finish_reason,
            usage=usage,
            model=model,
            provider=self.name,
            provider_request_id=response_id,
            latency_ms=int((time.perf_counter() - started) * 1000),
            raw_request=payload,
            raw_response={
                "id": response_id,
                "content": content,
                "tool_calls": [c.__dict__ for c in tool_calls],
            },
        )

    async def embed(self, model: str, texts: list[str]) -> EmbeddingResult:
        started = time.perf_counter()
        try:
            response = await self.client.embeddings.create(model=model, input=texts)
        except Exception as exc:
            raise normalize_openai_error(exc) from exc
        vectors = [list(item.embedding) for item in sorted(response.data, key=lambda d: d.index)]
        usage = TokenUsage(input=int(getattr(response.usage, "prompt_tokens", 0) or 0))
        return EmbeddingResult(
            vectors=vectors,
            usage=usage,
            model=model,
            provider=self.name,
            dimension=len(vectors[0]) if vectors else self.embedding_dimension(model),
            latency_ms=int((time.perf_counter() - started) * 1000),
        )

    async def health(self) -> bool:
        try:
            await self.client.models.list()
        except Exception:
            return False
        return True

    async def close(self) -> None:
        await self.client.close()
