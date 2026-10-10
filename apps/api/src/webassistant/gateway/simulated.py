"""Proveedor de IA simulado y determinista.

Sirve para pruebas, ensayos de carga (CG-15.10: "proveedor IA simulado") y la
demo autónoma (CG-16.3). Reproduce el contrato real: enruta a una skill,
solicita la tool RAG cuando la skill lo requiere y compone la respuesta JSON
a partir del ejemplo de la skill y de las fuentes recuperadas. Nunca inventa
identificadores: solo usa los que aparecen en los resultados de la tool.
"""

from __future__ import annotations

import asyncio
import copy
import hashlib
import itertools
import json
import math
import re
import time
from collections import OrderedDict
from collections.abc import AsyncIterator
from typing import Any

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
    approx_tokens,
)
from webassistant.text import first_sentence, limit_text, normalize, sentences, tokens

SIMULATED_EMBEDDING_MODEL = "simulated-embedding-v1"
SIMULATED_CHAT_MODEL = "simulated-chat-v1"
SIMULATED_DIMENSION = 384
OUT_OF_SCOPE_REPLY = (
    "Solo puedo ayudarte con consultas relacionadas con este sitio. ¿Qué necesitas buscar?"
)
_FOLLOW_UP = re.compile(
    r"\b(anade|añade|agrega|quita|elimina|cambia|sustituye|ademas|tambien|mas)\b"
)
_NUMBER = re.compile(r"\b(\d{1,4})\b")


