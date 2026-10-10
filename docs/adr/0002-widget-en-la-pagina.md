# ADR 0002 · Widget montado en la página con Shadow DOM

**Contexto.** La versión 1.4 del documento descarta el iframe: el chat debe integrarse en la página
anfitriona (resultados en contenedores de la página, host actions, accesibilidad coherente) sin que los
estilos del portal lo rompan ni el widget rompa el portal.

**Decisión.** `loader.js` estable (caché de 5 min) valida atributos, crea `window.WebAssistant` con
cola y carga un bundle versionado `assets/widget.<hash>.js` con integridad **SRI**. El chat se monta en
un elemento propio (`<webassistant-chat>`) con **Shadow DOM** abierto; `:host { all: initial !important }`
aísla la herencia y vence a reglas `!important` de la página. Todo el DOM se construye con
`textContent` (regla ESLint que prohíbe `innerHTML`) y las URLs se sanean (solo http/https). Los
resultados pueden pintarse en un contenedor de la página (`data-result-target`), también en Shadow DOM.

**Alternativas.** iframe (aislamiento total pero sin integración con la página y con problemas de
foco, tamaño y accesibilidad); Web Components con framework (peso y conflictos de versiones).

**Consecuencias.** Funciona bajo CSP estricta (hojas de estilo construibles), supera la prueba de CSS
agresivo y axe en escritorio y móvil. Los bundles antiguos se conservan para no romper widgets abiertos.
