"""Tipos y clases base para handlers de skills y acciones."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class RetrievedSource:
    """Fragmento recuperado por RAG que el modelo ha visto en este run."""

    chunk_id: str
    source_id: str
    knowledge_base: str
    title: str
    text: str
    score: float
    url: str | None = None
    locale: str = "es"
    document_type: str = "page"
    page: int | None = None
    metadata: dict[str, Any] = field(default_factory=dict)


@dataclass
class SkillContext:
    """Información que el núcleo comparte con el handler de una skill."""

    skill: str
    locale: str
    tenant_id: str
    site_id: str
    subject: str
    scopes: frozenset[str]
    sources: list[RetrievedSource] = field(default_factory=list)
    active_selection: list[dict[str, Any]] = field(default_factory=list)
    config: dict[str, Any] = field(default_factory=dict)
    capabilities: frozenset[str] = frozenset()

    def source_by_id(self, source_id: str) -> RetrievedSource | None:
        for source in self.sources:
            if source.source_id == source_id:
                return source
        return None


class SkillHandler:
    """Punto de extensión opcional de una skill.

    Todos los métodos tienen una implementación por defecto neutra; un handler
    solo sobrescribe lo que necesita. Las salidas siguen validándose contra los
    esquemas del manifiesto y del renderer, por lo que un handler no puede
    saltarse los contratos.
    """

    def prepare_result(self, ctx: SkillContext, result: dict[str, Any]) -> dict[str, Any]:
        """Normaliza el ``result`` del modelo antes de validarlo."""
        return result

    def build_presentation(self, ctx: SkillContext, result: dict[str, Any]) -> dict[str, Any]:
        """Transforma el resultado validado en los datos del renderer."""
        return result

    def build_chat_reply(self, ctx: SkillContext, result: dict[str, Any], chat_reply: str) -> str:
        """Devuelve el texto breve que se muestra en el chat."""
        return chat_reply


@dataclass
class ActionContext:
    tenant_id: str
    site_id: str
    subject: str
    scopes: frozenset[str]
    skill: str
    action: str
    execution_id: str
    run_id: str | None = None
    conversation_id: str | None = None
    locale: str = "es"


@dataclass
class ActionResult:
    status: str = "succeeded"
    data: dict[str, Any] = field(default_factory=dict)
    message: str = ""


class ActionHandler:
    """Handler de una acción interna (ejecutada en el servidor de WebAssistant)."""

    async def execute(self, ctx: ActionContext, params: dict[str, Any]) -> ActionResult:
        raise NotImplementedError