class SimulatedProvider(ModelProvider):
    name = "simulated"

    def __init__(self, *, latency_ms: int = 0, fail_with: ErrorCode | None = None) -> None:
        self.latency_ms = latency_ms
        self.fail_with = fail_with
        self.calls: list[ChatRequest] = []

    def capabilities(self, model: str) -> ModelCapabilities:
        return ModelCapabilities(temperature=False, reasoning_effort=False, context_window=200_000)

    def embedding_dimension(self, model: str) -> int:
        return SIMULATED_DIMENSION

    # --- Chat -------------------------------------------------------------------

    async def chat(self, request: ChatRequest) -> ChatResult:
        started = time.perf_counter()
        self.calls.append(request)
        if self.latency_ms:
            await asyncio.sleep(self.latency_ms / 1000)
        if self.fail_with is not None:
            raise ProviderError(self.fail_with, "Fallo simulado del proveedor")
        content, tool_calls = self._respond(request)
        prompt_text = "\n".join(m.content or "" for m in request.messages)
        usage = TokenUsage(
            input=approx_tokens(prompt_text),
            cached=approx_tokens(request.messages[0].content) // 2 if request.messages else 0,
            output=approx_tokens(content)
            + sum(approx_tokens(json.dumps(c.arguments)) for c in tool_calls),
        )
        digest = hashlib.sha256(prompt_text.encode()).hexdigest()[:16]
        return ChatResult(
            content=content,
            tool_calls=tool_calls,
            finish_reason="tool_calls" if tool_calls else "stop",
            usage=usage,
            model=request.model,
            provider=self.name,
            provider_request_id=f"sim-{digest}",
            latency_ms=int((time.perf_counter() - started) * 1000),
            raw_request={
                "model": request.model,
                "messages": len(request.messages),
                "tools": [t.name for t in request.tools],
            },
            raw_response={"content": content, "tool_calls": [c.name for c in tool_calls]},
        )

    async def stream_chat(self, request: ChatRequest) -> AsyncIterator[StreamDelta | ChatResult]:
        result = await self.chat(request)
        if result.content:
            for index in range(0, len(result.content), 24):
                yield StreamDelta(result.content[index : index + 24])
                await asyncio.sleep(0)
        yield result

    def _respond(self, request: ChatRequest) -> tuple[str | None, list[ToolCallRequest]]:
        hints = request.hints or {}
        user_message, tool_payloads, requested_skill = self._conversation_state(request.messages)
        if hints.get("phase") == "repair":
            previous = hints.get("previous_output") or {}
            repair_skill: dict[str, Any] = hints.get("selected_skill") or {}
            return self._compose(repair_skill, user_message, [], hints, fallback=previous), []

        catalog: list[dict[str, Any]] = hints.get("catalog") or []
        selected: dict[str, Any] | None = hints.get("selected_skill")
        rag_tool = hints.get("rag_tool") or "search_knowledge"
        has_rag_tool = any(t.name == rag_tool for t in request.tools)

        if tool_payloads:
            skill_name = requested_skill or (selected or {}).get("skill")
            skill = self._find(catalog, skill_name) or selected or {}
            results = [r for payload in tool_payloads for r in payload.get("results", [])]
            return self._compose(skill, user_message, results, hints), []

        routed: dict[str, Any] | None = (
            selected
            if selected and hints.get("force_rag")
            else self._route(catalog, user_message, hints)
        )
        if routed is None:
            return json.dumps(
                {
                    "skill": "default",
                    "out_of_scope": True,
                    "chat_reply": OUT_OF_SCOPE_REPLY,
                    "result": {},
                },
                ensure_ascii=False,
            ), []
        if (routed.get("requires_rag") or hints.get("force_rag")) and has_rag_tool:
            query = " ".join(tokens(user_message, keep_stopwords=False)) or user_message
            call = ToolCallRequest(
                id="call_" + hashlib.sha256(f"{routed['skill']}:{query}".encode()).hexdigest()[:12],
                name=rag_tool,
                arguments={
                    "skill": routed["skill"],
                    "search_string": user_message[:300],
                    "amount": 6,
                },
            )
            call.raw_arguments = json.dumps(call.arguments, ensure_ascii=False)
            _ = query
            return None, [call]
        return self._compose(routed, user_message, [], hints), []

    @staticmethod
    def _conversation_state(
        messages: list[ChatMessage],
    ) -> tuple[str, list[dict[str, Any]], str | None]:
        last_user_index = max((i for i, m in enumerate(messages) if m.role == "user"), default=-1)
        user_message = messages[last_user_index].content or "" if last_user_index >= 0 else ""
        payloads: list[dict[str, Any]] = []
        requested_skill: str | None = None
        for message in messages[last_user_index + 1 :]:
            if message.role == "assistant":
                for call in message.tool_calls:
                    requested_skill = str(call.arguments.get("skill") or requested_skill or "")
            if message.role == "tool" and message.content:
                try:
                    data = json.loads(message.content)
                except json.JSONDecodeError:
                    continue
                if isinstance(data, dict):
                    payloads.append(data)
        return user_message, payloads, requested_skill or None

    @staticmethod
    def _find(catalog: list[dict[str, Any]], name: str | None) -> dict[str, Any] | None:
        for item in catalog:
            if item.get("skill") == name:
                return item
        return None

    def _route(
        self, catalog: list[dict[str, Any]], message: str, hints: dict[str, Any]
    ) -> dict[str, Any] | None:
        active_skill = hints.get("active_selection_skill")
        if active_skill and _FOLLOW_UP.search(normalize(message)):
            follow = self._find(catalog, active_skill)
            if follow:
                return follow
        words = set(tokens(message))
        if not words:
            return None
        best: tuple[float, dict[str, Any]] | None = None
        for item in catalog:
            score = self._score(item, words)
            if score > 0 and (best is None or score > best[0]):
                best = (score, item)
        if best is None or best[0] < 2:
            return None
        return best[1]

    @staticmethod
    def _score(item: dict[str, Any], words: set[str]) -> float:
        weighted: list[tuple[float, str]] = [(3.0, k) for k in item.get("keywords", [])]
        weighted += [(2.0, e) for e in item.get("examples", [])]
        weighted.append((2.0, item.get("title", "")))
        weighted.append((0.5, item.get("intent", "")))
        weighted += [(0.5, h) for h in item.get("routing_hints", [])]
        seen: dict[str, float] = {}
        for weight, text in weighted:
            for token in set(tokens(text)):
                for word in words:
                    # Coincidencia exacta o por raíz (plurales y variantes: pedido/pedidos).
                    if token == word or (
                        len(token) >= 5 and len(word) >= 5 and token[:5] == word[:5]
                    ):
                        seen[word] = max(seen.get(word, 0.0), weight)
        return sum(seen.values())

    # --- Composición ---------------------------------------------------------------

    def _compose(
        self,
        skill: dict[str, Any],
        message: str,
        results: list[dict[str, Any]],
        hints: dict[str, Any],
        fallback: dict[str, Any] | None = None,
    ) -> str:
        example = copy.deepcopy(skill.get("response_example") or {"chat_reply": "", "result": {}})
        template: dict[str, Any] = (
            example["result"] if isinstance(example.get("result"), dict) else {}
        )
        products = self._products(results, hints)
        filled = {
            key: self._fill(key, value, message, results, products)
            for key, value in template.items()
        }
        if fallback and isinstance(fallback.get("result"), dict):
            filled = {**filled, **{k: v for k, v in fallback["result"].items() if k in template}}
        chat_reply = str(example.get("chat_reply") or "He preparado la respuesta.")
        if skill.get("requires_rag") and not results and not products:
            chat_reply = "No he encontrado información que encaje con tu consulta."
        elif results and isinstance(filled.get("answer"), str) and filled["answer"]:
            chat_reply = first_sentence(filled["answer"], 160)
        elif results and products:
            chat_reply = f"He encontrado {len(products)} opciones; las tienes en el panel."
        response = {
            "skill": skill.get("skill", "default"),
            "chat_reply": chat_reply,
            "result": filled,
        }
        return json.dumps(response, ensure_ascii=False)

    @staticmethod
    def _products(results: list[dict[str, Any]], hints: dict[str, Any]) -> list[dict[str, Any]]:
        products: OrderedDict[str, dict[str, Any]] = OrderedDict()
        for item in hints.get("active_selection") or []:
            if item.get("product_id"):
                products[str(item["product_id"])] = {
                    "product_id": str(item["product_id"]),
                    "variation_id": item.get("variation_id"),
                    "title": item.get("title", ""),
                    "body": "",
                    "category": item.get("category", ""),
                    "quantity": item.get("quantity", 1),
                }
        for result in results:
            metadata = result.get("metadata") or {}
            is_product = (
                result.get("type") == "product" or "product_id" in metadata or "price" in metadata
            )
            if not is_product:
                continue
            product_id = str(metadata.get("product_id") or result.get("source_id"))
            if product_id in products:
                continue
            products[product_id] = {
                "product_id": product_id,
                "variation_id": metadata.get("variation_id"),
                "title": result.get("title", ""),
                "body": first_sentence(result.get("text", ""), 160),
                "category": metadata.get("category", ""),
                "quantity": 1,
            }
        return list(products.values())[:8]

    def _fill(
        self,
        key: str,
        value: Any,
        message: str,
        results: list[dict[str, Any]],
        products: list[dict[str, Any]],
    ) -> Any:
        top = results[0] if results else None
        if isinstance(value, list):
            sample = value[0] if value else None
            if key in {"items", "products"} and (
                sample is None or (isinstance(sample, dict) and "product_id" in sample)
            ):
                return [self._shape(sample or {}, product) for product in products]
            if key == "rows":
                return [
                    {
                        "product_id": p["product_id"],
                        "variation_id": p.get("variation_id"),
                        "label": p["title"],
                        "value": p["body"] or p["title"],
                    }
                    for p in products[:3]
                ]
            if key in {"points", "steps"}:
                source_text = str(top.get("text", "")) if top else ""
                return [
                    {"title": limit_text(s, 60), "body": s} for s in sentences(source_text)[1:4]
                ]
            if key == "sources":
                return list(
                    OrderedDict.fromkeys(r.get("title", "") for r in results[:3] if r.get("title"))
                )
            if key == "citations":
                documents = [
                    r for r in results if r.get("type") in {"document", "guide", "page"}
                ] or results
                return [
                    {
                        "source_id": r.get("source_id"),
                        "document_title": r.get("title", ""),
                        "quote": first_sentence(r.get("text", ""), 240),
                        "location": r.get("heading") or "",
                        "page": r.get("page"),
                    }
                    for r in documents[:3]
                ]
            if key == "categories":
                groups: OrderedDict[str, list[dict[str, Any]]] = OrderedDict()
                for product in products:
                    groups.setdefault(product.get("category") or "Selección", []).append(product)
                item_sample = (
                    (sample or {}).get("items", [{}])[0] if isinstance(sample, dict) else {}
                )
                return [
                    {
                        "name": name,
                        "description": "",
                        "items": [self._shape(item_sample, p) for p in items],
                    }
                    for name, items in groups.items()
                ]
            if key == "options":
                return value
            if key == "dietary_restrictions":
                return []
            return value
        if isinstance(value, bool):
            return bool(results) if key == "found" else value
        if isinstance(value, int):
            if key in {"people", "vegetarian_people"}:
                numbers = [int(n) for n in _NUMBER.findall(message)]
                if key == "people":
                    return numbers[0] if numbers else value
                return numbers[1] if len(numbers) > 1 else 0
            return value
        if isinstance(value, str):
            if key == "answer":
                if not top:
                    return "No he encontrado contenidos que respondan a tu pregunta."
                parts = [s for s in sentences(top.get("text", "")) if len(s.split()) > 3]
                return " ".join(parts[:2]) or top.get("title", "")
            if key in {"intro", "description", "summary"}:
                if results:
                    return f"He encontrado {len(products) or len(results)} resultados relacionados con tu consulta."
                return value
            if key == "query":
                return message[:200]
            if key == "event_name":
                return limit_text(message, 80)
            if key in {"budget", "notes"}:
                return ""
            return value
        return value

    @staticmethod
    def _shape(sample: dict[str, Any], product: dict[str, Any]) -> dict[str, Any]:
        shaped: dict[str, Any] = {"product_id": product["product_id"], "title": product["title"]}
        if product.get("variation_id"):
            shaped["variation_id"] = product["variation_id"]
        if "body" in sample:
            shaped["body"] = product.get("body", "")
        if "quantity" in sample:
            shaped["quantity"] = int(product.get("quantity") or 1)
        return shaped

    # --- Embeddings ----------------------------------------------------------------

    async def embed(self, model: str, texts: list[str]) -> EmbeddingResult:
        started = time.perf_counter()
        if self.fail_with is not None:
            raise ProviderError(self.fail_with, "Fallo simulado del proveedor")
        vectors = [hashed_embedding(text, SIMULATED_DIMENSION) for text in texts]
        return EmbeddingResult(
            vectors=vectors,
            usage=TokenUsage(input=sum(approx_tokens(t) for t in texts)),
            model=model,
            provider=self.name,
            dimension=SIMULATED_DIMENSION,
            latency_ms=int((time.perf_counter() - started) * 1000),
        )


def hashed_embedding(text: str, dimension: int) -> list[float]:
    """Embedding determinista por hashing de tokens y bigramas (normalizado L2)."""
    vector = [0.0] * dimension
    words = tokens(text)
    features = words + [f"{a}_{b}" for a, b in itertools.pairwise(words)]
    for feature in features:
        digest = hashlib.blake2b(feature.encode(), digest_size=8).digest()
        index = int.from_bytes(digest[:4], "big") % dimension
        sign = 1.0 if digest[4] & 1 else -1.0
        weight = 1.0 if "_" not in feature else 0.5
        vector[index] += sign * weight
    norm = math.sqrt(sum(v * v for v in vector))
    if norm == 0:
        vector[0] = 1.0
        return vector
    return [v / norm for v in vector]
