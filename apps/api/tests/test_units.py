"""Pruebas unitarias de utilidades, seguridad, precios, resiliencia y streaming."""

from __future__ import annotations

import asyncio
from datetime import UTC, datetime, timedelta
from decimal import Decimal

import pytest

from webassistant.config import ConfigurationError, Settings
from webassistant.errors import AppError, ErrorCode
from webassistant.gateway.base import CancelToken, ProviderError, TokenUsage
from webassistant.gateway.pricing import PriceSnapshot, compute_cost
from webassistant.gateway.profiles import filter_params
from webassistant.gateway.resilience import CircuitBreaker, Resilience, RetryPolicy
from webassistant.ids import chunk_point_id, new_id
from webassistant.orchestrator.events import ReplyExtractor
from webassistant.security.crypto import (
    PayloadCipher,
    hash_secret,
    password_problems,
    verify_secret,
)
from webassistant.security.origins import normalize_origin, origin_allowed, validate_origin_pattern
from webassistant.security.redaction import redact, redact_text
from webassistant.security.scopes import AdminRole, Permission, has_scopes, role_permissions
from webassistant.security.tokens import (
    AdminClaims,
    ConfirmationClaims,
    ServerClaims,
    TokenService,
    WidgetClaims,
)
from webassistant.services.theme import contrast_ratio, validate_theme
from webassistant.skills.runtime import parse_model_output
from webassistant.text import limit_text, tokens


def test_production_requires_secure_configuration() -> None:
    with pytest.raises(ConfigurationError) as info:
        Settings(environment="production", jwt_secret="short")
    message = str(info.value)
    assert "JWT_SECRET" in message
    assert "ENCRYPTION_KEY" in message
    assert "PostgreSQL" in message


def test_ids_are_opaque_and_chunk_ids_stable() -> None:
    a, b = new_id("conversation"), new_id("conversation")
    assert a.startswith("conv_") and a != b
    assert chunk_point_id("t", "kb", "s", "1", 0) == chunk_point_id("t", "kb", "s", "1", 0)
    assert chunk_point_id("t", "kb", "s", "1", 0) != chunk_point_id("t2", "kb", "s", "1", 0)


def test_text_tokens_and_limits() -> None:
    assert "barbacoa" in tokens("¡Organiza una BARBACOA!")
    assert limit_text("x" * 200, 20).endswith("...")


def test_redaction_removes_secrets_and_pii() -> None:
    text = "token Bearer abcdefghijklmnop y correo ana@example.com, sk-1234567890abcdefghij"
    redacted = redact_text(text)
    assert (
        "ana@example.com" not in redacted
        and "abcdefghijklmnop" not in redacted
        and "sk-123" not in redacted
    )
    data = redact({"password": "x", "nested": {"api_key": "y", "ok": "visible"}})
    assert data == {"password": "[REDACTED]", "nested": {"api_key": "[REDACTED]", "ok": "visible"}}


def test_secret_hash_and_password_policy() -> None:
    encoded = hash_secret("s3cret-value")
    assert verify_secret("s3cret-value", encoded)
    assert not verify_secret("other", encoded)
    assert password_problems("short")
    assert not password_problems("Una-Clave-Segura-2026")


def test_payload_cipher_roundtrip() -> None:
    cipher = PayloadCipher(None)
    token = cipher.encrypt("prompt interno")
    assert "prompt" not in token
    assert cipher.decrypt(token) == "prompt interno"


def test_origins() -> None:
    assert normalize_origin("HTTPS://Example.com:443/path") == "https://example.com"
    assert origin_allowed("https://shop.example.com", ["https://*.example.com"])
    assert not origin_allowed("https://evil.com", ["https://example.com"])
    assert not origin_allowed(None, ["https://example.com"])
    with pytest.raises(ValueError):
        validate_origin_pattern("https://example.com/path")


