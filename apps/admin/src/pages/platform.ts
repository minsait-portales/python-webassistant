/** Plataforma (platform_admin): precios versionados, registro de skills, índices vectoriales y uso global. */
import type { Page } from '../app';
import {
  badge,
  button,
  confirm,
  dialog,
  form,
  formatCost,
  formatDate,
  formatNumber,
  h,
  list,
  notify,
  section,
  table,
  toolbar,
} from '../ui';

interface Price {
  id: string;
  provider: string;
  model: string;
  input_per_million: string;
  cached_per_million: string;
  output_per_million: string;
  currency: string;
  valid_from: string;
  valid_to: string | null;
  source: string;
  created_by: string;
}

interface SkillInfo {
  name: string;
  version: string;
  status: string;
  source: string;
  title: string;
  group: string;
  renderer: string;
  requires_rag: boolean;
  permissions: string[];
  capabilities: string[];
  diagnostics: Array<{ message?: string; path?: string } | string>;
}

interface EmbeddingIndex {
  id: string;
  provider: string;
  model: string;
  dimension: number;
  collection: string;
  status: string;
  points: number | null;
  created_at: string;
  activated_at: string | null;
}

interface UsageReport {
  items: Array<Record<string, unknown>>;
  totals: Record<string, unknown>;
}

