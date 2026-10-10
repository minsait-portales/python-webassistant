/**
 * Bundle del widget (cargado por loader.js con SRI). Expone `window.WebAssistantWidget`
 * para que el loader monte instancias y para registrar renderers propios del portal.
 */
import type { MountOptions, PublicApi, WebAssistantGlobal } from './shared';
import { ChatWidget } from './widget/chat';
import { hasRenderer, registerRenderer } from './widget/renderers';

declare const __WIDGET_VERSION__: string;

export function mount(options: MountOptions, global: WebAssistantGlobal): PublicApi {
  const widget = new ChatWidget(options, global);
  void widget.start();
  return widget;
}

const api = { mount, registerRenderer, hasRenderer, version: __WIDGET_VERSION__ };

declare global {
  interface Window {
    WebAssistantWidget?: typeof api;
  }
}

window.WebAssistantWidget = api;
