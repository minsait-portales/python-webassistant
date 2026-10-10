"""SDK de skills portables de WebAssistant (CG-06.7).

Una skill se declara con un manifiesto (``skill.yaml``) y esquemas JSON. Solo
si necesita lógica propia aporta un handler Python que implementa
:class:`SkillHandler` o :class:`ActionHandler`. El núcleo nunca necesita
modificarse para incorporar una capacidad nueva.
"""

from webassistant_skill_sdk.handlers import (
    ActionContext,
    ActionHandler,
    ActionResult,
    RetrievedSource,
    SkillContext,
    SkillHandler,
)
from webassistant_skill_sdk.manifest import (
    MANIFEST_FILENAMES,
    ManifestError,
    SkillPackage,
    load_skill_package,
)

__all__ = [
    "MANIFEST_FILENAMES",
    "ActionContext",
    "ActionHandler",
    "ActionResult",
    "ManifestError",
    "RetrievedSource",
    "SkillContext",
    "SkillHandler",
    "SkillPackage",
    "load_skill_package",
]
