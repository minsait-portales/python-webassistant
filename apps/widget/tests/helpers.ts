import type { HostActionHandler, PublicApi, QueuedCall, WebAssistantGlobal } from '../src/shared';

/** `window.WebAssistant` mínimo para montar el chat en pruebas. */
export function testGlobal(): WebAssistantGlobal & {
  events: Array<{ type: string; payload: Record<string, unknown> }>;
} {
  const listeners = new Map<string, Set<(event: unknown) => void>>();
  const events: Array<{ type: string; payload: Record<string, unknown> }> = [];
  listeners.set(
    '*',
    new Set([(event) => events.push(event as { type: string; payload: Record<string, unknown> })]),
  );
  return {
    __loader: 'test',
    instances: new Map<string, PublicApi>(),
    listeners,
    hostActions: new Map<string, HostActionHandler>(),
    pending: [] as QueuedCall[],
    events,
    on(type, handler) {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type)!.add(handler as (event: unknown) => void);
      return () => listeners.get(type)?.delete(handler as (event: unknown) => void);
    },
    registerHostAction(name, handler) {
      this.hostActions.set(name, handler);
    },
    setToken: () => undefined,
    open: () => undefined,
    close: () => undefined,
    push: (...calls: QueuedCall[]) => calls.length,
    send: () => undefined,
  };
}

export function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json' },
  });
}

export async function waitFor(check: () => boolean, timeout = 2000): Promise<void> {
  const start = Date.now();
  while (!check()) {
    if (Date.now() - start > timeout) throw new Error('waitFor: tiempo agotado');
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}
