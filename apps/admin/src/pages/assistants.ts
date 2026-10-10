/** Assistants (borrador, publicación, versiones y rollback) y políticas de agente (CG-03). */
import type { Context, Page } from '../app';
import {
  badge,
  button,
  confirm,
  dialog,
  form,
  formatDate,
  h,
  json,
  list,
  notify,
  section,
  table,
  toolbar,
} from '../ui';

interface AgentConfig {
  id: string;
  key: string;
  name: string;
  policy: Record<string, unknown>;
  updated_at: string;
}

interface AssistantSummary {
  id: string;
  key: string;
  name: string;
  status: string;
  published_version: number | null;
  agent_config_id: string | null;
  updated_at: string;
}

interface AssistantVersion {
  id: string;
  version: number;
  note: string;
  created_by: string;
  created_at: string;
}

interface AssistantDetail extends AssistantSummary {
  draft_config: Record<string, unknown>;
  effective_config: Record<string, unknown>;
  versions: AssistantVersion[];
}

interface SkillEntry {
  name: string;
  enabled: boolean;
  available: boolean;
  version: string | null;
  permissions: string[];
  capabilities: string[];
}

function base(ctx: Context): string {
  return `/v1/admin/tenants/${encodeURIComponent(ctx.tenantId)}`;
}

export const assistantsPage: Page = async (ctx) => {
  const [{ items }, agents] = await Promise.all([
    ctx.api.get<{ items: AssistantSummary[] }>(`${base(ctx)}/assistants`),
    ctx.api.get<{ items: AgentConfig[] }>(`${base(ctx)}/agent-configs`).then((r) => r.items),
  ]);
  const agentName = (id: string | null) => agents.find((a) => a.id === id)?.name ?? '—';
  const create = () =>
    dialog(
      'Nuevo assistant',
      (close) =>
        form(
          [
            {
              name: 'key',
              label: 'Clave',
              required: true,
              help: 'Identificador estable usado en data-assistant-id.',
            },
            { name: 'name', label: 'Nombre', required: true },
            {
              name: 'agent_config',
              label: 'Política de agente',
              type: 'select',
              options: [['', 'Por defecto'], ...agents.map((a): [string, string] => [a.key, a.name])],
              nullable: true,
            },
            {
              name: 'config',
              label: 'Configuración inicial (JSON)',
              type: 'json',
              value: {},
              rows: 8,
              help: 'Se completa con los valores por defecto: modelo, límites, mensajes, skills y bases de conocimiento.',
            },
          ],
          {
            submitLabel: 'Crear borrador',
            onCancel: close,
            onSubmit: async (values) => {
              const created = await ctx.api.post<AssistantSummary>(`${base(ctx)}/assistants`, values);
              close();
              notify('Assistant creado como borrador.');
              ctx.go(ctx.t(`assistants/${created.id}`));
            },
          },
        ),
      { wide: true },
    );
  return {
    title: 'Assistants',
    content: h(
      'div',
      { className: 'stack' },
      h('p', {
        className: 'muted',
        text: 'Cada cambio se edita en borrador y se publica como versión inmutable. Los widgets usan siempre la versión publicada.',
      }),
      ctx.can('config.write') ? toolbar(button('Nuevo assistant', create, { variant: 'primary' })) : null,
      table(
        'Assistants',
        [
          {
            label: 'Assistant',
            render: (a) => [
              h('a', { href: ctx.t(`assistants/${a.id}`) }, h('strong', { text: a.name })),
              h('br'),
              h('code', { text: a.key }),
            ],
          },
          { label: 'Estado', render: (a) => badge(a.status) },
          {
            label: 'Versión publicada',
            render: (a) => (a.published_version ? `v${a.published_version}` : '—'),
          },
          { label: 'Política de agente', render: (a) => agentName(a.agent_config_id) },
          { label: 'Actualizado', render: (a) => formatDate(a.updated_at) },
        ],
        items,
        'Todavía no hay assistants.',
      ),
    ),
  };
};

