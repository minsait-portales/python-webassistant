import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';

import type { WebAssistantGlobal } from '../src/shared';

type TestWindow = Omit<Window, 'WebAssistantWidget'> & {
  WebAssistant?: unknown;
  WebAssistantWidget?: unknown;
  __webassistantBundle?: Promise<void>;
};

function addScript(attrs: Record<string, string>): HTMLScriptElement {
  const script = document.createElement('script');
  script.type = 'text/plain'; // jsdom no debe intentar cargarlo
  script.setAttribute('src', 'http://localhost:8000/widget/v1/loader.js');
  for (const [name, value] of Object.entries(attrs)) script.setAttribute(name, value);
  document.head.appendChild(script);
  return script;
}

async function loadLoader(): Promise<void> {
  vi.resetModules();
  await import('../src/loader');
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('loader.js', () => {
  const api = { setToken: vi.fn(), open: vi.fn(), close: vi.fn(), send: vi.fn(async () => undefined) };
  let mount: Mock<(options: unknown, global: unknown) => typeof api>;

  beforeEach(() => {
    const win = window as TestWindow;
    delete win.WebAssistant;
    win.__webassistantBundle = Promise.resolve();
    mount = vi.fn(() => api);
    win.WebAssistantWidget = { mount };
    document.head.replaceChildren();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('monta una sola vez aunque el script se cargue dos veces', async () => {
    addScript({ 'data-site-id': 'site_demo', 'data-assistant-id': 'catalogo' });
    await loadLoader();
    await loadLoader();
    expect(mount).toHaveBeenCalledTimes(1);
    const options = mount.mock.calls[0]![0] as Record<string, unknown>;
    expect(options).toMatchObject({
      siteId: 'site_demo',
      assistantId: 'catalogo',
      apiBase: 'http://localhost:8000',
      instanceId: 'site_demo:catalogo:panel',
    });
  });

  it('rechaza atributos no válidos sin montar nada', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    addScript({ 'data-site-id': 'Site con espacios<script>' });
    await loadLoader();
    expect(mount).not.toHaveBeenCalled();
    expect(error).toHaveBeenCalled();
  });

  it('procesa la cola previa: host actions, eventos y llamadas pendientes', async () => {
    const handler = vi.fn();
    const listener = vi.fn();
    (window as TestWindow).WebAssistant = [
      ['registerHostAction', 'cart.get', handler],
      ['on', 'ready', listener],
      ['open'],
    ];
    addScript({ 'data-site-id': 'site_demo' });
    await loadLoader();
    const global = (window as TestWindow).WebAssistant as WebAssistantGlobal;
    expect(global.hostActions.get('cart.get')).toBe(handler);
    expect(global.listeners.get('ready')?.has(listener)).toBe(true);
    expect(api.open).toHaveBeenCalledTimes(1);
    global.send('hola');
    expect(api.send).toHaveBeenCalledWith('hola');
  });

  it('mantiene push() tras cargar para integraciones que usan la cola', async () => {
    addScript({ 'data-site-id': 'site_demo' });
    await loadLoader();
    const global = (window as TestWindow).WebAssistant as WebAssistantGlobal;
    const handler = vi.fn();
    const token = vi.fn(async () => 'jwt');
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    expect(global.push(['registerHostAction', 'cart.add', handler], ['setToken', token], ['__proto__'])).toBe(
      3,
    );
    expect(global.hostActions.get('cart.add')).toBe(handler);
    expect(api.setToken).toHaveBeenCalledWith(token);
    expect(error).toHaveBeenCalledTimes(1);
  });

  it('permite varias instancias con distinto contenedor de resultados', async () => {
    addScript({ 'data-site-id': 'site_demo' });
    await loadLoader();
    addScript({ 'data-site-id': 'site_demo', 'data-result-target': '#resultados' });
    await loadLoader();
    expect(mount).toHaveBeenCalledTimes(2);
    expect((mount.mock.calls[1]![0] as { instanceId: string }).instanceId).toBe(
      'site_demo:default:#resultados',
    );
  });
});
