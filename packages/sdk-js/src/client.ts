import { readSse } from './sse';
import type {
  ActionExecution,
  BootstrapResponse,
  ChatResponse,
  ConversationHistory,
  ConversationView,
  Problem,
  SearchBody,
  SessionResponse,
  StreamEvent,
  TokenRequest,
  TokenResponse,
} from './types';

export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

export class ApiError extends Error {
  readonly status: number;
  readonly problem: Problem;

  constructor(problem: Problem) {
    super(problem.detail || problem.title || problem.code);
    this.name = 'ApiError';
    this.status = problem.status;
    this.problem = problem;
  }

  get code(): string {
    return this.problem.code;
  }

  get userMessage(): string {
    return this.problem.user_message || 'Se produjo un error. Inténtalo de nuevo.';
  }

  get retryable(): boolean {
    return Boolean(this.problem.retryable);
  }
}

interface BaseOptions {
  baseUrl: string;
  fetch?: FetchLike;
}

function joinUrl(base: string, path: string): string {
  return `${base.replace(/\/+$/, '')}${path}`;
}

async function toProblem(response: Response): Promise<Problem> {
  try {
    const body = (await response.json()) as Partial<Problem>;
    if (body && typeof body.code === 'string') return body as Problem;
  } catch {
    /* respuesta sin JSON */
  }
  return {
    type: 'about:blank',
    title: response.statusText || 'Error',
    status: response.status,
    code: response.status >= 500 ? 'service_unavailable' : 'internal_error',
    retryable: response.status >= 500,
  };
}

class HttpClient {
  protected readonly baseUrl: string;
  protected readonly fetchImpl: FetchLike;

  constructor(options: BaseOptions) {
    this.baseUrl = options.baseUrl;
    this.fetchImpl = options.fetch ?? ((input, init) => globalThis.fetch(input, init));
  }

  protected headers(extra?: Record<string, string>): Record<string, string> {
    return { Accept: 'application/json', ...extra };
  }

  protected async request<T>(
    method: string,
    path: string,
    body?: unknown,
    extra?: Record<string, string>,
    signal?: AbortSignal,
  ): Promise<T> {
    const response = await this.fetchImpl(joinUrl(this.baseUrl, path), {
      method,
      headers: this.headers(body === undefined ? extra : { 'Content-Type': 'application/json', ...extra }),
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    });
    if (!response.ok) throw new ApiError(await toProblem(response));
    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
  }
}

/** Fuente del token del widget: valor fijo o función que lo renueva. */
export type TokenSource = string | (() => Promise<string>);

export interface WidgetClientOptions extends BaseOptions {
  token?: TokenSource;
}

/** Cliente del navegador (widget): solo usa tokens temporales, nunca credenciales permanentes. */
export class WebAssistantClient extends HttpClient {
  private token: TokenSource | undefined;
  private cachedToken: string | null = null;

  constructor(options: WidgetClientOptions) {
    super(options);
    this.token = options.token;
  }

  setToken(token: TokenSource | undefined): void {
    this.token = token;
    this.cachedToken = typeof token === 'string' ? token : null;
  }

  private async authorization(): Promise<Record<string, string>> {
    if (!this.cachedToken && this.token) {
      this.cachedToken = typeof this.token === 'string' ? this.token : await this.token();
    }
    return this.cachedToken ? { Authorization: `Bearer ${this.cachedToken}` } : {};
  }

  invalidateToken(): void {
    this.cachedToken = null;
  }

  private async authed<T>(
    method: string,
    path: string,
    body?: unknown,
    extra?: Record<string, string>,
    retry = true,
  ): Promise<T> {
    try {
      return await this.request<T>(method, path, body, { ...(await this.authorization()), ...extra });
    } catch (error) {
      if (retry && error instanceof ApiError && error.status === 401 && typeof this.token === 'function') {
        this.invalidateToken();
        return this.authed<T>(method, path, body, extra, false);
      }
      throw error;
    }
  }

  bootstrap(siteId: string, assistantId?: string, locale?: string): Promise<BootstrapResponse> {
    const params = new URLSearchParams({ site_id: siteId });
    if (assistantId) params.set('assistant_id', assistantId);
    if (locale) params.set('locale', locale);
    return this.request<BootstrapResponse>('GET', `/v1/bootstrap?${params.toString()}`);
  }

  createSession(body: {
    site_id: string;
    assistant_id?: string;
    locale?: string;
    anonymous_session?: string;
    host_token?: string;
  }): Promise<SessionResponse> {
    return this.request<SessionResponse>('POST', '/v1/sessions', body);
  }

