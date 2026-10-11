import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const SOURCE = readFileSync(
  path.resolve(import.meta.dirname, '../../webassistant_connector/js/webassistant-connector.js'),
  'utf8',
);

/** Implementación mínima de core/once para jsdom. */
function onceStub() {
  const seen = new Set();
  return (id, selector) => {
    if (seen.has(id)) return [];
    seen.add(id);
    return Array.from(document.querySelectorAll(selector));
  };
}

function settings(overrides = {}) {
  return {
    webassistantConnector: {
      loaderUrl: 'https://asistente.example/widget/v1/loader.js',
      apiBase: 'https://asistente.example',
      siteId: 'site_demo',
      tokenUrl: '/webassistant/token',
      csrfTokenUrl: '/session/token',
      hostActionUrl: '/webassistant/host-action/',
      hostActions: ['content.create'],
      dataLayer: true,
      instances: { default: { assistantId: 'portal', locale: 'es', open: false } },
      ...overrides,
    },
  };
}

/** Carga el behavior con unas globales de Drupal simuladas y lo adjunta. */
function attach(drupalSettings) {
  const Drupal = { behaviors: {}, t: (text) => text };
  window.Drupal = Drupal;
  window.drupalSettings = drupalSettings;
  window.once = onceStub();
  window.eval(SOURCE);
  Drupal.behaviors.webassistantConnector.attach(document, drupalSettings);
  return Drupal;
}

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('webassistant-connector.js', () => {
  beforeEach(() => {
    document.body.replaceChildren();
    delete window.WebAssistant;
    delete window.dataLayer;
    sessionStorage.clear();
    localStorage.clear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('inserta un único loader por instancia con atributos seguros', () => {
    const drupal = attach(settings());
    drupal.behaviors.webassistantConnector.attach(document, window.drupalSettings);
    const scripts = document.querySelectorAll('script[data-webassistant-instance]');
    expect(scripts).toHaveLength(1);
    const script = scripts[0];
    expect(script.getAttribute('src')).toBe('https://asistente.example/widget/v1/loader.js');
    expect(script.dataset.siteId).toBe('site_demo');
    expect(script.dataset.assistantId).toBe('portal');
    expect(script.dataset.locale).toBe('es');
    expect(script.dataset.webassistantInstance).toBe('site_demo:portal:panel');
    expect(script.async).toBe(true);
  });

  it('registra el proveedor de token por instancia y lo pide con CSRF', async () => {
    const fetchMock = vi.fn(async (url) =>
      url === '/session/token'
        ? new Response('csrf-123')
        : jsonResponse(200, { token: 'jwt-1', expires_at: new Date(Date.now() + 600000).toISOString() }),
    );
    vi.stubGlobal('fetch', fetchMock);
    attach(settings());
    const call = window.WebAssistant.find((item) => item[0] === 'setToken');
    expect(call[2]).toBe('site_demo:portal:panel');
    await expect(call[1]()).resolves.toBe('jwt-1');
    await expect(call[1]()).resolves.toBe('jwt-1');
    const tokenCalls = fetchMock.mock.calls.filter(([url]) => url === '/webassistant/token');
    expect(tokenCalls).toHaveLength(1);
    expect(tokenCalls[0][1].headers['X-CSRF-Token']).toBe('csrf-123');
    expect(sessionStorage.getItem('webassistant_connector.auth')).toBe('1');
  });

  it('vuelve a anónimo si Drupal rechaza el token y falla ante errores del servidor', async () => {
    let status = 403;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url) => (url === '/session/token' ? new Response('csrf') : jsonResponse(status, {}))),
    );
    attach(settings());
    const provider = window.WebAssistant.find((item) => item[0] === 'setToken')[1];
    await expect(provider()).resolves.toBe('');
    status = 503;
    await expect(provider()).rejects.toThrow(/HTTP 503/);
  });

  it('host action: cabeceras de seguridad, resultado y redirección solo al mismo origen', async () => {
    const fetchMock = vi.fn(async (url) =>
      url === '/session/token'
        ? new Response('csrf')
        : jsonResponse(200, { status: 'succeeded', message: 'Abriendo', redirect: 'https://evil.example/x' }),
    );
    vi.stubGlobal('fetch', fetchMock);
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({
      ...window.location,
      href: 'http://localhost/node/1',
      origin: 'http://localhost',
      assign,
    });
    attach(settings());
    const handler = window.WebAssistant.find((item) => item[0] === 'registerHostAction')[2];
    const result = await handler({ version: '1', name: 'content.create', params: { content_type: 'page' } });
    expect(result).toEqual({ status: 'succeeded', message: 'Abriendo' });
    const [url, init] = fetchMock.mock.calls.find(([target]) => String(target).startsWith('/webassistant/'));
    expect(url).toBe('/webassistant/host-action/content.create');
    expect(init.headers['X-WebAssistant-Request']).toBe('1');
    expect(init.headers['X-CSRF-Token']).toBe('csrf');
    expect(init.credentials).toBe('same-origin');
    window.WebAssistant.filter((item) => item[0] === 'on' && item[1] === 'action_result').forEach((item) =>
      item[2](),
    );
    expect(assign).not.toHaveBeenCalled();
  });

  it('sigue la redirección interna cuando el widget ha informado del resultado', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url) =>
        url === '/session/token'
          ? new Response('csrf')
          : jsonResponse(200, { status: 'succeeded', redirect: '/node/add/page' }),
      ),
    );
    const assign = vi.fn();
    vi.spyOn(window, 'location', 'get').mockReturnValue({
      ...window.location,
      href: 'http://localhost/node/1',
      origin: 'http://localhost',
      assign,
    });
    attach(settings());
    const handler = window.WebAssistant.find((item) => item[0] === 'registerHostAction')[2];
    await expect(handler({ name: 'content.create' })).resolves.toEqual({ status: 'succeeded' });
    expect(assign).not.toHaveBeenCalled();
    window.WebAssistant.filter((item) => item[0] === 'on' && item[1] === 'action_result').forEach((item) =>
      item[2](),
    );
    expect(assign).toHaveBeenCalledWith('http://localhost/node/add/page');
  });

  it('una respuesta no 2xx de Drupal se informa como fallida', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url) => (url === '/session/token' ? new Response('csrf') : jsonResponse(403, {}))),
    );
    attach(settings());
    const handler = window.WebAssistant.find((item) => item[0] === 'registerHostAction')[2];
    await expect(handler({ name: 'content.create' })).resolves.toMatchObject({ status: 'failed' });
  });

  it('tras cerrar sesión borra los datos del widget del navegador', () => {
    sessionStorage.setItem('webassistant_connector.auth', '1');
    sessionStorage.setItem('wa:site_demo:portal:panel:conversation', 'conv_1');
    localStorage.setItem('wa:site_demo:portal:panel:anon', 'anon');
    localStorage.setItem('otra:clave', 'se conserva');
    attach(settings({ tokenUrl: null }));
    expect(sessionStorage.getItem('wa:site_demo:portal:panel:conversation')).toBeNull();
    expect(localStorage.getItem('wa:site_demo:portal:panel:anon')).toBeNull();
    expect(localStorage.getItem('otra:clave')).toBe('se conserva');
    expect(window.WebAssistant.some((item) => item[0] === 'setToken')).toBe(false);
  });

  it('publica eventos en dataLayer sin parámetros ni datos personales', () => {
    attach(settings());
    const listener = window.WebAssistant.find((item) => item[0] === 'on' && item[1] === '*')[2];
    listener({
      type: 'action_requested',
      instance_id: 'site_demo:portal:panel',
      payload: { skill: 'content_create', action: 'open_form', params: { email: 'x@example.com' } },
    });
    expect(window.dataLayer).toEqual([
      {
        event: 'webassistant_action_requested',
        webassistant: {
          type: 'action_requested',
          instance_id: 'site_demo:portal:panel',
          skill: 'content_create',
          action: 'open_form',
        },
      },
    ]);
  });
});