def test_scopes_and_roles() -> None:
    assert has_scopes({"cart:*"}, ["cart:write"])
    assert not has_scopes({"chat:use"}, ["cart:write"])
    assert Permission.PLATFORM_MANAGE in role_permissions(AdminRole.PLATFORM_ADMIN)
    assert Permission.PLATFORM_MANAGE not in role_permissions(AdminRole.TENANT_ADMIN)
    assert Permission.CONFIG_WRITE not in role_permissions(AdminRole.VIEWER)
    assert Permission.PAYLOADS_READ in role_permissions(AdminRole.AUDITOR)


def _tokens(**overrides: object) -> TokenService:
    values = {"jwt_secret": "a" * 40, "environment": "test"}
    values.update(overrides)
    return TokenService(Settings(**values))  # type: ignore[arg-type]


def test_token_audiences_are_isolated() -> None:
    service = _tokens()
    widget, _ = service.issue_widget(
        WidgetClaims(
            subject="u1",
            subject_type="user",
            tenant_id="t",
            site_id="s",
            site_public_id="site",
            scopes=frozenset({"chat:use"}),
        )
    )
    server, _ = service.issue_server(ServerClaims("c", "t", None, frozenset({"ingest:write"})))
    admin, _ = service.issue_admin(AdminClaims("u", None, "platform_admin"))
    assert service.decode_widget(widget).subject == "u1"
    with pytest.raises(AppError):
        service.decode_admin(widget)
    with pytest.raises(AppError):
        service.decode_widget(server)
    with pytest.raises(AppError):
        service.decode_server(admin)


def test_token_expiry_and_rotation() -> None:
    old = _tokens(jwt_secret="o" * 40)
    token, _ = old.issue_server(ServerClaims("c", "t", None, frozenset()))
    rotated = _tokens(jwt_secret="n" * 40, jwt_previous_secrets="o" * 40)
    assert rotated.decode_server(token).client_id == "c"
    unrelated = _tokens(jwt_secret="x" * 40)
    with pytest.raises(AppError):
        unrelated.decode_server(token)
    past = _tokens()
    past._now = lambda: datetime.now(UTC) - timedelta(hours=2)  # type: ignore[assignment]
    expired, _ = past.issue_confirmation(ConfirmationClaims("s", "e", "h", "t"))
    with pytest.raises(AppError) as info:
        _tokens().decode_confirmation(expired)
    assert info.value.code == ErrorCode.CONFIRMATION_INVALID


def test_widget_acl_principals() -> None:
    claims = WidgetClaims(
        subject="u9",
        subject_type="user",
        tenant_id="t",
        site_id="s",
        site_public_id="p",
        scopes=frozenset(),
        roles=("editor",),
        groups=("g1",),
    )
    assert claims.acl_principals == {
        "public",
        "authenticated",
        "user:u9",
        "role:editor",
        "group:g1",
    }
    anonymous = WidgetClaims(
        subject="anon",
        subject_type="anonymous",
        tenant_id="t",
        site_id="s",
        site_public_id="p",
        scopes=frozenset(),
    )
    assert anonymous.acl_principals == {"public"}


def test_cost_distinguishes_input_cache_and_output() -> None:
    price = PriceSnapshot(
        "price_1", "openai", "gpt-6-luna", Decimal("0.1"), Decimal("0.01"), Decimal("0.5"), "USD"
    )
    cost = compute_cost(TokenUsage(input=1_000_000, cached=400_000, output=200_000), price)
    assert cost.input == Decimal("0.06")
    assert cost.cached == Decimal("0.004")
    assert cost.output == Decimal("0.1")
    assert cost.total == Decimal("0.164")
    assert compute_cost(TokenUsage(input=1_000_000, cached=400_000, output=200_000), price) == cost
    assert compute_cost(TokenUsage(input=10), None).total == 0


def test_luna_tool_compatibility_params() -> None:
    params = filter_params(
        "gpt-6-luna", {"temperature": 0.2, "reasoning_effort": "high"}, has_tools=True
    )
    assert params == {"reasoning_effort": "none"}
    classic = filter_params(
        "gpt-4.1-mini", {"temperature": 0.2, "reasoning_effort": "high"}, has_tools=False
    )
    assert classic == {"temperature": 0.2}


