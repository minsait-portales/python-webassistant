import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ChatWidget } from '../src/widget/chat';
import { json, testGlobal, waitFor } from './helpers';

const BOOTSTRAP = {
  api_version: '1',
  site: { id: 'site_demo', name: 'Demo' },
  assistant: { id: 'catalogo', name: 'Asistente de catálogo', version: 1 },
  locale: 'es',
  locales: ['es', 'en'],
  texts: {
    welcome: 'Bienvenido',
    input_placeholder: 'Escribe tu consulta...',
    privacy_notice: 'No incluyas datos personales.',
    launcher_label: 'WebAssistant AI',
    login_required: 'Inicia sesión',
    access_denied: 'Sin permisos',
    new_chat: 'Nuevo chat',
    send: 'Enviar',
    close: 'Cerrar chat',
  },
  theme: { accent: '#480e2a', position: 'left' },
  capabilities: {},
  suggestions: ['Organiza una barbacoa'],
  limits: { max_message_length: 800 },
  auth: { mode: 'public' },
  endpoints: {},
  layout: {},
};

const CART_RESPONSE = {
  api_version: '1',
  conversation_id: 'conv_1',
  run_id: 'run_1',
  skill: 'shopping_cart_view',
  reply: 'Te muestro el carrito <img src=x onerror=alert(1)>',
  presentation: { type: 'cart', version: '1', data: { intro: 'Tu carrito', items: [] } },
  actions: [
    {
      name: 'get_cart',
      skill: 'shopping_cart_view',
      type: 'host',
      label: 'Cargar carrito',
      confirmation: 'none',
      auto: true,
      target: 'presentation',
      params: {},
    },
  ],
  citations: [],
};

type Handler = (url: URL, init: RequestInit) => Response | Promise<Response>;

