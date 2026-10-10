/** Inicio de sesión, panel de inicio, tenants y cuenta. */
import type { AdminClient } from '@webassistant/sdk';

import type { Context, Page, Tenant, View } from '../app';
import {
  badge,
  button,
  dialog,
  form,
  formatDate,
  formatNumber,
  formatCost,
  h,
  json,
  notify,
  section,
  table,
  toolbar,
} from '../ui';

export function loginView(api: AdminClient, onLogin: (token: string, expiresAt: string) => void): View {
  return {
    title: 'Consola de WebAssistant',
    content: h(
      'div',
      { className: 'card login-card' },
      h('p', { className: 'muted', text: 'Accede con tu usuario de administración.' }),
      form(
        [
          {
            name: 'email',
            label: 'Correo electrónico',
            type: 'email',
            required: true,
            autocomplete: 'username',
          },
          {
            name: 'password',
            label: 'Contraseña',
            type: 'password',
            required: true,
            autocomplete: 'current-password',
          },
        ],
        {
          submitLabel: 'Entrar',
          onSubmit: async (values) => {
            const result = await api.login(String(values.email), String(values.password));
            onLogin(result.access_token, result.expires_at);
          },
        },
      ),
    ),
  };
}

interface UsageReport {
  from: string;
  to: string;
  items: Array<Record<string, unknown>>;
  totals: Record<string, unknown>;
}

export const dashboardPage: Page = async (ctx) => {
  const blocks: Node[] = [];
  blocks.push(
    section(
      'Tu acceso',
      h(
        'dl',
        { className: 'facts' },
        h('dt', { text: 'Usuario' }),
        h('dd', { text: ctx.me.email }),
        h('dt', { text: 'Rol' }),
        h('dd', { text: ctx.me.role }),
        h('dt', { text: 'Permisos' }),
        h('dd', { text: ctx.me.permissions.join(', ') }),
      ),
    ),
  );
  if (ctx.tenantId && ctx.can('usage.read')) {
    const usage = await ctx.api.get<UsageReport>(`/v1/admin/tenants/${ctx.tenantId}/usage?group_by=model`);
    blocks.push(
      section(
        'Uso de los últimos 30 días',
        h(
          'dl',
          { className: 'kpis' },
          h(
            'div',
            {},
            h('dt', { text: 'Llamadas al modelo' }),
            h('dd', { text: formatNumber(usage.totals.calls) }),
          ),
          h(
            'div',
            {},
            h('dt', { text: 'Tokens de entrada' }),
            h('dd', { text: formatNumber(usage.totals.input_tokens) }),
          ),
          h(
            'div',
            {},
            h('dt', { text: 'Tokens en caché' }),
            h('dd', { text: formatNumber(usage.totals.cached_tokens) }),
          ),
          h(
            'div',
            {},
            h('dt', { text: 'Tokens de salida' }),
            h('dd', { text: formatNumber(usage.totals.output_tokens) }),
          ),
          h('div', {}, h('dt', { text: 'Coste' }), h('dd', { text: formatCost(usage.totals.total_cost) })),
        ),
        h('p', {}, h('a', { href: ctx.t('usage'), text: 'Ver el informe completo' })),
      ),
    );
  }
  if (ctx.can('platform.manage')) {
    const diagnostics = await ctx.api.get<{
      version: string;
      environment: string;
      dependencies: Array<{ name: string; ok: boolean; latency_ms: number; error: string | null }>;
      queue: Record<string, unknown>;
    }>('/v1/admin/diagnostics');
    blocks.push(
      section(
        'Estado de la plataforma',
        h('p', {
          text: `Versión ${diagnostics.version} · entorno ${diagnostics.environment} · cola ${String(diagnostics.queue.mode)} (pendientes: ${String(diagnostics.queue.depth ?? '—')})`,
        }),
        table(
          'Dependencias',
          [
            { label: 'Dependencia', render: (d) => d.name },
            { label: 'Estado', render: (d) => badge(d.ok ? 'ok' : 'error') },
            { label: 'Latencia (ms)', render: (d) => formatNumber(d.latency_ms), numeric: true },
            { label: 'Error', render: (d) => d.error ?? '—' },
          ],
          diagnostics.dependencies,
        ),
        h('details', {}, h('summary', { text: 'Diagnóstico completo' }), json(diagnostics)),
      ),
    );
  }
  return { title: 'Inicio', content: h('div', { className: 'stack' }, blocks) };
};

function tenantForm(ctx: Context, tenant: Tenant | null, close: () => void): HTMLElement {
  return form(
    [
      ...(tenant
        ? []
        : [
            {
              name: 'slug',
              label: 'Identificador (slug)',
              required: true,
              help: 'Minúsculas, números y guiones.',
            },
          ]),
      { name: 'name', label: 'Nombre', required: true, value: tenant?.name },
      {
        name: 'status',
        label: 'Estado',
        type: 'select',
        options: [
          ['active', 'Activo'],
          ['suspended', 'Suspendido'],
        ],
        value: tenant?.status ?? 'active',
      },
      { name: 'settings', label: 'Ajustes (JSON)', type: 'json', value: tenant?.settings ?? {}, rows: 6 },
    ],
    {
      submitLabel: tenant ? 'Guardar' : 'Crear tenant',
      onCancel: close,
      onSubmit: async (values) => {
        if (tenant) await ctx.api.patch(`/v1/admin/tenants/${tenant.id}`, values);
        else await ctx.api.post('/v1/admin/tenants', values);
        close();
        notify(tenant ? 'Tenant actualizado.' : 'Tenant creado.');
        ctx.reload();
      },
    },
  );
}

export const tenantsPage: Page = async (ctx) => {
  const { items } = await ctx.api.get<{ items: Tenant[] }>('/v1/admin/tenants');
  return {
    title: 'Tenants',
    content: h(
      'div',
      { className: 'stack' },
      toolbar(
        button('Nuevo tenant', () => dialog('Nuevo tenant', (close) => tenantForm(ctx, null, close)), {
          variant: 'primary',
        }),
      ),
      table(
        'Tenants',
        [
          { label: 'Nombre', render: (t) => t.name },
          { label: 'Slug', render: (t) => h('code', { text: t.slug }) },
          { label: 'Estado', render: (t) => badge(t.status) },
          { label: 'Alta', render: (t) => formatDate(t.created_at) },
          {
            label: 'Acciones',
            render: (t) => [
              button(
                'Editar',
                () => dialog(`Editar tenant ${t.name}`, (close) => tenantForm(ctx, t, close)),
                { ariaLabel: `Editar ${t.name}` },
              ),
              h('a', { className: 'btn btn-link', href: `#/t/${t.id}/assistants`, text: 'Abrir' }),
            ],
          },
        ],
        items,
      ),
    ),
  };
};

export const accountPage: Page = async (ctx) => ({
  title: 'Mi cuenta',
  content: section(
    'Cambiar contraseña',
    form(
      [
        {
          name: 'current_password',
          label: 'Contraseña actual',
          type: 'password',
          required: true,
          autocomplete: 'current-password',
        },
        {
          name: 'new_password',
          label: 'Contraseña nueva',
          type: 'password',
          required: true,
          help: 'Mínimo 12 caracteres, combinando mayúsculas, minúsculas y números.',
        },
      ],
      {
        submitLabel: 'Cambiar contraseña',
        onSubmit: async (values) => {
          await ctx.api.post('/v1/admin/me/password', values);
          notify('Contraseña actualizada.');
        },
      },
    ),
  ),
});
