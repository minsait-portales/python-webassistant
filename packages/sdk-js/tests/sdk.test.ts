import { describe, expect, it, vi } from 'vitest';

import { AdminClient, ApiError, PortalClient, SseParser, WebAssistantClient } from '../src';

function sseResponse(chunks: string[]): Response {
  const encoder = new TextEncoder();
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      for (const chunk of chunks) controller.enqueue(encoder.encode(chunk));
      controller.close();
    },
  });
  return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
}

describe('SseParser', () => {
  it('reconstruye eventos partidos entre trozos, con CRLF, comentarios e ids', () => {
    const parser = new SseParser();
    expect(parser.feed('retry: 2000\n\n: comentario\nid: 1\nevent: run.sta')).toEqual([]);
    const messages = parser.feed('tus\r\ndata: {"a":\r\ndata: 1}\r\n\r\nid: 2\ndata: x\n\n');
    expect(messages).toEqual([
      { id: '1', event: 'run.status', data: '{"a":\n1}' },
      { id: '2', event: 'message', data: 'x' },
    ]);
  });
});

describe('WebAssistantClient', () => {
  it('emite eventos del stream hasta el terminal y usa el token', async () => {
    const fetchMock = vi.fn(async () =>
      sseResponse([
        'retry: 2000\n\n',
        'id: 1\nevent: run.started\ndata: {"type":"run.started","run_id":"run_1","seq":1}\n\n',
        'id: 2\nevent: reply.delta\ndata: {"type":"reply.delta","run_id":"run_1","seq":2,"delta":"Hola"}\n\nid: 3\nevent: run.completed\n',
        'data: {"type":"run.completed","run_id":"run_1","seq":3,"response":{"reply":"Hola"}}\n\n',
        'id: 4\ndata: {"type":"heartbeat","run_id":"run_1","seq":4}\n\n',
      ]),
    );
    const client = new WebAssistantClient({
      baseUrl: 'https://api.example.com/',
      token: 'tok',
      fetch: fetchMock,
    });
    const events = [];
    for await (const event of client.streamMessage('conv_1', 'Hola')) events.push(event.type);
    expect(events).toEqual(['run.started', 'reply.delta', 'run.completed']);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://api.example.com/v1/conversations/conv_1/messages');
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok');
    expect((init.headers as Record<string, string>).Accept).toBe('text/event-stream');
  });

  it('reanuda un run con Last-Event-ID', async () => {
    const fetchMock = vi.fn(async () =>
      sseResponse(['id: 5\ndata: {"type":"run.completed","run_id":"run_1","seq":5}\n\n']),
    );
    const client = new WebAssistantClient({
      baseUrl: 'https://api.example.com',
      token: 'tok',
      fetch: fetchMock,
    });
    const events = [];
    for await (const event of client.resumeRun('conv_1', 'run_1', 4)) events.push(event.seq);
    expect(events).toEqual([5]);
    const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1];
    expect((init.headers as Record<string, string>)['Last-Event-ID']).toBe('4');
  });

  it('convierte problem+json en ApiError con mensaje para el usuario', async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            type: 'x',
            title: 'Límite',
            status: 429,
            code: 'rate_limited',
            retryable: true,
            user_message: 'Espera un momento.',
          }),
          {
            status: 429,
            headers: { 'Content-Type': 'application/problem+json' },
          },
        ),
    );
    const client = new WebAssistantClient({
      baseUrl: 'https://api.example.com',
      token: 'tok',
      fetch: fetchMock,
    });
    const error = await client.sendMessage('conv_1', 'hola').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).code).toBe('rate_limited');
    expect((error as ApiError).retryable).toBe(true);
    expect((error as ApiError).userMessage).toBe('Espera un momento.');
  });

  it('renueva el token una vez ante un 401', async () => {
    let calls = 0;
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      calls += 1;
      const auth = (init?.headers as Record<string, string>).Authorization;
      if (auth === 'Bearer viejo')
        return new Response(JSON.stringify({ type: 'x', title: 'No', status: 401, code: 'unauthorized' }), {
          status: 401,
        });
      return new Response(JSON.stringify({ items: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    });
    const tokens = ['viejo', 'nuevo'];
    const client = new WebAssistantClient({
      baseUrl: 'https://api.example.com',
      token: async () => tokens.shift()!,
      fetch: fetchMock,
    });
    await expect(client.listConversations()).resolves.toEqual({ items: [] });
    expect(calls).toBe(2);
  });
});

describe('PortalClient', () => {
  it('obtiene un token de servidor y lo reutiliza', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (url.endsWith('/v1/token')) {
        return new Response(
          JSON.stringify({
            access_token: 'srv',
            expires_in: 600,
            token_type: 'Bearer',
            expires_at: '',
            scopes: [],
          }),
          { status: 200 },
        );
      }
      return new Response(JSON.stringify({ items: [] }), { status: 200 });
    });
    const portal = new PortalClient({
      baseUrl: 'https://api.example.com',
      clientId: 'id',
      clientSecret: 'secret',
      fetch: fetchMock,
    });
    await portal.search({ query: 'barbacoa' } as never);
    await portal.search({ query: 'carbón' } as never);
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/v1/token'))).toHaveLength(1);
  });
});

describe('AdminClient', () => {
  it('guarda el token tras el login', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.endsWith('/login'))
        return new Response(JSON.stringify({ access_token: 'adm', expires_at: '', user: {} }), {
          status: 200,
        });
      return new Response(JSON.stringify({ auth: (init?.headers as Record<string, string>).Authorization }), {
        status: 200,
      });
    });
    const admin = new AdminClient({ baseUrl: 'https://api.example.com', fetch: fetchMock });
    await admin.login('a@b.c', 'x');
    await expect(admin.get('/v1/admin/auth/me')).resolves.toEqual({ auth: 'Bearer adm' });
  });
});
