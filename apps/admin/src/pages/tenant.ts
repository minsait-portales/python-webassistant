/** Sites con orígenes y tema, clientes API (secreto una sola vez) y usuarios del tenant. */
import type { Context, Page } from '../app';
import {
  badge,
  button,
  confirm,
  dialog,
  form,
  formatDate,
  h,
  list,
  notify,
  revealSecret,
  table,
  toolbar,
} from '../ui';

interface Site {
  id: string;
  public_id: string;
  name: string;
  status: string;
  access_mode: string;
  allowed_origins: string[];
  default_assistant_id: string | null;
  default_locale: string;
  languages: string[];
  theme: Record<string, unknown>;
  settings: Record<string, unknown>;
  capabilities: string[];
}

interface Assistant {
  id: string;
  key: string;
  name: string;
}

interface ApiClient {
  id: string;
  site_id: string | null;
  name: string;
  client_id: string;
  scopes: string[];
  status: string;
  last_used_at: string | null;
  rotated_at: string | null;
  created_at: string;
}

interface AdminUser {
  id: string;
  email: string;
  name: string;
  role: string;
  status: string;
  last_login_at: string | null;
}

const SERVER_SCOPES: Array<[string, string]> = [
  ['token:issue', 'token:issue · emitir tokens de widget para usuarios del portal'],
  ['ingest:write', 'ingest:write · publicar y borrar documentos'],
  ['ingest:read', 'ingest:read · inventario de ingesta'],
  ['search:read', 'search:read · búsqueda server-to-server'],
];

const ROLES: Array<[string, string]> = [
  ['tenant_admin', 'Administrador del tenant'],
  ['editor', 'Editor (assistants, skills y conocimiento)'],
  ['auditor', 'Auditor (auditoría, conversaciones y payloads)'],
  ['viewer', 'Lector'],
];

function base(ctx: Context): string {
  return `/v1/admin/tenants/${encodeURIComponent(ctx.tenantId)}`;
}

function installSnippet(site: Site): string {
  return `<script async src="${window.location.origin}/widget/v1/loader.js"\n  data-site-id="${site.public_id}"></script>`;
}

function siteForm(ctx: Context, site: Site | null, assistants: Assistant[], close: () => void): HTMLElement {
  const assistantOptions: Array<[string, string]> = [
    ['', '— Sin assistant por defecto —'],
    ...assistants.map((a): [string, string] => [a.key, `${a.name} (${a.key})`]),
  ];
  const currentAssistant = assistants.find((a) => a.id === site?.default_assistant_id)?.key ?? '';
  return form(
    [
      ...(site
        ? []
        : [
            {
              name: 'public_id',
              label: 'Identificador público',
              required: true,
              help: 'Se usa en data-site-id. Minúsculas, números, guion y guion bajo.',
            },
          ]),
      { name: 'name', label: 'Nombre', required: true, value: site?.name },
      {
        name: 'allowed_origins',
        label: 'Orígenes permitidos',
        type: 'list',
        required: true,
        value: site?.allowed_origins,
        help: 'Uno por línea, con esquema y sin ruta: https://www.ejemplo.com',
      },
      {
        name: 'access_mode',
        label: 'Acceso',
        type: 'select',
        options: [
          ['public', 'Público (sesiones anónimas)'],
          ['private', 'Privado (token del portal)'],
        ],
        value: site?.access_mode ?? 'public',
      },
      {
        name: 'status',
        label: 'Estado',
        type: 'select',
        options: [
          ['active', 'Activo'],
          ['disabled', 'Desactivado'],
        ],
        value: site?.status ?? 'active',
      },
      {
        name: 'default_assistant',
        label: 'Assistant por defecto',
        type: 'select',
        options: assistantOptions,
        value: currentAssistant,
        nullable: true,
      },
      { name: 'default_locale', label: 'Idioma por defecto', value: site?.default_locale ?? 'es' },
      { name: 'languages', label: 'Idiomas', type: 'list', value: site?.languages ?? ['es'] },
      {
        name: 'capabilities',
        label: 'Capacidades del portal',
        type: 'list',
        value: site?.capabilities ?? [],
        help: 'Por ejemplo: cart, content_forms, user_profile. Las skills que las requieren solo se ofrecen si el site las declara.',
      },
      {
        name: 'theme',
        label: 'Tema (JSON)',
        type: 'json',
        value: site?.theme ?? {},
        rows: 8,
        help: 'Tokens validados: colores hexadecimales, fuente, radio, posición, etiqueta y logo. Se comprueba el contraste WCAG AA.',
      },
      {
        name: 'settings',
        label: 'Ajustes (JSON)',
        type: 'json',
        value: site?.settings ?? {},
        rows: 8,
        help: 'allowed_widget_scopes, anonymous_scopes, link_anonymous_on_login, desktop_always_open, result_target_selector…',
      },
    ],
    {
      submitLabel: site ? 'Guardar site' : 'Registrar site',
      onCancel: close,
      onSubmit: async (values) => {
        if (site) await ctx.api.patch(`${base(ctx)}/sites/${site.id}`, values);
        else await ctx.api.post(`${base(ctx)}/sites`, values);
        close();
        notify(site ? 'Site actualizado.' : 'Site registrado.');
        ctx.reload();
      },
    },
  );
}