  createConversation(assistantId?: string, locale?: string): Promise<ConversationView> {
    return this.authed('POST', '/v1/conversations', { assistant_id: assistantId, locale });
  }

  listConversations(): Promise<{ items: ConversationView[] }> {
    return this.authed('GET', '/v1/conversations');
  }

  getConversation(conversationId: string): Promise<ConversationHistory> {
    return this.authed('GET', `/v1/conversations/${encodeURIComponent(conversationId)}`);
  }

  sendMessage(conversationId: string, message: string): Promise<ChatResponse> {
    return this.authed('POST', `/v1/conversations/${encodeURIComponent(conversationId)}/messages`, {
      message,
    });
  }

  /** Envía un mensaje y emite los eventos SSE del run hasta un evento terminal. */
  async *streamMessage(
    conversationId: string,
    message: string,
    signal?: AbortSignal,
  ): AsyncGenerator<StreamEvent> {
    const response = await this.fetchImpl(
      joinUrl(this.baseUrl, `/v1/conversations/${encodeURIComponent(conversationId)}/messages`),
      {
        method: 'POST',
        headers: {
          ...(await this.authorization()),
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
        },
        body: JSON.stringify({ message }),
        signal,
      },
    );
    if (!response.ok) throw new ApiError(await toProblem(response));
    if (!response.body || !(response.headers.get('content-type') || '').includes('text/event-stream')) {
      const body = (await response.json()) as ChatResponse;
      yield { type: 'run.completed', run_id: body.run_id, seq: 1, response: body };
      return;
    }
    yield* this.parseStream(response.body, signal);
  }

  /** Reanuda el stream de un run tras una desconexión (Last-Event-ID). */
  async *resumeRun(
    conversationId: string,
    runId: string,
    lastEventId: number,
    signal?: AbortSignal,
  ): AsyncGenerator<StreamEvent> {
    const response = await this.fetchImpl(
      joinUrl(
        this.baseUrl,
        `/v1/conversations/${encodeURIComponent(conversationId)}/runs/${encodeURIComponent(runId)}/events`,
      ),
      {
        method: 'GET',
        headers: {
          ...(await this.authorization()),
          Accept: 'text/event-stream',
          'Last-Event-ID': String(lastEventId),
        },
        signal,
      },
    );
    if (!response.ok || !response.body) throw new ApiError(await toProblem(response));
    yield* this.parseStream(response.body, signal);
  }

  private async *parseStream(
    body: ReadableStream<Uint8Array>,
    signal?: AbortSignal,
  ): AsyncGenerator<StreamEvent> {
    for await (const message of readSse(body, signal)) {
      let event: StreamEvent;
      try {
        event = JSON.parse(message.data) as StreamEvent;
      } catch {
        continue;
      }
      yield event;
      if (event.type === 'run.completed' || event.type === 'run.failed' || event.type === 'run.cancelled')
        return;
    }
  }

  cancelRun(conversationId: string, runId: string): Promise<{ run_id: string; status: string }> {
    return this.authed(
      'POST',
      `/v1/conversations/${encodeURIComponent(conversationId)}/runs/${encodeURIComponent(runId)}/cancel`,
    );
  }

  resetConversation(conversationId: string, locale?: string): Promise<ConversationView> {
    return this.authed('POST', `/v1/conversations/${encodeURIComponent(conversationId)}/reset`, { locale });
  }

  deleteConversation(conversationId: string): Promise<void> {
    return this.authed('DELETE', `/v1/conversations/${encodeURIComponent(conversationId)}`);
  }

  prepareAction(body: {
    conversation_id: string;
    run_id?: string | null;
    skill: string;
    action: string;
    params: Record<string, unknown>;
  }): Promise<ActionExecution> {
    return this.authed('POST', '/v1/actions/prepare', body);
  }

  executeAction(
    executionId: string,
    idempotencyKey: string | null,
    confirmationToken?: string,
  ): Promise<ActionExecution> {
    const extra: Record<string, string> = idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {};
    return this.authed(
      'POST',
      `/v1/actions/${encodeURIComponent(executionId)}/execute`,
      { confirmation_token: confirmationToken },
      extra,
    );
  }

  reportAction(
    executionId: string,
    status: 'succeeded' | 'failed',
    result?: Record<string, unknown>,
  ): Promise<ActionExecution> {
    return this.authed('POST', `/v1/actions/${encodeURIComponent(executionId)}/result`, { status, result });
  }
}