async def test_resilience_retries_then_succeeds() -> None:
    resilience = Resilience(RetryPolicy(max_retries=2, base_delay=0.001, timeout=1))
    calls = {"n": 0}

    async def flaky() -> str:
        calls["n"] += 1
        if calls["n"] < 3:
            raise ProviderError(ErrorCode.PROVIDER_UNAVAILABLE, "caído")
        return "ok"

    assert await resilience.call("p:m", flaky) == "ok"
    assert calls["n"] == 3


async def test_resilience_does_not_retry_bad_requests_and_times_out() -> None:
    resilience = Resilience(RetryPolicy(max_retries=3, base_delay=0.001, timeout=0.05))
    calls = {"n": 0}

    async def bad() -> str:
        calls["n"] += 1
        raise ProviderError(ErrorCode.PROVIDER_BAD_REQUEST, "x")

    with pytest.raises(ProviderError):
        await resilience.call("p:bad", bad)
    assert calls["n"] == 1

    async def slow() -> str:
        await asyncio.sleep(1)
        return "late"

    resilience.policy.max_retries = 0
    with pytest.raises(ProviderError) as info:
        await resilience.call("p:slow", slow)
    assert info.value.code == ErrorCode.PROVIDER_TIMEOUT


async def test_resilience_cancellation() -> None:
    resilience = Resilience(RetryPolicy(max_retries=0, timeout=5))
    token = CancelToken()

    async def slow() -> str:
        await asyncio.sleep(2)
        return "late"

    asyncio.get_running_loop().call_later(0.05, token.cancel)
    with pytest.raises(AppError) as info:
        await resilience.call("p:c", slow, cancel=token)
    assert info.value.code == ErrorCode.RUN_CANCELLED


def test_circuit_breaker_opens_and_half_opens() -> None:
    breaker = CircuitBreaker(failure_threshold=2, reset_seconds=0)
    breaker.record_failure()
    assert breaker.allow()
    breaker.record_failure()
    assert breaker.state == "open"
    assert breaker.allow()  # reset_seconds=0 -> half-open inmediato
    assert breaker.state == "half_open"
    breaker.record_success()
    assert breaker.state == "closed"


def test_reply_extractor_streams_chat_reply() -> None:
    extractor = ReplyExtractor()
    chunks = [
        '{"skill":"x","chat',
        '_reply": "Hola \\"mun',
        'do\\" \\u00e1',
        'cento", "result":{}}',
    ]
    output = "".join(extractor.feed(c) for c in chunks)
    assert output == 'Hola "mundo" ácento'
    assert extractor.done


def test_parse_model_output_tolerates_fences() -> None:
    assert parse_model_output('```json\n{"skill": "default"}\n```') == {"skill": "default"}
    assert parse_model_output('texto {"a": 1} final') == {"a": 1}
    assert parse_model_output("nada") is None


def test_theme_contrast_validation() -> None:
    assert contrast_ratio("#000000", "#ffffff") > 20
    validate_theme(
        {"accent": "#480e2a"},
        {
            "accent": "#480e2a",
            "on_accent": "#ffffff",
            "text": "#260717",
            "surface": "#ffffff",
            "muted_surface": "#f6f5f1",
        },
    )
    with pytest.raises(AppError):
        validate_theme({"text": "#eeeeee"}, {"text": "#260717", "surface": "#ffffff"})
    with pytest.raises(AppError):
        validate_theme({"accent": "red; background:url(x)"}, {})


def test_control_characters_are_removed_from_extracted_text() -> None:
    from webassistant.rag.extraction import normalize_inline
    from webassistant.text import clean_whitespace, strip_control_chars

    raw = "Bater\x00ía\x07 de 5000\tmAh\nLínea 2\x1b"
    assert strip_control_chars(raw) == "Batería de 5000\tmAh\nLínea 2"
    assert "\x00" not in clean_whitespace(raw)
    for content_format in ("text", "markdown", "html"):
        assert "\x00" not in normalize_inline(raw, content_format).text


def test_heading_anchors_keep_accented_letters() -> None:
    from webassistant.rag.chunking import _slug

    assert _slug("Garantía de los productos") == "garantia-de-los-productos"
    assert _slug("¿Cómo pido cita previa?") == "como-pido-cita-previa"
