"""Utilidades de texto compartidas: normalización, tokens, oraciones y recortes."""

from __future__ import annotations

import html
import re
import unicodedata

_STOPWORDS = frozenset(
    [
        "a",
        "al",
        "algo",
        "algun",
        "alguna",
        "algunas",
        "alguno",
        "algunos",
        "ante",
        "antes",
        "aqui",
        "asi",
        "aun",
        "cada",
        "como",
        "con",
        "contra",
        "cual",
        "cuales",
        "cuando",
        "de",
        "del",
        "desde",
        "donde",
        "dos",
        "el",
        "ella",
        "ellas",
        "ellos",
        "en",
        "entre",
        "era",
        "es",
        "esa",
        "esas",
        "ese",
        "eso",
        "esos",
        "esta",
        "estas",
        "este",
        "esto",
        "estos",
        "fue",
        "ha",
        "hace",
        "hay",
        "la",
        "las",
        "le",
        "les",
        "lo",
        "los",
        "mas",
        "me",
        "mi",
        "mis",
        "mucho",
        "muy",
        "nada",
        "ni",
        "no",
        "nos",
        "o",
        "os",
        "otra",
        "otro",
        "para",
        "pero",
        "poco",
        "por",
        "porque",
        "puede",
        "pueden",
        "que",
        "quien",
        "se",
        "sea",
        "ser",
        "si",
        "sin",
        "sobre",
        "son",
        "su",
        "sus",
        "tal",
        "tambien",
        "te",
        "tengo",
        "tiene",
        "tienen",
        "tu",
        "tus",
        "un",
        "una",
        "uno",
        "unos",
        "unas",
        "ya",
        "yo",
        "quiero",
        "quieres",
        "puedo",
        "necesito",
        "hola",
        "gracias",
        "favor",
        "the",
        "a",
        "an",
        "and",
        "or",
        "of",
        "to",
        "in",
        "on",
        "for",
        "with",
        "is",
        "are",
        "be",
        "by",
        "at",
        "from",
        "this",
        "that",
        "it",
        "as",
        "what",
        "which",
        "who",
        "how",
        "can",
        "i",
        "you",
        "me",
        "my",
        "we",
        "our",
        "your",
        "please",
        "show",
        "find",
    ]
)

_WORD_RE = re.compile(r"[a-z0-9ñ]+")
_TAG_RE = re.compile(r"<[^>]+>")
_SPACE_RE = re.compile(r"\s+")
_SENTENCE_RE = re.compile(r"(?<=[.!?])\s+")


def strip_accents(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def normalize(text: str) -> str:
    text = strip_accents((text or "").lower())
    text = re.sub(r"[^a-z0-9 ]+", " ", text)
    return _SPACE_RE.sub(" ", text).strip()


def stem(word: str) -> str:
    """Stemming ligero (es/en) suficiente para el proveedor simulado y el matcher local."""
    for suffix in (
        "aciones",
        "acion",
        "amente",
        "mente",
        "idades",
        "idad",
        "ciones",
        "cion",
        "es",
        "s",
    ):
        if len(word) > len(suffix) + 3 and word.endswith(suffix):
            return word[: -len(suffix)]
    return word


def tokens(text: str, *, keep_stopwords: bool = False) -> list[str]:
    words = _WORD_RE.findall(normalize(text))
    if not keep_stopwords:
        words = [w for w in words if w not in _STOPWORDS and len(w) > 1]
    return [stem(w) for w in words]


def strip_html(text: str) -> str:
    text = re.sub(r"(?is)<(script|style)[^>]*>.*?</\1>", " ", text)
    text = re.sub(r"(?i)<br\s*/?>|</p>|</li>|</h[1-6]>|</div>", "\n", text)
    text = _TAG_RE.sub(" ", text)
    return html.unescape(text)


def clean_whitespace(text: str) -> str:
    lines = [_SPACE_RE.sub(" ", line).strip() for line in text.replace("\r", "\n").split("\n")]
    out: list[str] = []
    blank = False
    for line in lines:
        if not line:
            if not blank and out:
                out.append("")
            blank = True
            continue
        out.append(line)
        blank = False
    return "\n".join(out).strip()


def sentences(text: str) -> list[str]:
    flat = _SPACE_RE.sub(" ", text or "").strip()
    if not flat:
        return []
    return [s.strip() for s in _SENTENCE_RE.split(flat) if s.strip()]


def first_sentence(text: str, limit: int = 140) -> str:
    parts = sentences(text)
    return limit_text(parts[0] if parts else text, limit)


def limit_text(text: str, limit: int = 140) -> str:
    text = (text or "").strip()
    if len(text) <= limit:
        return text
    return text[: limit - 3].rstrip(" \t\n\r.,;:") + "..."


def strip_code_fences(text: str) -> str:
    text = (text or "").strip()
    return re.sub(r"^```(?:json)?\s*|\s*```$", "", text).strip()
