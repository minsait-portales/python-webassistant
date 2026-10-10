/** Bases de conocimiento, estado de ingesta por documento, reintentos y conciliación (CG-07). */
import type { Context, Page } from '../app';
import { badge, button, dialog, form, formatDate, formatNumber, h, notify, table, toolbar } from '../ui';

interface KnowledgeBase {
  id: string;
  key: string;
  name: string;
  description: string;
  site_id: string | null;
  default_locale: string;
  chunking: Record<string, unknown>;
  status: string;
  documents: Record<string, number>;
  updated_at: string;
}

interface DocumentRow {
  document_id: string;
  source_id: string;
  type: string;
  title: string;
  locale: string;
  version: string;
  status: string;
  error: string | null;
  attempts: number;
  chunk_count: number;
  acl: string[];
  canonical_url: string | null;
  indexed_at: string | null;
}

const PAGE_SIZE = 50;

function base(ctx: Context): string {
  return `/v1/admin/tenants/${encodeURIComponent(ctx.tenantId)}`;
}

function kbForm(ctx: Context, kb: KnowledgeBase | null, close: () => void): HTMLElement {
  return form(
    [
      ...(kb
        ? []
        : [
            {
              name: 'key',
              label: 'Clave',
              required: true,
              help: 'Se usa en la ingesta (knowledge_base) y en la configuración del assistant.',
            },
          ]),
      { name: 'name', label: 'Nombre', required: true, value: kb?.name },
      { name: 'description', label: 'Descripción', type: 'textarea', value: kb?.description ?? '' },
      { name: 'default_locale', label: 'Idioma por defecto', value: kb?.default_locale ?? 'es' },
      {
        name: 'chunking',
        label: 'Troceado (JSON)',
        type: 'json',
        value: kb?.chunking ?? { strategy: 'heading', max_chars: 900, overlap_chars: 100 },
        rows: 6,
        help: 'strategy: heading | paragraph | whole; max_chars; overlap_chars. Los cambios aplican a las siguientes indexaciones.',
      },
      ...(kb
        ? [
            {
              name: 'status',
              label: 'Estado',
              type: 'select' as const,
              options: [
                ['active', 'Activa'],
                ['disabled', 'Desactivada'],
              ] as Array<[string, string]>,
              value: kb.status,
            },
          ]
        : []),
    ],
    {
      submitLabel: kb ? 'Guardar' : 'Crear base de conocimiento',
      onCancel: close,
      onSubmit: async (values) => {
        if (kb) await ctx.api.patch(`${base(ctx)}/knowledge-bases/${kb.id}`, values);
        else await ctx.api.post(`${base(ctx)}/knowledge-bases`, values);
        close();
        notify('Base de conocimiento guardada.');
        ctx.reload();
      },
    },
  );
}

function stats(documents: Record<string, number>): string {
  const entries = Object.entries(documents ?? {});
  return entries.length
    ? entries.map(([status, count]) => `${status}: ${count}`).join(' · ')
    : 'Sin documentos';
}

export const knowledgePage: Page = async (ctx) => {
  const { items } = await ctx.api.get<{ items: KnowledgeBase[] }>(`${base(ctx)}/knowledge-bases`);
  const canWrite = ctx.can('knowledge.write');
  return {
    title: 'Bases de conocimiento',
    content: h(
      'div',
      { className: 'stack' },
      h('p', {
        className: 'muted',
        text: 'Los portales publican documentos canónicos por la API de ingesta; aquí se supervisa su indexación en PostgreSQL y Qdrant.',
      }),
      canWrite
        ? toolbar(
            button(
              'Nueva base de conocimiento',
              () => dialog('Nueva base de conocimiento', (close) => kbForm(ctx, null, close), { wide: true }),
              { variant: 'primary' },
            ),
            button('Conciliar PostgreSQL y Qdrant', async () => {
              await ctx.api.post(`${base(ctx)}/reconcile`);
              notify('Conciliación encolada en el worker.');
            }),
          )
        : null,
      table(
        'Bases de conocimiento',
        [
          {
            label: 'Base',
            render: (kb) => [
              h('a', { href: ctx.t(`knowledge/${kb.id}`) }, h('strong', { text: kb.name })),
              h('br'),
              h('code', { text: kb.key }),
            ],
          },
          { label: 'Estado', render: (kb) => badge(kb.status) },
          { label: 'Documentos', render: (kb) => stats(kb.documents) },
          { label: 'Troceado', render: (kb) => String(kb.chunking.strategy ?? '—') },
          { label: 'Actualizada', render: (kb) => formatDate(kb.updated_at) },
          {
            label: 'Acciones',
            render: (kb) => [
              h('a', {
                className: 'btn btn-link',
                href: ctx.t(`knowledge/${kb.id}`),
                'aria-label': `Documentos de ${kb.name}`,
                text: 'Documentos',
              }),
              canWrite
                ? button(
                    'Editar',
                    () => dialog(`Editar ${kb.name}`, (close) => kbForm(ctx, kb, close), { wide: true }),
                    { ariaLabel: `Editar ${kb.name}` },
                  )
                : null,
            ],
          },
        ],
        items,
        'No hay bases de conocimiento.',
      ),
    ),
  };
};

