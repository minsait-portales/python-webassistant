/**
 * Sesión del widget: token temporal (anónimo o del portal) con renovación y
 * persistencia mínima en almacenamiento del navegador (sin secretos).
 */
import { WebAssistantClient } from '@webassistant/sdk';

import type { TokenInput } from '../shared';

function storage(kind: 'local' | 'session'): Storage | null {
  try {
    const store = kind === 'local' ? window.localStorage : window.sessionStorage;
    const probe = '__wa_probe__';
    store.setItem(probe, '1');
    store.removeItem(probe);
    return store;
  } catch {
    return null;
  }
}

export class WidgetStore {
  private readonly local = storage('local');
  private readonly session = storage('session');

  constructor(private readonly prefix: string) {}

  get anonymousSession(): string | null {
    return this.local?.getItem(`${this.prefix}:anon`) ?? null;
  }

  set anonymousSession(value: string | null) {
    if (!this.local) return;
    if (value) this.local.setItem(`${this.prefix}:anon`, value);
    else this.local.removeItem(`${this.prefix}:anon`);
  }

  get conversationId(): string | null {
    return this.session?.getItem(`${this.prefix}:conversation`) ?? null;
  }

  set conversationId(value: string | null) {
    if (!this.session) return;
    if (value) this.session.setItem(`${this.prefix}:conversation`, value);
    else this.session.removeItem(`${this.prefix}:conversation`);
  }
}

export class SessionManager {
  private token: { value: string; expiresAt: number } | null = null;
  private external: TokenInput | null = null;
  private inflight: Promise<string> | null = null;

  constructor(
    private readonly client: WebAssistantClient,
    private readonly store: WidgetStore,
    private readonly siteId: string,
    private readonly assistantId: string | undefined,
    private readonly locale: string | undefined,
  ) {}

  setExternalToken(token: TokenInput | null): void {
    this.external = token;
    this.token = null;
    this.client.invalidateToken();
  }

  /** Proveedor de token para el SDK: renueva antes de caducar. */
  readonly provider = async (): Promise<string> => {
    if (this.token && this.token.expiresAt - Date.now() > 30_000) return this.token.value;
    if (this.inflight) return this.inflight;
    this.inflight = this.obtain().finally(() => {
      this.inflight = null;
    });
    return this.inflight;
  };

  private async obtain(): Promise<string> {
    let hostToken: string | undefined;
    if (this.external) {
      hostToken = typeof this.external === 'string' ? this.external : await this.external();
    }
    const response = await this.client.createSession({
      site_id: this.siteId,
      assistant_id: this.assistantId,
      locale: this.locale,
      anonymous_session: this.store.anonymousSession ?? undefined,
      host_token: hostToken,
    });
    if (response.anonymous_session) this.store.anonymousSession = response.anonymous_session;
    this.token = { value: response.access_token, expiresAt: Date.now() + response.expires_in * 1000 };
    return response.access_token;
  }
}
