/**
 * loader.js de WebAssistant (CG-10.1).
 *
 * Instalación en la web anfitriona:
 *
 *   <script async src="https://assistant.example.com/widget/v1/loader.js"
 *     data-site-id="site_public_id" data-assistant-id="catalogo"></script>
 *
 * - Sin dependencias globales salvo `window.WebAssistant` (API pública con cola).
 * - Valida atributos y evita dobles montajes aunque el script se cargue dos veces.
 * - Carga el bundle versionado del widget (hash + SRI) que monta el chat en la página.
 */
import type { WebAssistantGlobal, LoaderOptions, PublicApi, HostActionHandler, QueuedCall } from './shared';

declare const __WIDGET_ENTRY__: string;
declare const __WIDGET_INTEGRITY__: string;
declare const __WIDGET_VERSION__: string;

const ID_PATTERN = /^[a-z][a-z0-9_-]{1,63}$/;

function readOptions(script: HTMLScriptElement): LoaderOptions | null {
  const data = script.dataset;
  const siteId = (data.siteId || '').trim();
  if (!ID_PATTERN.test(siteId)) {
    console.error('[WebAssistant] data-site-id no válido o ausente.');
    return null;
  }
  const assistantId = (data.assistantId || '').trim();
  if (assistantId && !ID_PATTERN.test(assistantId)) {
    console.error('[WebAssistant] data-assistant-id no válido.');
    return null;
  }
  const scriptUrl = new URL(script.src, document.baseURI);
  const apiBase = (data.apiBase || scriptUrl.origin).replace(/\/+$/, '');
  if (!/^https?:\/\//.test(apiBase)) {
    console.error('[WebAssistant] data-api-base no válido.');
    return null;
  }
  const locale = (data.locale || document.documentElement.lang || '').slice(0, 10) || undefined;
  return {
    siteId,
    assistantId: assistantId || undefined,
    apiBase,
    locale,
    resultTarget: data.resultTarget || undefined,
    open: data.open === 'true',
    token: data.token || undefined,
    position: data.position === 'right' ? 'right' : data.position === 'left' ? 'left' : undefined,
  };
}

function createGlobal(): WebAssistantGlobal {
  const existing = (window as unknown as { WebAssistant?: WebAssistantGlobal | QueuedCall[] }).WebAssistant;
  const queued: QueuedCall[] = Array.isArray(existing) ? existing : [];
  if (existing && !Array.isArray(existing) && existing.__loader) return existing;
  const listeners = new Map<string, Set<(event: unknown) => void>>();
  const hostActions = new Map<string, HostActionHandler>();
  const instances = new Map<string, PublicApi>();
  const pending: QueuedCall[] = [...queued];

  const global: WebAssistantGlobal = {
    __loader: __WIDGET_VERSION__,
    instances,
    listeners,
    hostActions,
    pending,
    on(type, handler) {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type)!.add(handler as (event: unknown) => void);
      return () => listeners.get(type)?.delete(handler as (event: unknown) => void);
    },
    registerHostAction(name, handler) {
      hostActions.set(name, handler);
    },
    setToken(token, instanceId) {
      const target = instanceId ? instances.get(instanceId) : instances.values().next().value;
      if (target) target.setToken(token);
      else pending.push(['setToken', token, instanceId]);
    },
    open(instanceId) {
      const target = instanceId ? instances.get(instanceId) : instances.values().next().value;
      if (target) target.open();
      else pending.push(['open', instanceId]);
    },
    close(instanceId) {
      const target = instanceId ? instances.get(instanceId) : instances.values().next().value;
      target?.close();
    },
    send(text, instanceId) {
      const target = instanceId ? instances.get(instanceId) : instances.values().next().value;
      if (target) void target.send(text);
      else pending.push(['send', text, instanceId]);
    },
  };
  for (const call of queued) {
    if (call[0] === 'registerHostAction')
      global.registerHostAction(call[1] as string, call[2] as HostActionHandler);
    if (call[0] === 'on') global.on(call[1] as never, call[2] as never);
  }
  (window as unknown as { WebAssistant: WebAssistantGlobal }).WebAssistant = global;
  return global;
}

function loadBundle(apiBase: string): Promise<void> {
  const flag = window as unknown as { __webassistantBundle?: Promise<void> };
  if (flag.__webassistantBundle) return flag.__webassistantBundle;
  flag.__webassistantBundle = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `${apiBase}/widget/v1/${__WIDGET_ENTRY__}`;
    script.async = true;
    script.crossOrigin = 'anonymous';
    if (__WIDGET_INTEGRITY__) script.integrity = __WIDGET_INTEGRITY__;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('No se pudo cargar el widget de WebAssistant'));
    document.head.appendChild(script);
  });
  return flag.__webassistantBundle;
}

function boot(): void {
  const script =
    (document.currentScript as HTMLScriptElement | null) ||
    (document.querySelector(
      'script[data-site-id][src*="loader.js"]:not([data-webassistant-mounted="true"])',
    ) as HTMLScriptElement | null);
  if (!script) return;
  const options = readOptions(script);
  if (!options) return;
  const global = createGlobal();
  const instanceId = `${options.siteId}:${options.assistantId || 'default'}:${options.resultTarget || 'panel'}`;
  if (global.instances.has(instanceId) || script.dataset.webassistantMounted === 'true') {
    return; // Doble carga del script: no se duplica el chat.
  }
  script.dataset.webassistantMounted = 'true';
  global.instances.set(instanceId, {
    setToken: (token) => global.pending.push(['setToken', token, instanceId]),
    open: () => global.pending.push(['open', instanceId]),
    close: () => undefined,
    send: async (text) => {
      global.pending.push(['send', text, instanceId]);
    },
  });
  const start = () =>
    loadBundle(options.apiBase)
      .then(() => {
        const mount = (
          window as unknown as {
            WebAssistantWidget?: {
              mount: (o: LoaderOptions & { instanceId: string }, g: WebAssistantGlobal) => PublicApi;
            };
          }
        ).WebAssistantWidget;
        if (!mount) throw new Error('Bundle del widget no disponible');
        const api = mount.mount({ ...options, instanceId }, global);
        global.instances.set(instanceId, api);
        const replay = global.pending.splice(0);
        for (const call of replay) {
          const [name, value, target] = call;
          if (target && target !== instanceId) {
            global.pending.push(call);
            continue;
          }
          if (name === 'setToken') api.setToken(value as never);
          if (name === 'open') api.open();
          if (name === 'send') void api.send(value as string);
        }
      })
      .catch((error: unknown) => {
        global.instances.delete(instanceId);
        script.dataset.webassistantMounted = 'false';
        console.error('[WebAssistant]', error);
      });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}

boot();
