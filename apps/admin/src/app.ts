/** Núcleo de la consola: sesión, permisos, enrutado por hash y maquetación accesible. */
import { AdminClient, ApiError } from '@webassistant/sdk';

import { append, button, clear, h, mountRegions, notifyError } from './ui';

export interface Me {
  id: string;
  tenant_id: string | null;
  role: string;
  email: string;
  permissions: string[];
}

export interface Tenant {
  id: string;
  slug: string;
  name: string;
  status: string;
  settings: Record<string, unknown>;
  created_at: string;
}

export interface Context {
  api: AdminClient;
  me: Me;
  tenantId: string;
  tenants: Tenant[];
  params: Record<string, string>;
  query: URLSearchParams;
  can(permission: string): boolean;
  go(path: string): void;
  reload(): void;
  /** Ruta de una sección del tenant activo, p. ej. `t('sites')`. */
  t(path: string): string;
}

export interface View {
  title: string;
  content: Node;
}

export type Page = (ctx: Context) => Promise<View>;

export interface Route {
  pattern: string;
  page: Page;
  permission?: string;
  nav?: { label: string; group: 'general' | 'tenant' | 'platform' | 'account' };
}

const TOKEN_KEY = 'wa-admin-session';
const TENANT_KEY = 'wa-admin-tenant';

function storedSession(): { token: string; expiresAt: string } | null {
  try {
    const raw = window.sessionStorage.getItem(TOKEN_KEY);
    if (!raw) return null;
    const session = JSON.parse(raw) as { token: string; expiresAt: string };
    return new Date(session.expiresAt).getTime() > Date.now() ? session : null;
  } catch {
    return null;
  }
}

export function tenantPath(tenantId: string, path: string): string {
  return `#/t/${encodeURIComponent(tenantId)}/${path}`;
}

function match(pattern: string, path: string): Record<string, string> | null {
  const expected = pattern.split('/').filter(Boolean);
  const actual = path.split('/').filter(Boolean);
  if (expected.length !== actual.length) return null;
  const params: Record<string, string> = {};
  for (let i = 0; i < expected.length; i += 1) {
    const part = expected[i]!;
    const value = actual[i]!;
    if (part.startsWith(':')) params[part.slice(1)] = decodeURIComponent(value);
    else if (part !== value) return null;
  }
  return params;
}

export class ConsoleApp {
  private readonly api: AdminClient;
  private me: Me | null = null;
  private tenants: Tenant[] = [];
  private main!: HTMLElement;
  private nav!: HTMLElement;
  private header!: HTMLElement;
  private renderToken = 0;
  private firstRender = true;

  constructor(
    private readonly root: HTMLElement,
    private readonly routes: Route[],
    private readonly loginPage: (
      api: AdminClient,
      onLogin: (token: string, expiresAt: string) => void,
    ) => View,
  ) {
    this.api = new AdminClient({ baseUrl: window.location.origin });
  }

  async start(): Promise<void> {
    mountRegions(document.body);
    window.addEventListener('hashchange', () => void this.render());
    const session = storedSession();
    if (session) {
      this.api.setToken(session.token);
      try {
        await this.loadIdentity();
      } catch {
        this.logout(false);
      }
    }
    await this.render();
  }

  private async loadIdentity(): Promise<void> {
    this.me = await this.api.get<Me>('/v1/admin/me');
    const tenants = await this.api.get<{ items: Tenant[] }>('/v1/admin/tenants');
    this.tenants = tenants.items;
  }

  private logout(rerender = true): void {
    try {
      window.sessionStorage.removeItem(TOKEN_KEY);
    } catch {
      /* sin almacenamiento */
    }
    this.api.setToken(null);
    this.me = null;
    if (rerender) {
      window.location.hash = '#/';
      void this.render();
    }
  }

  private currentTenant(requested?: string): string {
    const ids = this.tenants.map((t) => t.id);
    if (requested && ids.includes(requested)) return requested;
    if (this.me?.tenant_id) return this.me.tenant_id;
    let stored: string | null;
    try {
      stored = window.localStorage.getItem(TENANT_KEY);
    } catch {
      stored = null; // almacenamiento no disponible
    }
    return stored && ids.includes(stored) ? stored : (ids[0] ?? '');
  }

  private rememberTenant(tenantId: string): void {
    try {
      window.localStorage.setItem(TENANT_KEY, tenantId);
    } catch {
      /* sin almacenamiento */
    }
  }

