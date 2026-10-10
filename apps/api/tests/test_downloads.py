"""Protección SSRF en la descarga de originales del portal (CG-07.3, amenaza T-09)."""

from __future__ import annotations

import pytest

from webassistant.errors import AppError, ErrorCode
from webassistant.rag.extraction import check_download_url


@pytest.mark.parametrize(
    "url",
    [
        "https://127.0.0.1/doc.pdf",
        "https://localhost/doc.pdf",
        "https://169.254.169.254/latest/meta-data/",
        "https://10.0.0.5/doc.pdf",
        "https://192.168.1.10/doc.pdf",
        "https://[::1]/doc.pdf",
    ],
)
async def test_private_destinations_are_rejected_without_allowlist(url: str) -> None:
    with pytest.raises(AppError) as error:
        await check_download_url(url, allowed_hosts=[])
    assert error.value.code == ErrorCode.FORBIDDEN


@pytest.mark.parametrize("url", ["ftp://portal.example.com/doc.pdf", "file:///etc/passwd"])
async def test_only_https_is_accepted(url: str) -> None:
    with pytest.raises(AppError):
        await check_download_url(url, allowed_hosts=["portal.example.com"])


async def test_http_only_allowed_in_development() -> None:
    with pytest.raises(AppError):
        await check_download_url(
            "http://docs.portal.example.com/a.pdf", allowed_hosts=["portal.example.com"]
        )
    await check_download_url(
        "http://docs.portal.example.com/a.pdf",
        allowed_hosts=["portal.example.com"],
        allow_http=True,
    )


async def test_allowlist_permits_intranet_hosts_and_rejects_others() -> None:
    await check_download_url(
        "https://files.portal.intranet/doc.pdf", allowed_hosts=["portal.intranet"]
    )
    with pytest.raises(AppError) as error:
        await check_download_url(
            "https://evil.example.com/doc.pdf", allowed_hosts=["portal.intranet"]
        )
    assert error.value.code == ErrorCode.FORBIDDEN
    with pytest.raises(AppError):
        await check_download_url(
            "https://portal.intranet.evil.com/doc.pdf", allowed_hosts=["portal.intranet"]
        )


async def test_production_requires_an_allowlist() -> None:
    with pytest.raises(AppError) as error:
        await check_download_url(
            "https://example.com/doc.pdf", allowed_hosts=[], require_allowlist=True
        )
    assert "WEBASSISTANT_INGEST_ALLOWED_DOWNLOAD_HOSTS" in error.value.detail