function mockApi(routes: Record<string, Handler>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async (input: string, init: RequestInit = {}) => {
    const url = new URL(input);
    const key = `${init.method ?? 'GET'} ${url.pathname}`;
    const handler = routes[key];
    if (!handler)
      return json({ type: 'about:blank', title: 'Not found', status: 404, code: 'not_found' }, 404);
    return handler(url, init);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

const BASE_ROUTES: Record<string, Handler> = {
  'GET /v1/bootstrap': () => json(BOOTSTRAP),
  'POST /v1/sessions': () =>
    json({
      access_token: 'tok',
      token_type: 'Bearer',
      expires_in: 900,
      expires_at: '',
      subject_type: 'anonymous',
      scopes: [],
      anonymous_session: 'anon',
    }),
  'POST /v1/conversations': () =>
    json({
      conversation_id: 'conv_1',
      assistant_id: 'catalogo',
      status: 'active',
      locale: 'es',
      title: '',
      created_at: '',
      last_message_at: null,
    }),
};

function shadow(): ShadowRoot {
  return document.querySelector('webassistant-chat')!.shadowRoot!;
}

describe('Chat', () => {
  beforeEach(() => {
    document.body.replaceChildren();
    localStorage.clear();
    sessionStorage.clear();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('monta el chat accesible con los textos del bootstrap', async () => {
    mockApi(BASE_ROUTES);
    const global = testGlobal();
    const widget = new ChatWidget(
      { siteId: 'site_demo', apiBase: 'http://localhost:8000', instanceId: 'i1' },
      global,
    );
    await widget.start();
    const root = shadow();
    const launcher = root.querySelector('.wa-launcher')!;
    expect(launcher.textContent).toBe('WebAssistant AI');
    expect(launcher.getAttribute('aria-expanded')).toBe('false');
    const panel = root.querySelector('.wa-panel')!;
    expect(panel.getAttribute('role')).toBe('dialog');
    expect(panel.hasAttribute('hidden')).toBe(true);
    (launcher as HTMLButtonElement).click();
    expect(panel.hasAttribute('hidden')).toBe(false);
    expect(launcher.getAttribute('aria-expanded')).toBe('true');
    const input = root.querySelector('textarea')!;
    expect(root.querySelector(`label[for="${input.id}"]`)?.textContent).toBe('Escribe tu consulta');
    expect(root.activeElement).toBe(input);
    expect(root.textContent).toContain('Bienvenido');
    expect(root.querySelector('.wa-chip')?.textContent).toBe('Organiza una barbacoa');
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(panel.hasAttribute('hidden')).toBe(true);
    expect(root.activeElement).toBe(launcher);
    expect(global.events.map((e) => e.type)).toEqual(expect.arrayContaining(['ready', 'open', 'close']));
  });

  it('envía, muestra la respuesta como texto y ejecuta la host action automática', async () => {
    const prepared = {
      execution_id: 'exe_1',
      skill: 'shopping_cart_view',
      action: 'get_cart',
      type: 'host',
      status: 'proposed',
      requires_confirmation: false,
      params: {},
      run_id: 'run_1',
      result: null,
      expires_at: null,
    };
    const fetchMock = mockApi({
      ...BASE_ROUTES,
      'POST /v1/conversations/conv_1/messages': () => json(CART_RESPONSE),
      'POST /v1/actions/prepare': () => json(prepared),
      'POST /v1/actions/exe_1/execute': () =>
        json({
          ...prepared,
          status: 'delegated',
          host_action: {
            version: '1',
            name: 'cart.get',
            execution_id: 'exe_1',
            correlation_id: 'corr_1',
            params: {},
          },
        }),
      'POST /v1/actions/exe_1/result': () => json({ ...prepared, status: 'succeeded' }),
    });
    const global = testGlobal();
    global.registerHostAction('cart.get', () => ({
      data: {
        items: [{ product_id: 'p-1', title: 'Zapatilla Ligera', price: '79,95 €', quantity: 2 }],
        total: '159,90 €',
      },
    }));
    const widget = new ChatWidget(
      { siteId: 'site_demo', apiBase: 'http://localhost:8000', instanceId: 'i2' },
      global,
    );
    await widget.start();
    await widget.send('Enséñame el carrito');
    const root = shadow();
    await waitFor(() => (root.textContent ?? '').includes('Zapatilla Ligera'));
    expect(root.querySelector('.wa-bubble img')).toBeNull();
    expect(root.textContent).toContain('Te muestro el carrito <img src=x onerror=alert(1)>');
    expect(root.textContent).toContain('159,90 €');
    const reported = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/v1/actions/exe_1/result'));
    expect(JSON.parse(String(reported![1].body))).toMatchObject({ status: 'succeeded' });
    const execute = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/execute'));
    expect((execute![1].headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
    expect((execute![1].headers as Record<string, string>).Authorization).toBe('Bearer tok');
    expect(global.events.map((e) => e.type)).toEqual(
      expect.arrayContaining([
        'message_sent',
        'response',
        'presentation',
        'action_requested',
        'host_action',
        'action_result',
      ]),
    );
    const sent = global.events.find((e) => e.type === 'message_sent')!;
    expect(sent.payload).toEqual({ length: 'Enséñame el carrito'.length });
    expect(sessionStorage.getItem('wa:i2:conversation')).toBe('conv_1');
    expect(localStorage.getItem('wa:i2:anon')).toBe('anon');
  });

  it('muestra el mensaje de error del servidor sin perder el chat', async () => {
    mockApi({
      ...BASE_ROUTES,
      'POST /v1/conversations/conv_1/messages': () =>
        json(
          {
            type: 'about:blank',
            title: 'Too many',
            status: 429,
            code: 'rate_limited',
            retryable: true,
            user_message: 'Has enviado demasiadas consultas.',
          },
          429,
        ),
    });
    const global = testGlobal();
    const widget = new ChatWidget(
      { siteId: 'site_demo', apiBase: 'http://localhost:8000', instanceId: 'i3' },
      global,
    );
    await widget.start();
    await widget.send('Hola');
    const root = shadow();
    expect(root.querySelector('.wa-message--error')?.textContent).toContain(
      'Has enviado demasiadas consultas.',
    );
    expect((root.querySelector('.wa-send') as HTMLButtonElement).disabled).toBe(false);
    expect(global.events.find((e) => e.type === 'error')?.payload).toEqual({
      stage: 'message',
      code: 'rate_limited',
    });
  });

  it('no muestra el chat si el site no está autorizado', async () => {
    mockApi({
      'GET /v1/bootstrap': () =>
        json({ type: 'about:blank', title: 'Forbidden', status: 403, code: 'origin_not_allowed' }, 403),
    });
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const global = testGlobal();
    const widget = new ChatWidget(
      { siteId: 'site_x', apiBase: 'http://localhost:8000', instanceId: 'i4' },
      global,
    );
    await widget.start();
    expect(document.querySelector('webassistant-chat')).toBeNull();
    expect(global.events.at(-1)).toMatchObject({
      type: 'error',
      payload: { stage: 'bootstrap', code: 'origin_not_allowed' },
    });
  });

  it('restaura el historial de la conversación guardada', async () => {
    sessionStorage.setItem('wa:i5:conversation', 'conv_9');
    mockApi({
      ...BASE_ROUTES,
      'GET /v1/conversations/conv_9': () =>
        json({
          conversation_id: 'conv_9',
          assistant_id: 'catalogo',
          status: 'active',
          locale: 'es',
          title: '',
          created_at: '',
          last_message_at: null,
          messages: [
            {
              message_id: 'm1',
              role: 'user',
              content: '¿Cómo devuelvo un pedido?',
              run_id: 'run_1',
              created_at: '',
            },
            {
              message_id: 'm2',
              role: 'assistant',
              content: 'Desde Mis pedidos.',
              run_id: 'run_1',
              created_at: '',
              presentation: { type: 'answer', version: '1', data: { answer: 'Paso a paso' } },
            },
          ],
        }),
    });
    const widget = new ChatWidget(
      { siteId: 'site_demo', apiBase: 'http://localhost:8000', instanceId: 'i5', open: true },
      testGlobal(),
    );
    await widget.start();
    const root = shadow();
    await waitFor(() => (root.textContent ?? '').includes('Paso a paso'));
    expect(root.querySelectorAll('.wa-message--user')).toHaveLength(1);
    expect(root.textContent).not.toContain('Bienvenido');
  });
});
