/**
 * Chat de WebAssistant montado directamente en la página anfitriona (CG-10.2..10.8).
 *
 * - Shadow DOM: los estilos del portal no rompen el chat y viceversa.
 * - Streaming SSE con reanudación (Last-Event-ID), cancelación y estados accesibles.
 * - Historial restaurado al recargar, nuevo chat, sugerencias iniciales.
 * - Presentaciones en el panel o en un contenedor externo (`data-result-target`).
 * - Acciones con confirmación y host actions delegadas a la página.
 */
import type {
  ActionDescriptor,
  BootstrapResponse,
  ChatResponse,
  Citation,
  ConversationHistory,
  HistoryMessage,
  Presentation,
  StreamEvent,
} from '@webassistant/sdk';
import { ApiError, WebAssistantClient } from '@webassistant/sdk';

import type { MountOptions, PublicApi, TokenInput, WebAssistantGlobal } from '../shared';
import { ActionController, type ActionOutcome } from './actions';
import { Announcer } from './a11y';
import { append, clear, h, safeUrl, text, uid } from './dom';
import { EventBus } from './events';
import { type Translate, translator } from './i18n';
import { render, type RenderContext } from './renderers';
import { SessionManager, WidgetStore } from './session';
import { BASE_CSS, RESULT_TARGET_CSS } from './styles';
import { applyTheme, type ThemeTokens } from './theme';

declare const __WIDGET_LOGO__: string;

const MAX_RESUMES = 2;
const DESKTOP_QUERY = '(min-width: 901px)';
const PRESENTATIONS_WITH_SOURCES = new Set(['answer', 'document_citations']);

interface Bubble {
  item: HTMLLIElement;
  bubble: HTMLElement;
  body: HTMLElement;
}

interface Turn {
  conversationId: string;
  runId: string | null;
  presentation: Presentation | null;
  actions: ActionDescriptor[];
  container: HTMLElement | null;
}