export const documentsPage: Page = async (ctx) => {
  const kbId = ctx.params.kbid!;
  const status = ctx.query.get('status') ?? '';
  const offset = Math.max(0, Number(ctx.query.get('offset')) || 0);
  const params = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(offset) });
  if (status) params.set('status', status);
  const [kbs, docs] = await Promise.all([
    ctx.api.get<{ items: KnowledgeBase[] }>(`${base(ctx)}/knowledge-bases`).then((r) => r.items),
    ctx.api.get<{ items: DocumentRow[] }>(
      `${base(ctx)}/knowledge-bases/${encodeURIComponent(kbId)}/documents?${params.toString()}`,
    ),
  ]);
  const kb = kbs.find((k) => k.id === kbId);
  const canWrite = ctx.can('knowledge.write');
  const here = (next: Record<string, string | number>) => {
    const query = new URLSearchParams({
      ...(status ? { status } : {}),
      offset: String(offset),
      ...Object.fromEntries(Object.entries(next).map(([k, v]) => [k, String(v)])),
    });
    if (!query.get('status')) query.delete('status');
    return `${ctx.t(`knowledge/${kbId}`)}?${query.toString()}`;
  };
  const filter = h('select', { id: 'doc-status' }, [
    h('option', { value: '', text: 'Todos' }),
    ...['queued', 'processing', 'ready', 'failed', 'deleted'].map((s) => h('option', { value: s, text: s })),
  ]);
  filter.value = status;
  filter.addEventListener('change', () => ctx.go(here({ status: filter.value, offset: 0 })));
  return {
    title: `Documentos · ${kb?.name ?? kbId}`,
    content: h(
      'div',
      { className: 'stack' },
      h('p', {}, h('a', { href: ctx.t('knowledge'), text: '← Volver a bases de conocimiento' })),
      toolbar(
        h('label', { for: 'doc-status', text: 'Estado' }),
        filter,
        canWrite
          ? button('Reintentar fallidos', async () => {
              const result = await ctx.api.post<{ queued: number }>(
                `${base(ctx)}/knowledge-bases/${encodeURIComponent(kbId)}/retry`,
              );
              notify(`${result.queued} documentos encolados para reintento.`);
              ctx.reload();
            })
          : null,
      ),
      table(
        'Documentos',
        [
          {
            label: 'Documento',
            render: (d) => [h('strong', { text: d.title }), h('br'), h('code', { text: d.source_id })],
          },
          { label: 'Tipo', render: (d) => d.type },
          { label: 'Idioma', render: (d) => d.locale },
          { label: 'Estado', render: (d) => badge(d.status) },
          { label: 'Fragmentos', render: (d) => formatNumber(d.chunk_count), numeric: true },
          { label: 'Intentos', render: (d) => formatNumber(d.attempts), numeric: true },
          { label: 'ACL', render: (d) => d.acl.join(', ') },
          { label: 'Indexado', render: (d) => formatDate(d.indexed_at) },
          { label: 'Error', render: (d) => d.error ?? '—' },
          {
            label: 'Acciones',
            render: (d) =>
              canWrite
                ? button(
                    'Reindexar',
                    async () => {
                      await ctx.api.post(
                        `${base(ctx)}/knowledge-bases/${encodeURIComponent(kbId)}/documents/${encodeURIComponent(d.document_id)}/reindex`,
                      );
                      notify(`Reindexación de «${d.title}» encolada.`);
                    },
                    { ariaLabel: `Reindexar ${d.title}` },
                  )
                : '—',
          },
        ],
        docs.items,
        'No hay documentos con este filtro.',
      ),
      h(
        'nav',
        { className: 'pager', 'aria-label': 'Paginación de documentos' },
        offset > 0
          ? h('a', { href: here({ offset: Math.max(0, offset - PAGE_SIZE) }), text: '← Anteriores' })
          : null,
        docs.items.length === PAGE_SIZE
          ? h('a', { href: here({ offset: offset + PAGE_SIZE }), text: 'Siguientes →' })
          : null,
      ),
    ),
  };
};
