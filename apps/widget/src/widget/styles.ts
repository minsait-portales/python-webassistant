/**
 * Estilos del widget, encapsulados en Shadow DOM para resistir CSS agresivo
 * de la página anfitriona. Diseño heredado del módulo Drupal (drawer lateral,
 * pestaña vertical y paleta Minsait) con requisitos WCAG 2.1 AA: foco visible,
 * contraste, reflow a 320 px, objetivos táctiles de 44 px y reducción de movimiento.
 */
export const BASE_CSS = `
/* !important en :host gana a las reglas !important de la página (contexto de encapsulación). */
:host { all: initial !important; }
[hidden] { display: none !important; }
*, *::before, *::after { box-sizing: border-box; }
.wa {
  font-family: var(--wa-font);
  font-size: 16px;
  line-height: 1.45;
  color: var(--wa-text);
  -webkit-font-smoothing: antialiased;
}
.wa-sr {
  position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}
button, textarea, a { font: inherit; }
button { cursor: pointer; }
:focus { outline: none; }
:focus-visible { outline: 3px solid var(--wa-highlight); outline-offset: 2px; border-radius: 4px; }
.wa-launcher {
  position: fixed; top: 96px; z-index: var(--wa-z);
  min-width: 44px; min-height: 124px; padding: 12px 10px;
  border: 1px solid var(--wa-border); background: var(--wa-accent); color: var(--wa-on-accent);
  font-size: 14px; font-weight: 800; letter-spacing: .02em;
  writing-mode: vertical-rl; transform: rotate(180deg);
  box-shadow: 0 6px 18px rgba(38, 7, 23, .18);
}
.wa[data-position="left"] .wa-launcher { left: 0; border-left: 0; border-radius: var(--wa-radius) 0 0 var(--wa-radius); }
.wa[data-position="right"] .wa-launcher { right: 0; border-right: 0; border-radius: 0 var(--wa-radius) var(--wa-radius) 0; }
.wa-launcher:hover { filter: brightness(1.15); }
.wa-panel {
  position: fixed; top: 0; bottom: 0; z-index: var(--wa-z);
  width: min(var(--wa-chat-width), 100vw);
  display: grid; grid-template-rows: auto auto 1fr auto auto;
  background: var(--wa-muted-surface); color: var(--wa-text);
  box-shadow: 12px 0 32px rgba(38, 7, 23, .16);
  transition: transform 240ms ease;
}
.wa[data-position="left"] .wa-panel { left: 0; border-right: 1px solid var(--wa-border); }
.wa[data-position="right"] .wa-panel { right: 0; border-left: 1px solid var(--wa-border); }
.wa-panel[hidden] { display: none; }
.wa-header {
  display: flex; align-items: center; gap: 8px; padding: 12px 16px;
  background: var(--wa-surface); border-bottom: 1px solid var(--wa-border);
}
.wa-logo { width: 36px; height: 36px; object-fit: contain; flex: 0 0 auto; }
.wa-title { flex: 1 1 auto; min-width: 0; margin: 0; font-size: 16px; line-height: 1.25; font-weight: 800; color: var(--wa-accent); overflow-wrap: anywhere; }
.wa-header button {
  min-height: 44px; min-width: 44px; padding: 0 12px; border-radius: var(--wa-radius);
  border: 1px solid var(--wa-border); background: var(--wa-surface); color: var(--wa-accent); font-weight: 700; font-size: 14px;
  white-space: nowrap; flex: 0 0 auto;
}
.wa-header .wa-close { background: var(--wa-accent); color: var(--wa-on-accent); }
.wa-header .wa-new span[aria-hidden] { font-size: 16px; }
.wa-notice { margin: 12px 16px 0; padding: 12px 14px; border: 1px solid var(--wa-highlight); border-radius: var(--wa-radius); background: var(--wa-surface); }
.wa-messages { list-style: none; margin: 0; padding: 20px 16px; overflow-y: auto; min-height: 0; }
.wa-message { display: flex; flex-direction: column; margin: 0 0 14px; max-width: 100%; }
.wa-message--user { align-items: flex-end; }
.wa-bubble {
  max-width: min(100%, 340px); padding: 12px 14px; border-radius: var(--wa-radius);
  border: 1px solid var(--wa-border); background: var(--wa-surface); white-space: pre-wrap; overflow-wrap: anywhere;
}
.wa-message--user .wa-bubble { border-color: var(--wa-accent); background: color-mix(in srgb, var(--wa-surface) 88%, var(--wa-accent)); }
.wa-message--error .wa-bubble { border-color: var(--wa-highlight); }
.wa-author { margin: 0 0 4px; font-size: 12px; font-weight: 700; color: var(--wa-text); }
.wa-loader { display: inline-flex; gap: 6px; align-items: center; min-height: 20px; }
.wa-loader span { width: 8px; height: 8px; border-radius: 999px; background: var(--wa-accent); animation: wa-bounce 1s ease-in-out infinite; }
.wa-loader span:nth-child(2) { animation-delay: .16s; background: var(--wa-highlight); }
.wa-loader span:nth-child(3) { animation-delay: .32s; }
@keyframes wa-bounce { 0%, 80%, 100% { transform: translateY(0); opacity: .45; } 40% { transform: translateY(-5px); opacity: 1; } }
.wa-status { padding: 0 16px 6px; min-height: 22px; font-size: 13px; color: var(--wa-text); }
.wa-suggestions { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 0; padding: 0; list-style: none; }
.wa-chip {
  min-height: 44px; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--wa-accent);
  background: var(--wa-surface); color: var(--wa-accent); font-size: 14px; font-weight: 700; text-align: left;
}
.wa-composer { display: grid; gap: 8px; padding: 12px 16px 14px; background: var(--wa-surface); border-top: 1px solid var(--wa-border); }
.wa-input {
  width: 100%; min-height: 84px; max-height: 40vh; resize: vertical; padding: 10px 12px;
  border: 1px solid #8a8580; border-radius: var(--wa-radius); background: var(--wa-surface); color: var(--wa-text);
}
.wa-input[aria-invalid="true"] { border-color: var(--wa-highlight); }
.wa-composer-row { display: flex; align-items: center; gap: 8px; }
.wa-label { font-size: 13px; font-weight: 700; color: var(--wa-text); }
.wa-counter { flex: 1 1 auto; font-size: 12px; }
.wa-send, .wa-stop, .wa-primary {
  min-height: 44px; padding: 0 18px; border: 0; border-radius: var(--wa-radius);
  background: var(--wa-accent); color: var(--wa-on-accent); font-weight: 700;
}
.wa-stop, .wa-secondary { background: var(--wa-surface); color: var(--wa-accent); border: 1px solid var(--wa-accent); min-height: 44px; padding: 0 16px; border-radius: var(--wa-radius); font-weight: 700; }
.wa-send:disabled { opacity: .6; cursor: not-allowed; }
.wa-privacy { margin: 0; font-size: 12px; color: var(--wa-text); }
.wa-model { margin: 0; padding: 0 16px 6px; font-size: 11px; font-style: italic; text-align: right; }

/* Resultados */
.wa-result { margin-top: 10px; width: 100%; }
.wa-card { background: var(--wa-surface); border: 1px solid var(--wa-border); border-radius: var(--wa-radius); padding: 14px; }
.wa-card h3, .wa-card h4 { margin: 0 0 8px; color: var(--wa-accent); font-size: 16px; line-height: 1.3; }
.wa-eyebrow { display: inline-block; margin: 0 0 8px; padding: 4px 10px; border-radius: 999px; background: color-mix(in srgb, var(--wa-surface) 90%, var(--wa-highlight)); color: var(--wa-accent); font-size: 12px; font-weight: 800; text-transform: uppercase; }
.wa-card p { margin: 0 0 8px; }
.wa-steps { margin: 8px 0; padding-left: 22px; }
.wa-steps li { margin-bottom: 6px; }
.wa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin: 10px 0; padding: 0; list-style: none; }
.wa-product { display: flex; flex-direction: column; border: 1px solid var(--wa-border); border-radius: var(--wa-radius); overflow: hidden; background: var(--wa-surface); }
.wa-product img { width: 100%; height: 120px; object-fit: contain; background: var(--wa-muted-surface); }
.wa-product-body { display: flex; flex-direction: column; gap: 6px; padding: 10px; flex: 1 1 auto; }
.wa-product-title { margin: 0; font-size: 15px; font-weight: 800; color: var(--wa-accent); }
.wa-product-title a { color: inherit; }
.wa-meta { font-size: 13px; }
.wa-price { font-weight: 800; font-size: 16px; }
.wa-product-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: auto; }
.wa-product-actions button { min-height: 44px; padding: 0 12px; border-radius: var(--wa-radius); border: 1px solid var(--wa-accent); background: var(--wa-accent); color: var(--wa-on-accent); font-weight: 700; font-size: 14px; }
.wa-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 14px; }
.wa-table th, .wa-table td { text-align: left; padding: 8px; border-bottom: 1px solid var(--wa-border); vertical-align: top; }
.wa-sources { margin: 10px 0 0; padding: 0; list-style: none; font-size: 14px; }
.wa-sources li { margin: 4px 0; }
.wa-quote { margin: 8px 0; padding: 8px 12px; border-left: 4px solid var(--wa-highlight); background: var(--wa-muted-surface); }
.wa-quote blockquote { margin: 0 0 6px; }
.wa-card dl { margin: 8px 0; }
.wa-card dd { margin: 0 0 6px; }
.wa-badge { display: inline-block; margin-left: 6px; padding: 0 6px; border-radius: 4px; background: var(--wa-accent); color: var(--wa-on-accent); font-size: 11px; }
a { color: var(--wa-accent); text-decoration: underline; text-underline-offset: 2px; }
.wa-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.wa-view-result { margin-top: 8px; }

/* Diálogo de confirmación */
.wa-backdrop { position: fixed; inset: 0; z-index: calc(var(--wa-z) + 1); background: rgba(38, 7, 23, .45); display: grid; place-items: center; padding: 16px; }
.wa-dialog { width: min(440px, 100%); background: var(--wa-surface); color: var(--wa-text); border-radius: var(--wa-radius); padding: 20px; box-shadow: 0 20px 48px rgba(0,0,0,.3); }
.wa-dialog h2 { margin: 0 0 10px; font-size: 18px; color: var(--wa-accent); }
.wa-dialog dl { margin: 10px 0; display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 14px; }
.wa-dialog dt { font-weight: 700; }
.wa-dialog dd { margin: 0; overflow-wrap: anywhere; }
.wa-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }

@media (max-width: 900px) {
  .wa-panel { width: 100vw; left: 0 !important; right: 0 !important; }
  .wa-launcher { top: 120px; }
  .wa-new-label { display: none; }
}
@media (prefers-reduced-motion: reduce) {
  .wa-panel { transition: none; }
  .wa-loader span { animation: none; opacity: 1; }
}
@media (forced-colors: active) {
  .wa-launcher, .wa-send, .wa-primary, .wa-product-actions button { border: 2px solid ButtonText; }
  :focus-visible { outline-color: Highlight; }
}
`;

/** Estilos del contenedor externo de resultados (modo data-result-target). */
export const RESULT_TARGET_CSS = `
:host { all: initial !important; display: block !important; }
*, *::before, *::after { box-sizing: border-box; }
.wa-result-host { font-family: var(--wa-font); color: var(--wa-text); line-height: 1.45; padding: 24px; }
.wa-result-host .wa-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
`;