export const platformPage: Page = async (ctx) => {
  const [prices, skills, indexes, usage, tenants] = await Promise.all([
    ctx.api.get<{ items: Price[] }>('/v1/admin/prices').then((r) => r.items),
    ctx.api.get<{ items: SkillInfo[] }>('/v1/admin/skills').then((r) => r.items),
    ctx.api.get<{ items: EmbeddingIndex[] }>('/v1/admin/embedding-indexes').then((r) => r.items),
    ctx.api.get<UsageReport>('/v1/admin/usage?group_by=tenant'),
    Promise.resolve(ctx.tenants),
  ]);
  const tenantName = (id: unknown) => tenants.find((t) => t.id === id)?.name ?? String(id ?? '—');
  const publishPrice = () =>
    dialog('Publicar precio', (close) =>
      form(
        [
          { name: 'provider', label: 'Proveedor', required: true, value: 'openai' },
          { name: 'model', label: 'Modelo', required: true, placeholder: 'gpt-6-luna' },
          {
            name: 'input_per_million',
            label: 'Entrada por millón',
            type: 'number',
            step: '0.000001',
            min: 0,
            required: true,
          },
          {
            name: 'cached_per_million',
            label: 'Entrada en caché por millón',
            type: 'number',
            step: '0.000001',
            min: 0,
            value: 0,
          },
          {
            name: 'output_per_million',
            label: 'Salida por millón',
            type: 'number',
            step: '0.000001',
            min: 0,
            required: true,
          },
          { name: 'currency', label: 'Moneda', value: 'USD' },
          {
            name: 'source',
            label: 'Fuente',
            help: 'Tarifa oficial, contrato… Queda registrada con el precio.',
          },
        ],
        {
          submitLabel: 'Publicar',
          onCancel: close,
          onSubmit: async (values) => {
            const body = { ...values };
            for (const key of ['input_per_million', 'cached_per_million', 'output_per_million'])
              body[key] = String(body[key] ?? 0);
            await ctx.api.post('/v1/admin/prices', body);
            close();
            notify('Precio publicado. El anterior queda cerrado y los costes históricos no cambian.');
            ctx.reload();
          },
        },
      ),
    );
  const reindex = () =>
    dialog('Reindexar con otro modelo de embeddings', (close) =>
      form(
        [
          { name: 'provider', label: 'Proveedor', required: true, value: 'openai' },
          { name: 'model', label: 'Modelo', required: true, value: 'text-embedding-3-small' },
          { name: 'activate', label: 'Activar automáticamente al terminar', type: 'checkbox', value: false },
        ],
        {
          intro:
            'Se crea una colección nueva y se reindexan todos los documentos sin cortar el servicio; la actual sigue activa hasta validar la nueva.',
          submitLabel: 'Iniciar reindexación',
          onCancel: close,
          onSubmit: async (values) => {
            await ctx.api.post('/v1/admin/embedding-indexes', values);
            close();
            notify('Reindexación encolada en el worker.');
            ctx.reload();
          },
        },
      ),
    );
  return {
    title: 'Plataforma',
    content: h(
      'div',
      { className: 'stack' },
      section(
        'Uso por tenant (30 días)',
        table(
          'Uso por tenant',
          [
            { label: 'Tenant', render: (r) => tenantName(r.tenant) },
            { label: 'Llamadas', render: (r) => formatNumber(r.calls), numeric: true },
            { label: 'Entrada', render: (r) => formatNumber(r.input_tokens), numeric: true },
            { label: 'Salida', render: (r) => formatNumber(r.output_tokens), numeric: true },
            { label: 'Coste', render: (r) => formatCost(r.total_cost), numeric: true },
          ],
          usage.items,
          'Sin consumo.',
        ),
      ),
      section(
        'Precios de modelos',
        toolbar(button('Publicar precio', publishPrice, { variant: 'primary' })),
        table(
          'Precios',
          [
            { label: 'Modelo', render: (p) => [h('strong', { text: p.model }), h('br'), p.provider] },
            {
              label: 'Entrada / M',
              render: (p) => formatCost(p.input_per_million, p.currency),
              numeric: true,
            },
            {
              label: 'Caché / M',
              render: (p) => formatCost(p.cached_per_million, p.currency),
              numeric: true,
            },
            {
              label: 'Salida / M',
              render: (p) => formatCost(p.output_per_million, p.currency),
              numeric: true,
            },
            {
              label: 'Vigencia',
              render: (p) =>
                `${formatDate(p.valid_from)} → ${p.valid_to ? formatDate(p.valid_to) : 'vigente'}`,
            },
            { label: 'Fuente', render: (p) => p.source || '—' },
          ],
          prices,
        ),
      ),
      section(
        'Skills instaladas',
        toolbar(
          button('Volver a descubrir', async () => {
            const result = await ctx.api.post<{ items?: unknown[] }>('/v1/admin/skills/rescan');
            notify(
              `Registro actualizado${Array.isArray(result.items) ? `: ${result.items.length} skills` : ''}.`,
            );
            ctx.reload();
          }),
        ),
        table(
          'Skills',
          [
            { label: 'Skill', render: (s) => [h('code', { text: s.name }), h('br'), s.title] },
            { label: 'Versión', render: (s) => s.version },
            { label: 'Estado', render: (s) => badge(s.status) },
            { label: 'Origen', render: (s) => s.source },
            { label: 'Renderer', render: (s) => s.renderer },
            { label: 'RAG', render: (s) => (s.requires_rag ? 'Sí' : 'No') },
            { label: 'Permisos', render: (s) => list(s.permissions) },
            {
              label: 'Diagnóstico',
              render: (s) =>
                s.diagnostics.length
                  ? s.diagnostics
                      .map((d) => (typeof d === 'string' ? d : `${d.path ?? ''} ${d.message ?? ''}`))
                      .join('; ')
                  : '—',
            },
          ],
          skills,
        ),
      ),
      section(
        'Índices vectoriales',
        toolbar(
          button('Reindexar con otro modelo', reindex, { variant: 'primary' }),
          button('Volver a la versión anterior', async () => {
            if (
              !(await confirm(
                'Rollback del índice',
                'Se reactivará la versión anterior del índice vectorial.',
                'Volver atrás',
                true,
              ))
            )
              return;
            await ctx.api.post('/v1/admin/embedding-indexes/rollback');
            notify('Índice anterior reactivado.');
            ctx.reload();
          }),
        ),
        table(
          'Índices',
          [
            {
              label: 'Modelo',
              render: (i) => [h('strong', { text: i.model }), h('br'), `${i.provider} · ${i.dimension} dims`],
            },
            { label: 'Colección', render: (i) => h('code', { text: i.collection }) },
            { label: 'Estado', render: (i) => badge(i.status) },
            { label: 'Puntos', render: (i) => formatNumber(i.points), numeric: true },
            { label: 'Activado', render: (i) => formatDate(i.activated_at) },
            {
              label: 'Acciones',
              render: (i) =>
                i.status === 'ready'
                  ? button(
                      'Activar',
                      async () => {
                        await ctx.api.post(
                          `/v1/admin/embedding-indexes/${encodeURIComponent(i.id)}/activate`,
                        );
                        notify('Índice activado.');
                        ctx.reload();
                      },
                      { ariaLabel: `Activar índice ${i.collection}` },
                    )
                  : '—',
            },
          ],
          indexes,
        ),
      ),
    ),
  };
};