function adoptStyles(root: ShadowRoot, css: string): void {
  // Hojas construibles: compatibles con CSP estricta (sin <style> inline) cuando el navegador las soporta.
  try {
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(css);
    root.adoptedStyleSheets = [...root.adoptedStyleSheets, sheet];
    return;
  } catch {
    /* navegador sin hojas construibles */
  }
  root.appendChild(h('style', { text: css }));
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

export class ChatWidget implements PublicApi {
  private readonly client: WebAssistantClient;
  private readonly store: WidgetStore;
  private readonly session: SessionManager;
  private readonly events: EventBus;
  private readonly announcer = new Announcer();
  private t: Translate;
  private locale: string;

  private bootstrapData: BootstrapResponse | null = null;
  private host: HTMLElement | null = null;
  private shadow: ShadowRoot | null = null;
  private root!: HTMLElement;
  private launcher!: HTMLButtonElement;
  private panel!: HTMLElement;
  private notice!: HTMLElement;
  private list!: HTMLOListElement;
  private status!: HTMLElement;
  private input!: HTMLTextAreaElement;
  private counter!: HTMLElement;
  private sendButton!: HTMLButtonElement;
  private stopButton!: HTMLButtonElement;
  private suggestions: HTMLElement | null = null;
  private actionsController: ActionController | null = null;

  private resultHost: HTMLElement | null = null;
  private resultRegion: HTMLElement | null = null;
  private hiddenTargetChildren: HTMLElement[] = [];

  private conversationId: string | null = null;
  private conversationPromise: Promise<string> | null = null;
  private running = false;
  private abort: AbortController | null = null;
  private currentRun: string | null = null;
  private currentTurn: Turn | null = null;
  private isOpen = false;
  private ready = false;
  private hasHostToken: boolean;
  private readonly queue: Array<() => void> = [];

  constructor(
    private readonly options: MountOptions,
    private readonly global: WebAssistantGlobal,
  ) {
    this.locale = options.locale || 'es';
    this.t = translator(this.locale);
    this.store = new WidgetStore(`wa:${options.instanceId}`);
    this.client = new WebAssistantClient({ baseUrl: options.apiBase });
    this.session = new SessionManager(
      this.client,
      this.store,
      options.siteId,
      options.assistantId,
      options.locale,
    );
    this.hasHostToken = Boolean(options.token);
    if (options.token) this.session.setExternalToken(options.token);
    this.client.setToken(this.session.provider);
    this.events = new EventBus(options.instanceId, global);
  }

  // --- API pública ------------------------------------------------------------------

  setToken(token: TokenInput | null): void {
    this.session.setExternalToken(token || null);
    this.client.setToken(this.session.provider);
    this.hasHostToken = Boolean(token);
    this.conversationId = null;
    if (this.ready) {
      this.notice.hidden = true;
      this.setComposerEnabled(true);
      if (this.isOpen) void this.loadConversation();
    }
  }

  open(): void {
    if (!this.ready) {
      this.queue.push(() => this.open());
      return;
    }
    this.setOpen(true, true);
  }

  close(): void {
    if (this.ready) this.setOpen(false, true);
  }

  async send(message: string): Promise<void> {
    if (!this.ready) {
      this.queue.push(() => void this.send(message));
      return;
    }
    if (!this.isOpen) this.setOpen(true, false);
    await this.submit(message);
  }

  // --- Montaje ----------------------------------------------------------------------

  async start(): Promise<void> {
    let data: BootstrapResponse;
    try {
      data = await this.client.bootstrap(this.options.siteId, this.options.assistantId, this.options.locale);
    } catch (error) {
      console.warn('[WebAssistant] bootstrap', error instanceof ApiError ? error.code : error);
      this.events.emit('error', {
        stage: 'bootstrap',
        code: error instanceof ApiError ? error.code : 'network_error',
      });
      return;
    }
    this.bootstrapData = data;
    this.locale = data.locale || this.locale;
    this.t = translator(this.locale);
    this.build(data);
    this.ready = true;
    const layout = (data.layout ?? {}) as { desktop_always_open?: boolean };
    const desktop = typeof window.matchMedia === 'function' && window.matchMedia(DESKTOP_QUERY).matches;
    if (this.options.open || (layout.desktop_always_open && desktop)) this.setOpen(true, false);
    if (data.auth?.mode && data.auth.mode !== 'public' && !this.hasHostToken) {
      this.showNotice(data.texts.login_required || this.t('unavailable'));
      this.setComposerEnabled(false);
    }
    this.events.emit('ready', {
      site_id: this.options.siteId,
      assistant_id: data.assistant?.id ?? null,
      locale: this.locale,
    });
    for (const call of this.queue.splice(0)) call();
  }

  private build(data: BootstrapResponse): void {
    const theme = (data.theme ?? {}) as ThemeTokens;
    const position = this.options.position || (theme.position === 'right' ? 'right' : 'left');
    const panelId = uid('wa-panel');
    const titleId = uid('wa-title');
    const inputId = uid('wa-input');
    const counterId = uid('wa-counter');
    const privacyId = uid('wa-privacy');
    const maxLength = Number((data.limits as { max_message_length?: number })?.max_message_length) || 800;

    // Elemento propio (no <div>) para que los selectores de la página no lo alcancen.
    this.host = document.createElement('webassistant-chat');
    this.host.setAttribute('data-instance', this.options.instanceId);
    this.shadow = this.host.attachShadow({ mode: 'open' });
    adoptStyles(this.shadow, BASE_CSS);
    this.root = h('div', { className: 'wa', 'data-position': position, lang: this.locale });
    applyTheme(this.root, theme);

    const texts = data.texts;
    this.launcher = h(
      'button',
      { type: 'button', className: 'wa-launcher', 'aria-controls': panelId, 'aria-expanded': 'false' },
      text(texts.launcher_label || theme.launcher_label || this.t('launcher'), 60),
    );
    this.launcher.addEventListener('click', () => this.setOpen(true, true));

    const logoUrl = safeUrl(theme.logo_url) || `${this.options.apiBase}/widget/v1/${__WIDGET_LOGO__}`;
    const newChat = h(
      'button',
      { type: 'button', className: 'wa-new', 'aria-label': text(texts.new_chat || this.t('newChat'), 60) },
      h('span', { 'aria-hidden': 'true', text: '+' }),
      h('span', {
        className: 'wa-new-label',
        'aria-hidden': 'true',
        text: ` ${text(texts.new_chat || this.t('newChat'), 60)}`,
      }),
    );
    newChat.addEventListener('click', () => void this.reset());
    const closeButton = h(
      'button',
      { type: 'button', className: 'wa-close', 'aria-label': text(texts.close || this.t('close'), 60) },
      h('span', { 'aria-hidden': 'true', text: '✕' }),
    );
    closeButton.addEventListener('click', () => this.setOpen(false, true));

    this.notice = h('div', { className: 'wa-notice', role: 'note' });
    this.notice.hidden = true;
    this.list = h('ol', { className: 'wa-messages', 'aria-label': this.t('conversation'), tabindex: '0' });
    this.status = h('p', { className: 'wa-status', 'aria-hidden': 'true' });

    this.input = h('textarea', {
      id: inputId,
      className: 'wa-input',
      rows: 3,
      maxlength: maxLength,
      placeholder: text(texts.input_placeholder, 200) || null,
      'aria-describedby': `${counterId} ${privacyId}`,
      autocomplete: 'off',
    });
    this.counter = h('span', {
      id: counterId,
      className: 'wa-counter',
      text: this.t('charCount', { count: 0, max: maxLength }),
    });
    this.input.addEventListener('input', () => {
      this.counter.textContent = this.t('charCount', { count: this.input.value.length, max: maxLength });
      this.input.removeAttribute('aria-invalid');
    });
    this.input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        form.requestSubmit();
      }
    });
    this.sendButton = h(
      'button',
      { type: 'submit', className: 'wa-send' },
      text(texts.send || this.t('send'), 40),
    );
    this.stopButton = h('button', { type: 'button', className: 'wa-stop' }, this.t('stop'));
    this.stopButton.hidden = true;
    this.stopButton.addEventListener('click', () => void this.stop());
    const form = h(
      'form',
      { className: 'wa-composer', novalidate: true },
      h('label', { className: 'wa-label', for: inputId, text: this.t('inputLabel') }),
      this.input,
      h('div', { className: 'wa-composer-row' }, this.counter, this.stopButton, this.sendButton),
      h('p', { id: privacyId, className: 'wa-privacy', text: text(texts.privacy_notice, 400) }),
    );
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit(this.input.value);
    });

    this.panel = h(
      'section',
      {
        id: panelId,
        className: 'wa-panel',
        role: 'dialog',
        'aria-modal': 'false',
        'aria-labelledby': titleId,
      },
      h(
        'header',
        { className: 'wa-header' },
        h('img', { className: 'wa-logo', src: logoUrl, alt: '', width: 36, height: 36 }),
        h('h2', {
          id: titleId,
          className: 'wa-title',
          text: text(data.assistant?.name || this.t('title'), 120),
        }),
        newChat,
        closeButton,
      ),
      this.notice,
      this.list,
      this.status,
      form,
    );
    this.panel.hidden = true;
    this.panel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !this.shadow?.querySelector('.wa-backdrop')) {
        event.stopPropagation();
        this.setOpen(false, true);
      }
    });

    append(this.root, this.launcher, this.panel, this.announcer.polite, this.announcer.assertive);
    this.shadow.appendChild(this.root);
    document.body.appendChild(this.host);
    this.actionsController = new ActionController(
      this.client,
      this.global,
      this.events,
      this.announcer,
      this.root,
      this.t,
    );
    this.renderWelcome();
  }

  private setOpen(open: boolean, focus: boolean): void {
    if (open === this.isOpen) {
      if (open && focus) this.input.focus();
      return;
    }
    this.isOpen = open;
    this.panel.hidden = !open;
    this.launcher.hidden = open;
    this.launcher.setAttribute('aria-expanded', String(open));
    this.events.emit(open ? 'open' : 'close', {}, { conversation_id: this.conversationId });
    if (open) {
      if (focus) this.input.focus();
      void this.loadConversation();
    } else if (focus) {
      this.launcher.focus();
    }
  }

  private setComposerEnabled(enabled: boolean): void {
    this.input.disabled = !enabled;
    this.sendButton.disabled = !enabled || this.running;
  }

  private showNotice(message: string): void {
    this.notice.textContent = message;
    this.notice.hidden = false;
  }

  // --- Conversación -----------------------------------------------------------------

  private async loadConversation(): Promise<void> {
    try {
      await this.ensureConversation();
    } catch (error) {
      this.handleError(error, 'conversation');
    }
  }

  private ensureConversation(): Promise<string> {
    if (this.conversationId) return Promise.resolve(this.conversationId);
    if (this.conversationPromise) return this.conversationPromise;
    this.conversationPromise = this.openConversation().finally(() => {
      this.conversationPromise = null;
    });
    return this.conversationPromise;
  }

  private async openConversation(): Promise<string> {
    const stored = this.store.conversationId;
    if (stored) {
      try {
        const history = await this.client.getConversation(stored);
        if (history.status === 'active') {
          this.conversationId = history.conversation_id;
          this.restore(history);
          return history.conversation_id;
        }
      } catch (error) {
        if (!(error instanceof ApiError) || ![401, 403, 404].includes(error.status)) throw error;
      }
      this.store.conversationId = null;
    }
    const created = await this.client.createConversation(this.options.assistantId, this.locale);
    this.conversationId = created.conversation_id;
    this.store.conversationId = created.conversation_id;
    return created.conversation_id;
  }

  private restore(history: ConversationHistory): void {
    const messages = history.messages ?? [];
    if (!messages.length) return;
    this.clearMessages();
    const lastAssistant = [...messages].reverse().find((m) => m.role === 'assistant' && m.presentation);
    for (const message of messages) {
      if (message.role === 'user') {
        this.addMessage('user', message.content);
        continue;
      }
      const entry = this.addMessage('assistant', message.content);
      if (message === lastAssistant) this.renderRestored(message, entry, history.conversation_id);
    }
  }

  private renderRestored(message: HistoryMessage, entry: Bubble, conversationId: string): void {
    if (!message.presentation) return;
    const turn: Turn = {
      conversationId,
      runId: message.run_id,
      presentation: message.presentation,
      actions: message.actions ?? [],
      container: null,
    };
    this.showPresentation(turn, entry, false);
  }

  private async reset(): Promise<void> {
    if (this.running) await this.stop();
    const previous = this.conversationId;
    this.clearMessages();
    this.restoreTarget();
    this.renderWelcome();
    try {
      if (previous) {
        const created = await this.client.resetConversation(previous, this.locale);
        this.conversationId = created.conversation_id;
        this.store.conversationId = created.conversation_id;
      } else {
        this.conversationId = null;
        this.store.conversationId = null;
        await this.ensureConversation();
      }
      this.events.emit(
        'reset',
        { previous_conversation_id: previous },
        { conversation_id: this.conversationId },
      );
      this.announcer.say(this.t('newChat'));
    } catch (error) {
      this.conversationId = null;
      this.store.conversationId = null;
      this.handleError(error, 'reset');
    }
    this.input.focus();
  }

  // --- Mensajes ---------------------------------------------------------------------

  private clearMessages(): void {
    clear(this.list);
    this.suggestions = null;
    this.currentTurn = null;
  }

  private renderWelcome(): void {
    const data = this.bootstrapData;
    if (!data) return;
    if (data.texts.welcome) this.addMessage('assistant', data.texts.welcome);
    const options = (data.suggestions ?? []).filter((s) => typeof s === 'string' && s.trim()).slice(0, 6);
    if (!options.length) return;
    const chips = h(
      'ul',
      { className: 'wa-suggestions', 'aria-label': this.t('suggestions') },
      options.map((option) => {
        const button = h('button', { type: 'button', className: 'wa-chip' }, text(option, 160));
        button.addEventListener('click', () => void this.submit(option));
        return h('li', {}, button);
      }),
    );
    this.suggestions = h('li', { className: 'wa-message wa-message--assistant' }, chips);
    this.list.appendChild(this.suggestions);
  }

  private addMessage(role: 'user' | 'assistant' | 'error', content: string): Bubble {
    const body = h('div', { className: 'wa-bubble-text', text: content });
    const bubble = h('div', { className: 'wa-bubble' }, body);
    const author = role === 'user' ? this.t('you') : this.t('assistant');
    const item = h(
      'li',
      { className: `wa-message wa-message--${role === 'error' ? 'assistant wa-message--error' : role}` },
      h('p', { className: 'wa-author wa-sr', text: `${author}:` }),
      bubble,
    );
    this.list.appendChild(item);
    this.scrollToEnd();
    return { item, bubble, body };
  }

  private pendingMessage(): Bubble {
    const entry = this.addMessage('assistant', '');
    entry.bubble.setAttribute('aria-busy', 'true');
    const loader = h(
      'span',
      { className: 'wa-loader', 'aria-hidden': 'true' },
      h('span'),
      h('span'),
      h('span'),
    );
    entry.bubble.insertBefore(loader, entry.body);
    return entry;
  }

  private scrollToEnd(): void {
    this.list.scrollTop = this.list.scrollHeight;
  }

  /** Muestra el inicio de una respuesta larga en lugar de su final. */
  private scrollToMessage(item: HTMLElement): void {
    this.list.scrollTop = Math.max(0, item.offsetTop - this.list.offsetTop - 8);
  }

  private setStatus(key: 'thinking' | 'searching' | 'composing' | null): void {
    this.status.textContent = key ? this.t(key) : '';
    if (key) this.announcer.say(this.t(key));
  }

  private setRunning(running: boolean): void {
    this.running = running;
    this.sendButton.disabled = running || this.input.disabled;
    this.stopButton.hidden = !running;
    this.list.setAttribute('aria-busy', String(running));
  }

  // --- Envío y streaming ------------------------------------------------------------

  private async submit(raw: string): Promise<void> {
    const message = raw.trim();
    if (!message || this.running || this.input.disabled) {
      if (!message) this.input.focus();
      return;
    }
    const maxLength = Number(this.input.getAttribute('maxlength')) || 800;
    if (message.length > maxLength) {
      this.input.setAttribute('aria-invalid', 'true');
      this.announcer.say(this.t('tooLong', { max: maxLength }), true);
      return;
    }
    this.suggestions?.remove();
    this.suggestions = null;
    this.input.value = '';
    this.counter.textContent = this.t('charCount', { count: 0, max: maxLength });
    this.addMessage('user', message);
    const pending = this.pendingMessage();
    this.setRunning(true);
    this.setStatus('thinking');
    let conversationId: string | null = null;
    try {
      conversationId = await this.ensureConversation();
      this.events.emit('message_sent', { length: message.length }, { conversation_id: conversationId });
      const response = await this.stream(conversationId, message, pending);
      if (response) this.complete(response, pending);
    } catch (error) {
      pending.item.remove();
      this.handleError(error, 'message', conversationId);
    } finally {
      this.setRunning(false);
      this.setStatus(null);
      this.abort = null;
      this.currentRun = null;
    }
  }

  private async stream(
    conversationId: string,
    message: string,
    pending: Bubble,
  ): Promise<ChatResponse | null> {
    const controller = new AbortController();
    this.abort = controller;
    let runId: string | null = null;
    let lastSeq = 0;
    let resumes = 0;
    let reauthenticated = false;
    let reply = '';
    let iterator: AsyncGenerator<StreamEvent> = this.client.streamMessage(
      conversationId,
      message,
      controller.signal,
    );
    for (;;) {
      try {
        for await (const event of iterator) {
          if (typeof event.seq === 'number') lastSeq = Math.max(lastSeq, event.seq);
          if (event.run_id && !runId) {
            runId = event.run_id;
            this.currentRun = runId;
          }
          switch (event.type) {
            case 'run.status':
              if (event.status === 'searching' || event.status === 'composing' || event.status === 'thinking')
                this.setStatus(event.status);
              break;
            case 'reply.delta':
              if (event.delta) {
                reply += event.delta;
                pending.body.textContent = reply;
                this.scrollToEnd();
              }
              break;
            case 'run.completed':
              return event.response ?? null;
            case 'run.failed':
              throw new ApiError(
                event.error ?? {
                  type: 'about:blank',
                  title: 'Error',
                  status: 500,
                  code: 'internal_error',
                  user_message: this.t('error'),
                },
              );
            case 'run.cancelled':
              this.markCancelled(pending);
              return null;
            default:
              break;
          }
        }
        if (controller.signal.aborted) {
          this.markCancelled(pending);
          return null;
        }
        throw new Error('stream_closed');
      } catch (error) {
        if (controller.signal.aborted) {
          this.markCancelled(pending);
          return null;
        }
        if (error instanceof ApiError) {
          if (error.status === 401 && !runId && !reauthenticated) {
            reauthenticated = true;
            this.client.invalidateToken();
            iterator = this.client.streamMessage(conversationId, message, controller.signal);
            continue;
          }
          throw error;
        }
        if (runId && resumes < MAX_RESUMES) {
          resumes += 1;
          await delay(800 * resumes);
          iterator = this.client.resumeRun(conversationId, runId, lastSeq, controller.signal);
          continue;
        }
        throw error;
      }
    }
  }

  private markCancelled(pending: Bubble): void {
    pending.bubble.removeAttribute('aria-busy');
    pending.bubble.querySelector('.wa-loader')?.remove();
    if (!pending.body.textContent) pending.body.textContent = this.t('cancelled');
    this.announcer.say(this.t('cancelled'));
  }

  private async stop(): Promise<void> {
    const runId = this.currentRun;
    const conversationId = this.conversationId;
    this.abort?.abort();
    if (runId && conversationId) {
      await this.client.cancelRun(conversationId, runId).catch(() => undefined);
    }
  }

  private complete(response: ChatResponse, pending: Bubble): void {
    pending.bubble.removeAttribute('aria-busy');
    pending.bubble.querySelector('.wa-loader')?.remove();
    pending.body.textContent = response.reply || '';
    const turn: Turn = {
      conversationId: response.conversation_id,
      runId: response.run_id,
      presentation: response.presentation,
      actions: response.actions ?? [],
      container: null,
    };
    if (response.presentation) {
      this.showPresentation(turn, pending, true);
    } else if (!response.keep_presentation && this.options.resultTarget) {
      this.restoreTarget();
    }
    if (
      response.citations?.length &&
      !(response.presentation && PRESENTATIONS_WITH_SOURCES.has(response.presentation.type))
    ) {
      pending.bubble.appendChild(this.citations(response.citations));
    }
    // Se muestra la pregunta y el inicio de la respuesta (no el final de un resultado largo).
    const question = pending.item.previousElementSibling;
    this.scrollToMessage(question instanceof HTMLElement ? question : pending.item);
    this.events.emit(
      'response',
      {
        skill: response.skill,
        has_presentation: Boolean(response.presentation),
        actions: (response.actions ?? []).map((a) => a.name),
      },
      { conversation_id: response.conversation_id, run_id: response.run_id },
    );
    this.announcer.say(`${this.t('answered')}: ${text(response.reply, 280)}`);
  }

  private citations(items: Citation[]): HTMLElement {
    return h(
      'section',
      { className: 'wa-result', 'aria-label': this.t('sources') },
      h('p', { className: 'wa-author', text: this.t('sources') }),
      h(
        'ul',
        { className: 'wa-sources' },
        items.slice(0, 8).map((citation) => {
          const url = safeUrl(citation.url);
          const label = text(citation.title, 200);
          return h('li', {}, url ? h('a', { href: url }, label) : label);
        }),
      ),
    );
  }

  // --- Presentaciones y acciones ----------------------------------------------------

  private showPresentation(turn: Turn, entry: Bubble, live: boolean): void {
    const presentation = turn.presentation;
    if (!presentation) return;
    const autoActions = turn.actions.filter((a) => a.auto && a.type === 'host');
    if (autoActions.length && presentation.type === 'cart') {
      turn.presentation = { ...presentation, data: { ...presentation.data, loading: true } };
    }
    this.currentTurn = turn;
    if (this.options.resultTarget && this.mountTarget()) {
      turn.container = this.resultRegion;
      const button = h(
        'button',
        { type: 'button', className: 'wa-secondary wa-view-result' },
        this.t('viewResult'),
      );
      button.addEventListener('click', () => this.resultRegion?.focus());
      entry.bubble.appendChild(button);
    } else {
      const container = h('div', { className: 'wa-result' });
      entry.item.appendChild(container);
      turn.container = container;
    }
    this.paint(turn);
    if (live) {
      this.events.emit(
        'presentation',
        { type: presentation.type, version: presentation.version },
        { conversation_id: turn.conversationId, run_id: turn.runId },
      );
    }
    for (const action of autoActions) void this.runAction(turn, action, { ...(action.params ?? {}) });
  }

  private paint(turn: Turn): void {
    if (!turn.container || !turn.presentation) return;
    const ctx: RenderContext = {
      t: this.t,
      locale: this.locale,
      actions: turn.actions,
      runAction: (action, params) => void this.runAction(turn, action, params),
      quickReply: (value) => void this.submit(value),
      headingLevel: 'h3',
    };
    clear(turn.container);
    turn.container.appendChild(render(turn.presentation, ctx));
  }

  private async runAction(
    turn: Turn,
    action: ActionDescriptor,
    params: Record<string, unknown>,
  ): Promise<void> {
    if (!this.actionsController) return;
    const outcome: ActionOutcome = await this.actionsController.run(action, params, {
      conversationId: turn.conversationId,
      runId: turn.runId,
    });
    const presentation = turn.presentation;
    if (!presentation || this.currentTurn !== turn) return;
    if (presentation.type === 'cart') {
      if (action.auto) {
        const data = outcome.status === 'succeeded' ? (outcome.data ?? {}) : {};
        turn.presentation = { ...presentation, data: { ...presentation.data, ...data, loading: false } };
        this.paint(turn);
        if (outcome.status !== 'succeeded')
          this.announcer.say(outcome.message || this.t('actionFailed'), true);
        return;
      }
      if (outcome.status === 'succeeded') {
        // Tras modificar el carrito se recarga su contenido desde la página anfitriona.
        const refresh = turn.actions.find((a) => a.auto && a.type === 'host');
        if (refresh) await this.runAction(turn, refresh, { ...(refresh.params ?? {}) });
      }
    }
  }

  private mountTarget(): boolean {
    if (this.resultRegion?.isConnected) return true;
    const selector = this.options.resultTarget;
    if (!selector) return false;
    let target: HTMLElement | null;
    try {
      target = document.querySelector<HTMLElement>(selector);
    } catch {
      target = null; // selector CSS no válido
    }
    if (!target) return false;
    this.hiddenTargetChildren = Array.from(target.children).filter(
      (child): child is HTMLElement => child instanceof HTMLElement && !child.hidden,
    );
    for (const child of this.hiddenTargetChildren) child.hidden = true;
    this.resultHost = document.createElement('webassistant-result');
    this.resultHost.setAttribute('data-instance', this.options.instanceId);
    const shadow = this.resultHost.attachShadow({ mode: 'open' });
    adoptStyles(shadow, BASE_CSS + RESULT_TARGET_CSS);
    const wrapper = h('div', { className: 'wa wa-result-host', lang: this.locale });
    applyTheme(wrapper, (this.bootstrapData?.theme ?? {}) as ThemeTokens);
    this.resultRegion = h('section', {
      role: 'region',
      'aria-label': this.t('resultRegion'),
      tabindex: '-1',
    });
    wrapper.appendChild(this.resultRegion);
    shadow.appendChild(wrapper);
    target.appendChild(this.resultHost);
    return true;
  }

  private restoreTarget(): void {
    if (!this.resultHost) return;
    this.resultHost.remove();
    this.resultHost = null;
    this.resultRegion = null;
    for (const child of this.hiddenTargetChildren) child.hidden = false;
    this.hiddenTargetChildren = [];
  }

  // --- Errores ----------------------------------------------------------------------

  private handleError(
    error: unknown,
    stage: string,
    conversationId: string | null = this.conversationId,
  ): void {
    let message = this.t('error');
    let code = 'network_error';
    if (error instanceof ApiError) {
      message = error.problem.user_message || message;
      code = error.code;
      if (error.status === 401 || error.status === 403) {
        if (this.bootstrapData?.auth?.mode !== 'public')
          message = this.bootstrapData?.texts.login_required || message;
      }
      if (error.status === 404 && stage !== 'conversation') {
        this.conversationId = null;
        this.store.conversationId = null;
      }
    }
    if (stage === 'conversation') {
      this.showNotice(message);
    } else {
      this.addMessage('error', message);
    }
    this.announcer.say(message, true);
    this.events.emit('error', { stage, code }, { conversation_id: conversationId });
  }
}