export const assistantDetailPage: Page = async (ctx) => {
  const id = ctx.params.aid!;
  const path = `${base(ctx)}/assistants/${encodeURIComponent(id)}`;
  const [assistant, agents, skills] = await Promise.all([
    ctx.api.get<AssistantDetail>(path),
    ctx.api.get<{ items: AgentConfig[] }>(`${base(ctx)}/agent-configs`).then((r) => r.items),
    ctx.api.get<{ assistant_version: number; items: SkillEntry[] }>(`${path}/skills`).catch(() => null),
  ]);
  const canWrite = ctx.can('config.write');
  const canPublish = ctx.can('config.publish');
  const agentKey = agents.find((a) => a.id === assistant.agent_config_id)?.key ?? '';

  const draft = canWrite
    ? form(
        [
          { name: 'name', label: 'Nombre', required: true, value: assistant.name },
          {
            name: 'agent_config',
            label: 'Política de agente',
            type: 'select',
            options: [['', 'Por defecto'], ...agents.map((a): [string, string] => [a.key, a.name])],
            value: agentKey,
            nullable: true,
          },
          {
            name: 'config',
            label: 'Configuración del borrador (JSON)',
            type: 'json',
            value: assistant.draft_config,
            rows: 22,
            help: 'Instrucciones, ámbito, modelo, límites, mensajes, skills (con su configuración), bases de conocimiento, sugerencias e historial. Se valida contra assistant-config v1.',
          },
        ],
        {
          submitLabel: 'Guardar borrador',
          onSubmit: async (values) => {
            await ctx.api.patch(path, values);
            notify('Borrador guardado. Publica para que los widgets lo usen.');
            ctx.reload();
          },
        },
      )
    : json(assistant.draft_config);

  const publish = canPublish
    ? form(
        [{ name: 'note', label: 'Nota de la versión', help: 'Qué cambia respecto a la versión anterior.' }],
        {
          submitLabel: 'Publicar borrador',
          onSubmit: async (values) => {
            const version = await ctx.api.post<{ version: number }>(`${path}/publish`, values);
            notify(`Publicada la versión ${version.version}.`);
            ctx.reload();
          },
        },
      )
    : null;

  const viewVersion = async (version: number) => {
    const data = await ctx.api.get<Record<string, unknown>>(`${path}/versions/${version}`);
    dialog(
      `Versión ${version} de ${assistant.name}`,
      () => h('div', { className: 'dialog-body' }, json(data)),
      { wide: true },
    );
  };

  const restore = async (version: number) => {
    const ok = await confirm(
      'Restaurar versión',
      `El borrador se sustituirá por la versión ${version} y se publicará como versión nueva.`,
      'Restaurar y publicar',
    );
    if (!ok) return;
    const result = await ctx.api.post<{ published_version_created: number | null }>(`${path}/restore`, {
      version,
      publish: true,
    });
    notify(`Versión ${version} restaurada y publicada como v${result.published_version_created ?? '?'}.`);
    ctx.reload();
  };

  return {
    title: `Assistant ${assistant.name}`,
    content: h(
      'div',
      { className: 'stack' },
      h('p', {}, h('a', { href: ctx.t('assistants'), text: '← Volver a assistants' })),
      section(
        'Resumen',
        h(
          'dl',
          { className: 'facts' },
          h('dt', { text: 'Clave' }),
          h('dd', {}, h('code', { text: assistant.key })),
          h('dt', { text: 'Estado' }),
          h('dd', {}, badge(assistant.status)),
          h('dt', { text: 'Versión publicada' }),
          h('dd', { text: assistant.published_version ? `v${assistant.published_version}` : 'Sin publicar' }),
          h('dt', { text: 'Política de agente' }),
          h('dd', { text: agents.find((a) => a.id === assistant.agent_config_id)?.name ?? 'Por defecto' }),
        ),
      ),
      section('Borrador', draft),
      publish
        ? section(
            'Publicar',
            h('p', {
              className: 'muted',
              text: 'Se comprueba la compatibilidad de las skills antes de publicar.',
            }),
            publish,
          )
        : null,
      section(
        'Versiones',
        table(
          'Versiones publicadas',
          [
            { label: 'Versión', render: (v) => `v${v.version}`, numeric: true },
            { label: 'Nota', render: (v) => v.note || '—' },
            { label: 'Autor', render: (v) => v.created_by },
            { label: 'Fecha', render: (v) => formatDate(v.created_at) },
            {
              label: 'Acciones',
              render: (v) => [
                button('Ver', () => viewVersion(v.version), { ariaLabel: `Ver versión ${v.version}` }),
                canPublish && v.version !== assistant.published_version
                  ? button('Restaurar', () => restore(v.version), {
                      ariaLabel: `Restaurar versión ${v.version}`,
                    })
                  : null,
              ],
            },
          ],
          [...assistant.versions].sort((a, b) => b.version - a.version),
          'Aún no se ha publicado ninguna versión.',
        ),
      ),
      skills
        ? section(
            `Skills de la versión publicada (v${skills.assistant_version})`,
            table(
              'Skills',
              [
                { label: 'Skill', render: (s) => h('code', { text: s.name }) },
                { label: 'Activa', render: (s) => (s.enabled ? 'Sí' : 'No') },
                { label: 'Disponible', render: (s) => badge(s.available ? 'available' : 'error') },
                { label: 'Versión', render: (s) => s.version ?? '—' },
                { label: 'Permisos', render: (s) => list(s.permissions) },
                { label: 'Capacidades', render: (s) => list(s.capabilities) },
              ],
              skills.items,
            ),
          )
        : null,
      section(
        'Configuración efectiva',
        h(
          'details',
          {},
          h('summary', { text: 'Ver la configuración completa con valores por defecto' }),
          json(assistant.effective_config),
        ),
      ),
    ),
  };
};