  async render(): Promise<void> {
    const token = ++this.renderToken;
    if (!this.me) {
      clear(this.root);
      const view = this.loginPage(this.api, (accessToken, expiresAt) => {
        try {
          window.sessionStorage.setItem(TOKEN_KEY, JSON.stringify({ token: accessToken, expiresAt }));
        } catch {
          /* sin almacenamiento */
        }
        this.api.setToken(accessToken);
        void this.loadIdentity()
          .then(() => this.render())
          .catch(notifyError);
      });
      document.title = `${view.title} · WebAssistant`;
      const main = h(
        'main',
        { id: 'main', className: 'login', tabindex: '-1' },
        h('h1', { text: view.title }),
        view.content,
      );
      this.root.appendChild(main);
      return;
    }
    const [rawPath, rawQuery] = (window.location.hash.replace(/^#/, '') || '/').split('?');
    const path = rawPath || '/';
    let found: { route: Route; params: Record<string, string> } | null = null;
    for (const route of this.routes) {
      const params = match(route.pattern, path);
      if (params) {
        found = { route, params };
        break;
      }
    }
    const tenantId = this.currentTenant(found?.params.tid);
    if (tenantId) this.rememberTenant(tenantId);
    const me = this.me;
    const ctx: Context = {
      api: this.api,
      me,
      tenantId,
      tenants: this.tenants,
      params: found?.params ?? {},
      query: new URLSearchParams(rawQuery ?? ''),
      can: (permission) => me.permissions.includes(permission),
      go: (target) => {
        window.location.hash = target.startsWith('#') ? target : `#${target}`;
      },
      reload: () => void this.render(),
      t: (section) => tenantPath(tenantId, section),
    };
    this.layout(ctx, path);
    clear(this.main);
    this.main.setAttribute('aria-busy', 'true');
    let view: View;
    if (!found) {
      view = { title: 'Página no encontrada', content: h('p', { text: 'La sección solicitada no existe.' }) };
    } else if (found.route.permission && !ctx.can(found.route.permission)) {
      view = {
        title: 'Acceso denegado',
        content: h('p', { text: 'Tu rol no tiene permiso para esta sección.' }),
      };
    } else {
      try {
        view = await found.route.page(ctx);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          this.logout();
          return;
        }
        view = {
          title: 'No se pudo cargar la sección',
          content: h('p', {
            className: 'form-error',
            role: 'alert',
            text: error instanceof Error ? error.message : String(error),
          }),
        };
      }
    }
    if (token !== this.renderToken) return;
    clear(this.main);
    this.main.removeAttribute('aria-busy');
    const heading = h('h1', { tabindex: '-1', text: view.title });
    append(this.main, heading, view.content);
    document.title = `${view.title} · Consola WebAssistant`;
    if (!this.firstRender) heading.focus();
    this.firstRender = false;
  }

  private layout(ctx: Context, path: string): void {
    if (!this.header) {
      clear(this.root);
      this.header = h('header', { className: 'topbar' });
      this.nav = h('nav', { className: 'sidebar', 'aria-label': 'Secciones' });
      this.main = h('main', { id: 'main', className: 'content', tabindex: '-1' });
      append(
        this.root,
        h('a', { className: 'skip', href: '#main', text: 'Saltar al contenido' }),
        this.header,
        h('div', { className: 'layout' }, this.nav, this.main),
      );
      this.root.querySelector('.skip')!.addEventListener('click', (event) => {
        event.preventDefault();
        this.main.focus();
      });
    }
    clear(this.header);
    const tenantSelect = h(
      'select',
      { id: 'tenant-select' },
      ctx.tenants.map((t) => h('option', { value: t.id, text: `${t.name} (${t.slug})` })),
    );
    tenantSelect.value = ctx.tenantId;
    tenantSelect.disabled = ctx.tenants.length < 2;
    tenantSelect.addEventListener('change', () => {
      const section = path.startsWith('/t/') ? path.split('/')[3] || 'assistants' : 'assistants';
      ctx.go(tenantPath(tenantSelect.value, section));
    });
    append(
      this.header,
      h(
        'a',
        { className: 'brand', href: '#/' },
        h('img', { src: 'logo.png', alt: '', width: 28, height: 32 }),
        'WebAssistant · Consola',
      ),
      ctx.tenants.length
        ? h(
            'div',
            { className: 'tenant-picker' },
            h('label', { for: 'tenant-select', text: 'Tenant' }),
            tenantSelect,
          )
        : null,
      h(
        'div',
        { className: 'user' },
        h('span', { text: `${ctx.me.email} · ${ctx.me.role}` }),
        button('Cerrar sesión', () => this.logout(), { variant: 'link' }),
      ),
    );
    clear(this.nav);
    const groups: Array<[NonNullable<Route['nav']>['group'], string]> = [
      ['general', 'General'],
      ['tenant', 'Tenant'],
      ['platform', 'Plataforma'],
      ['account', 'Cuenta'],
    ];
    for (const [group, label] of groups) {
      const items = this.routes.filter(
        (r) => r.nav?.group === group && (!r.permission || ctx.can(r.permission)),
      );
      if (!items.length) continue;
      if (group === 'tenant' && !ctx.tenantId) continue;
      const headingId = `nav-${group}`;
      append(
        this.nav,
        h('h2', { id: headingId, text: label }),
        h(
          'ul',
          { 'aria-labelledby': headingId },
          items.map((route) => {
            const href = route.pattern.startsWith('/t/:tid/')
              ? tenantPath(ctx.tenantId, route.pattern.slice('/t/:tid/'.length))
              : `#${route.pattern}`;
            const routePath = href.slice(1).split('?')[0]!;
            const current = path === routePath || (routePath !== '/' && path.startsWith(`${routePath}/`));
            return h(
              'li',
              {},
              h('a', { href, 'aria-current': current ? 'page' : null, text: route.nav!.label }),
            );
          }),
        ),
      );
    }
  }
}