export const sitesPage: Page = async (ctx) => {
  const [{ items }, assistants] = await Promise.all([
    ctx.api.get<{ items: Site[] }>(`${base(ctx)}/sites`),
    ctx.can('config.read')
      ? ctx.api.get<{ items: Assistant[] }>(`${base(ctx)}/assistants`).then((r) => r.items)
      : Promise.resolve([] as Assistant[]),
  ]);
  return {
    title: 'Sites',
    content: h(
      'div',
      { className: 'stack' },
      h('p', {
        className: 'muted',
        text: 'Cada site es una web anfitriona con sus orígenes permitidos (CORS), modo de acceso, idiomas, tema y capacidades.',
      }),
      toolbar(
        button(
          'Registrar site',
          () => dialog('Registrar site', (close) => siteForm(ctx, null, assistants, close), { wide: true }),
          { variant: 'primary' },
        ),
      ),
      table(
        'Sites',
        [
          {
            label: 'Site',
            render: (s) => [h('strong', { text: s.name }), h('br'), h('code', { text: s.public_id })],
          },
          { label: 'Estado', render: (s) => badge(s.status) },
          { label: 'Acceso', render: (s) => s.access_mode },
          { label: 'Orígenes', render: (s) => list(s.allowed_origins) },
          { label: 'Idiomas', render: (s) => list(s.languages) },
          {
            label: 'Assistant',
            render: (s) => assistants.find((a) => a.id === s.default_assistant_id)?.name ?? '—',
          },
          {
            label: 'Acciones',
            render: (s) => [
              button(
                'Editar',
                () =>
                  dialog(`Editar site ${s.name}`, (close) => siteForm(ctx, s, assistants, close), {
                    wide: true,
                  }),
                { ariaLabel: `Editar ${s.name}` },
              ),
              button(
                'Instalación',
                () =>
                  dialog(`Instalación de ${s.name}`, () =>
                    h(
                      'div',
                      { className: 'dialog-body' },
                      h('p', {
                        text: 'Añade esta etiqueta a las páginas del portal. Las host actions y eventos se registran con window.WebAssistant.',
                      }),
                      h('pre', { className: 'json', tabindex: '0', text: installSnippet(s) }),
                      button('Copiar', async () => {
                        await navigator.clipboard?.writeText(installSnippet(s));
                        notify('Código copiado.');
                      }),
                    ),
                  ),
                { ariaLabel: `Código de instalación de ${s.name}` },
              ),
            ],
          },
        ],
        items,
        'Todavía no hay sites registrados.',
      ),
    ),
  };
};