export const agentsPage: Page = async (ctx) => {
  const data = await ctx.api.get<{ items: AgentConfig[]; defaults: Record<string, unknown> }>(
    `${base(ctx)}/agent-configs`,
  );
  const edit = (agent: AgentConfig | null) =>
    dialog(
      agent ? `Editar ${agent.name}` : 'Nueva política de agente',
      (close) =>
        form(
          [
            ...(agent ? [] : [{ name: 'key', label: 'Clave', required: true }]),
            { name: 'name', label: 'Nombre', required: true, value: agent?.name },
            {
              name: 'policy',
              label: 'Política (JSON)',
              type: 'json',
              value: agent?.policy ?? data.defaults,
              rows: 16,
              help: 'Iteraciones y herramientas máximas, herramientas permitidas, RAG (amount, max_amount, min_score), reparación, reintento con RAG forzado, detección de bucles y ventana de historial.',
            },
          ],
          {
            submitLabel: agent ? 'Guardar' : 'Crear',
            onCancel: close,
            onSubmit: async (values) => {
              if (agent) await ctx.api.patch(`${base(ctx)}/agent-configs/${agent.id}`, values);
              else await ctx.api.post(`${base(ctx)}/agent-configs`, values);
              close();
              notify('Política guardada. Se aplica al publicar los assistants que la usan.');
              ctx.reload();
            },
          },
        ),
      { wide: true },
    );
  return {
    title: 'Políticas de agente',
    content: h(
      'div',
      { className: 'stack' },
      ctx.can('config.write')
        ? toolbar(button('Nueva política', () => edit(null), { variant: 'primary' }))
        : null,
      table(
        'Políticas de agente',
        [
          {
            label: 'Política',
            render: (a) => [h('strong', { text: a.name }), h('br'), h('code', { text: a.key })],
          },
          { label: 'Iteraciones', render: (a) => String(a.policy.max_iterations ?? '—'), numeric: true },
          { label: 'Herramientas', render: (a) => list(a.policy.allowed_tools) },
          { label: 'Actualizada', render: (a) => formatDate(a.updated_at) },
          {
            label: 'Acciones',
            render: (a) =>
              ctx.can('config.write')
                ? button('Editar', () => edit(a), { ariaLabel: `Editar ${a.name}` })
                : '—',
          },
        ],
        data.items,
      ),
      section('Valores por defecto', json(data.defaults)),
    ),
  };
};
