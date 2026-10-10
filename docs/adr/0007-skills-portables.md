# ADR 0007 · Skills como paquetes portables

**Contexto.** El módulo Drupal mezcla skills con PHP y plantillas Twig. La capa genérica debe permitir
añadir skills sin modificar el núcleo.

**Decisión.** Una skill es un directorio con `skill.yaml` (manifiesto `webassistant.ai/v1` validado con
JSON Schema), `schemas/input.json`, `examples/response.json`, esquemas de acciones y, opcionalmente,
un handler Python (`webassistant-skill-sdk`). La salida se pinta con renderers versionados
(`product_grid@1`, `answer@1`...). Se descubren desde el paquete integrado, *entry points* y
`WEBASSISTANT_SKILL_PATHS`; las incompatibles se desactivan con diagnóstico.

**Consecuencias.** Las 17 skills del módulo Drupal se migraron a este formato; `examples/skills/store_hours`
demuestra una skill de terceros. Ver la [guía de skills](../skills.md).