export const clientsPage: Page = async (ctx) => {
  const [{ items }, sites] = await Promise.all([
    ctx.api.get<{ items: ApiClient[] }>(`${base(ctx)}/api-clients`),
    ctx.api.get<{ items: Site[] }>(`${base(ctx)}/sites`).then((r) => r.items),
  ]);
  const siteName = (id: string | null) => sites.find((s) => s.id === id)?.name ?? 'Todo el tenant';
  const create = () =>
    dialog('Nuevo cliente API', (close) =>
      form(
        [
          { name: 'name', label: 'Nombre', required: true, help: 'Por ejemplo: Portal Drupal producción.' },
          {
            name: 'site_id',
            label: 'Site',
            type: 'select',
            options: [
              ['', 'Todo el tenant'],
              ...sites.map((s): [string, string] => [s.id, `${s.name} (${s.public_id})`]),
            ],
            nullable: true,
          },
          {
            name: 'scopes',
            label: 'Permisos',
            type: 'checkboxes',
            options: SERVER_SCOPES,
            value: ['token:issue', 'ingest:write', 'ingest:read'],
            required: true,
          },
        ],
        {
          submitLabel: 'Crear cliente',
          onCancel: close,
          onSubmit: async (values) => {
            const created = await ctx.api.post<{ client_id: string; client_secret: string }>(
              `${base(ctx)}/api-clients`,
              values,
            );
            close();
            ctx.reload();
            revealSecret(`Cliente ${created.client_id} creado`, 'Secreto del cliente', created.client_secret);
          },
        },
      ),
    );
  return {
    title: 'Clientes API',
    content: h(
      'div',
      { className: 'stack' },
      h('p', {
        className: 'muted',
        text: 'Credenciales server-to-server para los portales: emitir tokens de widget, ingesta y búsqueda. El secreto solo se muestra al crearlo o rotarlo.',
      }),
      toolbar(button('Nuevo cliente', create, { variant: 'primary' })),
      table(
        'Clientes API',
        [
          {
            label: 'Cliente',
            render: (c) => [h('strong', { text: c.name }), h('br'), h('code', { text: c.client_id })],
          },
          { label: 'Site', render: (c) => siteName(c.site_id) },
          { label: 'Permisos', render: (c) => list(c.scopes) },
          { label: 'Estado', render: (c) => badge(c.status) },
          { label: 'Último uso', render: (c) => formatDate(c.last_used_at) },
          { label: 'Rotado', render: (c) => formatDate(c.rotated_at) },
          {
            label: 'Acciones',
            render: (c) =>
              c.status === 'active'
                ? [
                    button(
                      'Rotar secreto',
                      async () => {
                        if (
                          !(await confirm(
                            'Rotar secreto',
                            `El secreto actual de ${c.client_id} dejará de funcionar. ¿Continuar?`,
                            'Rotar',
                          ))
                        )
                          return;
                        const rotated = await ctx.api.post<{ client_secret: string }>(
                          `${base(ctx)}/api-clients/${c.id}/rotate`,
                        );
                        ctx.reload();
                        revealSecret(
                          `Nuevo secreto de ${c.client_id}`,
                          'Secreto del cliente',
                          rotated.client_secret,
                        );
                      },
                      { ariaLabel: `Rotar secreto de ${c.name}` },
                    ),
                    button(
                      'Revocar',
                      async () => {
                        if (
                          !(await confirm(
                            'Revocar cliente',
                            `${c.client_id} no podrá volver a autenticarse.`,
                            'Revocar',
                            true,
                          ))
                        )
                          return;
                        await ctx.api.post(`${base(ctx)}/api-clients/${c.id}/revoke`);
                        notify('Cliente revocado.');
                        ctx.reload();
                      },
                      { variant: 'danger', ariaLabel: `Revocar ${c.name}` },
                    ),
                  ]
                : '—',
          },
        ],
        items,
        'No hay clientes API.',
      ),
    ),
  };
};

export const usersPage: Page = async (ctx) => {
  const { items } = await ctx.api.get<{ items: AdminUser[] }>(`${base(ctx)}/users`);
  const create = () =>
    dialog('Nuevo usuario', (close) =>
      form(
        [
          { name: 'email', label: 'Correo electrónico', type: 'email', required: true },
          { name: 'name', label: 'Nombre' },
          { name: 'role', label: 'Rol', type: 'select', options: ROLES, value: 'viewer', required: true },
          {
            name: 'password',
            label: 'Contraseña inicial',
            type: 'password',
            required: true,
            help: 'Mínimo 12 caracteres, combinando mayúsculas, minúsculas y números.',
          },
        ],
        {
          submitLabel: 'Crear usuario',
          onCancel: close,
          onSubmit: async (values) => {
            await ctx.api.post(`${base(ctx)}/users`, values);
            close();
            notify('Usuario creado.');
            ctx.reload();
          },
        },
      ),
    );
  const edit = (user: AdminUser) =>
    dialog(`Editar ${user.email}`, (close) =>
      form(
        [
          { name: 'name', label: 'Nombre', value: user.name },
          { name: 'role', label: 'Rol', type: 'select', options: ROLES, value: user.role },
          {
            name: 'status',
            label: 'Estado',
            type: 'select',
            options: [
              ['active', 'Activo'],
              ['disabled', 'Desactivado'],
            ],
            value: user.status,
          },
        ],
        {
          submitLabel: 'Guardar',
          onCancel: close,
          onSubmit: async (values) => {
            await ctx.api.patch(`${base(ctx)}/users/${user.id}`, values);
            close();
            notify('Usuario actualizado.');
            ctx.reload();
          },
        },
      ),
    );
  return {
    title: 'Usuarios de administración',
    content: h(
      'div',
      { className: 'stack' },
      toolbar(button('Nuevo usuario', create, { variant: 'primary' })),
      table(
        'Usuarios',
        [
          { label: 'Usuario', render: (u) => [h('strong', { text: u.name || u.email }), h('br'), u.email] },
          { label: 'Rol', render: (u) => u.role },
          { label: 'Estado', render: (u) => badge(u.status) },
          { label: 'Último acceso', render: (u) => formatDate(u.last_login_at) },
          {
            label: 'Acciones',
            render: (u) => button('Editar', () => edit(u), { ariaLabel: `Editar ${u.email}` }),
          },
        ],
        items,
        'No hay usuarios en este tenant.',
      ),
    ),
  };
};