export interface PortalClientOptions extends BaseOptions {
  clientId: string;
  clientSecret: string;
}

/**
 * Cliente server-to-server para portales (Drupal, Liferay, Adobe, WordPress...).
 * Solo debe usarse en el backend del portal: maneja credenciales permanentes.
 */
export class PortalClient extends HttpClient {
  private readonly clientId: string;
  private readonly clientSecret: string;
  private serverToken: { value: string; expires: number } | null = null;

  constructor(options: PortalClientOptions) {
    super(options);
    this.clientId = options.clientId;
    this.clientSecret = options.clientSecret;
  }

  private async bearer(): Promise<Record<string, string>> {
    if (!this.serverToken || this.serverToken.expires - Date.now() < 30_000) {
      const token = await this.request<TokenResponse>('POST', '/v1/token', {
        grant_type: 'client_credentials',
        client_id: this.clientId,
        client_secret: this.clientSecret,
      });
      this.serverToken = { value: token.access_token, expires: Date.now() + token.expires_in * 1000 };
    }
    return { Authorization: `Bearer ${this.serverToken.value}` };
  }

  /** Emite un token temporal de widget para un usuario autenticado del portal. */
  issueWidgetToken(
    body: Omit<TokenRequest, 'grant_type' | 'client_id' | 'client_secret'>,
  ): Promise<TokenResponse> {
    return this.request<TokenResponse>('POST', '/v1/token', {
      ...body,
      grant_type: 'widget_token',
      client_id: this.clientId,
      client_secret: this.clientSecret,
    });
  }

  async ingest(documents: Record<string, unknown>[]): Promise<{ items: Array<Record<string, unknown>> }> {
    return this.request('POST', '/v1/ingest/documents', { documents }, await this.bearer());
  }

  async deleteDocument(knowledgeBase: string, sourceId: string): Promise<Record<string, unknown>> {
    return this.request(
      'DELETE',
      `/v1/ingest/documents/${encodeURIComponent(knowledgeBase)}/${encodeURIComponent(sourceId)}`,
      undefined,
      await this.bearer(),
    );
  }

  async updateAcl(knowledgeBase: string, sourceId: string, acl: string[]): Promise<Record<string, unknown>> {
    return this.request(
      'PUT',
      `/v1/ingest/acl/${encodeURIComponent(knowledgeBase)}/${encodeURIComponent(sourceId)}`,
      { acl },
      await this.bearer(),
    );
  }

  async inventory(
    knowledgeBase: string,
    cursor?: string,
  ): Promise<{ items: Array<Record<string, unknown>>; next_cursor: string | null }> {
    const query = cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
    return this.request(
      'GET',
      `/v1/ingest/inventory/${encodeURIComponent(knowledgeBase)}${query}`,
      undefined,
      await this.bearer(),
    );
  }

  async search(body: SearchBody): Promise<{ items: Array<Record<string, unknown>> }> {
    return this.request('POST', '/v1/search', body, await this.bearer());
  }
}

/** Cliente de la consola de administración. */
export class AdminClient extends HttpClient {
  private token: string | null = null;

  setToken(token: string | null): void {
    this.token = token;
  }

  protected override headers(extra?: Record<string, string>): Record<string, string> {
    return super.headers(this.token ? { Authorization: `Bearer ${this.token}`, ...extra } : extra);
  }

  async login(
    email: string,
    password: string,
  ): Promise<{ access_token: string; expires_at: string; user: Record<string, unknown> }> {
    const result = await this.request<{
      access_token: string;
      expires_at: string;
      user: Record<string, unknown>;
    }>('POST', '/v1/admin/auth/login', { email, password });
    this.token = result.access_token;
    return result;
  }

  get<T = unknown>(path: string): Promise<T> {
    return this.request<T>('GET', path);
  }

  post<T = unknown>(path: string, body?: unknown): Promise<T> {
    return this.request<T>('POST', path, body ?? {});
  }

  patch<T = unknown>(path: string, body: unknown): Promise<T> {
    return this.request<T>('PATCH', path, body);
  }

  put<T = unknown>(path: string, body: unknown): Promise<T> {
    return this.request<T>('PUT', path, body);
  }

  async text(path: string): Promise<string> {
    const response = await this.fetchImpl(joinUrl(this.baseUrl, path), {
      headers: this.headers({ Accept: 'text/csv' }),
    });
    if (!response.ok) throw new ApiError(await toProblem(response));
    return response.text();
  }
}
