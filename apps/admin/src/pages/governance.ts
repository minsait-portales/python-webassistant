/**
 * Gobierno (CG-12): políticas y presupuestos, retención, informes de uso y coste con CSV,
 * auditoría, trazabilidad de conversaciones y exportación/importación de configuración.
 */
import type { Context, Page } from '../app';
import {
  badge,
  button,
  confirm,
  dialog,
  download,
  form,
  formatCost,
  formatDate,
  formatNumber,
  h,
  json,
  list,
  notify,
  section,
  table,
  toolbar,
} from '../ui';

function base(ctx: Context): string {
  return `/v1/admin/tenants/${encodeURIComponent(ctx.tenantId)}`;
}

interface Policy {
  id: string;
  assistant_id: string | null;
  allowed_models: string[];
  max_input_tokens_per_run: number | null;
  max_output_tokens_per_call: number | null;
  daily_budget: string | null;
  monthly_budget: string | null;
  currency: string;
  alert_threshold_pct: number;
  max_concurrent_runs: number | null;
  max_concurrent_runs_per_subject: number;
  rate_limit_subject_per_minute: number | null;
  rate_limit_site_per_minute: number | null;
}

interface Retention {
  conversations_days: number;
  payloads_days: number;
  audit_days: number;
  usage_days: number;
  mode: string;
  last_applied_at: string | null;
}

interface AssistantRef {
  id: string;
  key: string;
  name: string;
}

export const governancePage: Page = async (ctx) => {
  const [policies, retention, assistants] = await Promise.all([
    ctx.api.get<{ items: Policy[] }>(`${base(ctx)}/policies`).then((r) => r.items),
    ctx.api.get<Retention>(`${base(ctx)}/retention`),
    ctx.can('config.read')
      ? ctx.api.get<{ items: AssistantRef[] }>(`${base(ctx)}/assistants`).then((r) => r.items)
      : Promise.resolve([] as AssistantRef[]),
  ]);
  const assistantName = (id: string | null) =>
    id ? (assistants.find((a) => a.id === id)?.name ?? id) : 'Todo el tenant';
  const editPolicy = (policy: Policy | null) =>
    dialog(
      policy ? `Política de ${assistantName(policy.assistant_id)}` : 'Nueva política',
      (close) =>
        form(
          [
            {
              name: 'assistant',
              label: 'Ámbito',
              type: 'select',
              options: [['', 'Todo el tenant'], ...assistants.map((a): [string, string] => [a.key, a.name])],
              value: policy?.assistant_id
                ? (assistants.find((a) => a.id === policy.assistant_id)?.key ?? '')
                : '',
              nullable: true,
            },
            {
              name: 'allowed_models',
              label: 'Modelos permitidos',
              type: 'list',
              value: policy?.allowed_models ?? [],
              help: 'Vacío = todos los modelos con precio publicado.',
            },
            {
              name: 'daily_budget',
              label: 'Presupuesto diario',
              type: 'number',
              step: '0.01',
              min: 0,
              value: policy?.daily_budget,
              nullable: true,
            },
            {
              name: 'monthly_budget',
              label: 'Presupuesto mensual',
              type: 'number',
              step: '0.01',
              min: 0,
              value: policy?.monthly_budget,
              nullable: true,
            },
            { name: 'currency', label: 'Moneda', value: policy?.currency ?? 'USD' },
            {
              name: 'alert_threshold_pct',
              label: 'Umbral de alerta (%)',
              type: 'number',
              min: 1,
              max: 100,
              value: policy?.alert_threshold_pct ?? 80,
            },
            {
              name: 'max_input_tokens_per_run',
              label: 'Máx. tokens de entrada por run',
              type: 'number',
              min: 1,
              value: policy?.max_input_tokens_per_run,
              nullable: true,
            },
            {
              name: 'max_output_tokens_per_call',
              label: 'Máx. tokens de salida por llamada',
              type: 'number',
              min: 1,
              value: policy?.max_output_tokens_per_call,
              nullable: true,
            },
            {
              name: 'max_concurrent_runs',
              label: 'Runs concurrentes del tenant',
              type: 'number',
              min: 1,
              value: policy?.max_concurrent_runs,
              nullable: true,
            },
            {
              name: 'max_concurrent_runs_per_subject',
              label: 'Runs concurrentes por usuario',
              type: 'number',
              min: 1,
              value: policy?.max_concurrent_runs_per_subject ?? 2,
            },
            {
              name: 'rate_limit_subject_per_minute',
              label: 'Peticiones por usuario y minuto',
              type: 'number',
              min: 1,
              value: policy?.rate_limit_subject_per_minute,
              nullable: true,
            },
            {
              name: 'rate_limit_site_per_minute',
              label: 'Peticiones por site y minuto',
              type: 'number',
              min: 1,
              value: policy?.rate_limit_site_per_minute,
              nullable: true,
            },
          ],
          {
            submitLabel: 'Guardar política',
            onCancel: close,
            onSubmit: async (values) => {
              const body = { ...values };
              for (const key of ['daily_budget', 'monthly_budget'])
                if (body[key] !== null) body[key] = String(body[key]);
              await ctx.api.put(`${base(ctx)}/policies`, body);
              close();
              notify('Política guardada.');
              ctx.reload();
            },
          },
        ),
      { wide: true },
    );
  return {
    title: 'Políticas y retención',
    content: h(
      'div',
      { className: 'stack' },
      section(
        'Límites, presupuestos y alertas',
        toolbar(button('Nueva política', () => editPolicy(null), { variant: 'primary' })),
        table(
          'Políticas',
          [
            { label: 'Ámbito', render: (p) => assistantName(p.assistant_id) },
            { label: 'Modelos', render: (p) => list(p.allowed_models) },
            { label: 'Diario', render: (p) => formatCost(p.daily_budget, p.currency), numeric: true },
            { label: 'Mensual', render: (p) => formatCost(p.monthly_budget, p.currency), numeric: true },
            { label: 'Alerta', render: (p) => `${p.alert_threshold_pct} %`, numeric: true },
            {
              label: 'Concurrencia',
              render: (p) =>
                `${p.max_concurrent_runs ?? '∞'} / ${p.max_concurrent_runs_per_subject} por usuario`,
            },
            {
              label: 'Acciones',
              render: (p) =>
                button('Editar', () => editPolicy(p), {
                  ariaLabel: `Editar política de ${assistantName(p.assistant_id)}`,
                }),
            },
          ],
          policies,
          'Sin políticas: se aplican los límites globales.',
        ),
      ),
      section(
        'Retención de datos',
        h('p', {
          className: 'muted',
          text: `Última aplicación: ${formatDate(retention.last_applied_at)}. El worker la aplica cada noche.`,
        }),
        form(
          [
            {
              name: 'conversations_days',
              label: 'Conversaciones (días)',
              type: 'number',
              min: 1,
              required: true,
              value: retention.conversations_days,
            },
            {
              name: 'payloads_days',
              label: 'Payloads técnicos cifrados (días)',
              type: 'number',
              min: 1,
              required: true,
              value: retention.payloads_days,
            },
            {
              name: 'audit_days',
              label: 'Auditoría (días)',
              type: 'number',
              min: 30,
              required: true,
              value: retention.audit_days,
            },
            {
              name: 'usage_days',
              label: 'Uso y costes (días)',
              type: 'number',
              min: 30,
              required: true,
              value: retention.usage_days,
            },
            {
              name: 'mode',
              label: 'Al caducar',
              type: 'select',
              options: [
                ['anonymize', 'Anonimizar'],
                ['delete', 'Borrar'],
              ],
              value: retention.mode,
            },
          ],
          {
            submitLabel: 'Guardar retención',
            onSubmit: async (values) => {
              await ctx.api.put(`${base(ctx)}/retention`, values);
              notify('Retención guardada.');
              ctx.reload();
            },
          },
        ),
        toolbar(
          button('Aplicar ahora', async () => {
            if (
              !(await confirm(
                'Aplicar retención',
                'Se anonimizarán o borrarán ahora los datos que superen los plazos configurados.',
                'Aplicar',
                true,
              ))
            )
              return;
            await ctx.api.post(`${base(ctx)}/retention/apply`);
            notify('Retención encolada en el worker.');
          }),
        ),
      ),
    ),
  };
};

// --- Uso y costes ---------------------------------------------------------------------

const DIMENSIONS: Array<[string, string]> = [
  ['day', 'Día'],
  ['assistant', 'Assistant'],
  ['model', 'Modelo'],
  ['provider', 'Proveedor'],
  ['operation', 'Operación'],
  ['skill', 'Skill'],
  ['site', 'Site'],
  ['status', 'Estado'],
];

interface UsageReport {
  from: string;
  to: string;
  group_by: string[];
  items: Array<Record<string, unknown>>;
  totals: Record<string, unknown>;
}

function isoDate(value: string): string {
  return value ? new Date(`${value}T00:00:00Z`).toISOString() : '';
}

export const usagePage: Page = async (ctx) => {
  const groupBy = (ctx.query.get('group_by') || 'assistant,model').split(',').filter(Boolean);
  const dateFrom = ctx.query.get('from') ?? '';
  const dateTo = ctx.query.get('to') ?? '';
  const params = new URLSearchParams({ group_by: groupBy.join(',') });
  if (dateFrom) params.set('date_from', isoDate(dateFrom));
  if (dateTo) params.set('date_to', isoDate(dateTo));
  const report = await ctx.api.get<UsageReport>(`${base(ctx)}/usage?${params.toString()}`);
  const filters = form(
    [
      { name: 'from', label: 'Desde', type: 'date', value: dateFrom },
      { name: 'to', label: 'Hasta', type: 'date', value: dateTo },
      {
        name: 'group_by',
        label: 'Agrupar por',
        type: 'checkboxes',
        options: DIMENSIONS,
        value: groupBy,
        required: true,
      },
    ],
    {
      submitLabel: 'Aplicar filtros',
      onSubmit: async (values) => {
        const query = new URLSearchParams({ group_by: (values.group_by as string[]).join(',') });
        if (values.from) query.set('from', String(values.from));
        if (values.to) query.set('to', String(values.to));
        ctx.go(`${ctx.t('usage')}?${query.toString()}`);
      },
    },
  );
  const exportCsv = button('Exportar CSV', async () => {
    const csv = await ctx.api.text(`${base(ctx)}/usage.csv?${params.toString()}`);
    download(`uso-${ctx.tenantId}.csv`, csv, 'text/csv;charset=utf-8');
  });
  const metric = (label: string, value: string) =>
    h('div', {}, h('dt', { text: label }), h('dd', { text: value }));
  return {
    title: 'Uso y costes',
    content: h(
      'div',
      { className: 'stack' },
      section('Filtros', filters),
      section(
        `Totales del ${formatDate(report.from)} al ${formatDate(report.to)}`,
        h(
          'dl',
          { className: 'kpis' },
          metric('Llamadas', formatNumber(report.totals.calls)),
          metric('Tokens de entrada', formatNumber(report.totals.input_tokens)),
          metric('Tokens en caché', formatNumber(report.totals.cached_tokens)),
          metric('Tokens de salida', formatNumber(report.totals.output_tokens)),
          metric('Tokens de razonamiento', formatNumber(report.totals.reasoning_tokens)),
          metric('Coste total', formatCost(report.totals.total_cost)),
        ),
        toolbar(exportCsv),
      ),
      section(
        'Detalle',
        table(
          'Uso agrupado',
          [
            ...report.group_by.map((dimension) => ({
              label: DIMENSIONS.find(([key]) => key === dimension)?.[1] ?? dimension,
              render: (row: Record<string, unknown>) => String(row[dimension] ?? '—'),
            })),
            { label: 'Llamadas', render: (row) => formatNumber(row.calls), numeric: true },
            { label: 'Entrada', render: (row) => formatNumber(row.input_tokens), numeric: true },
            { label: 'Caché', render: (row) => formatNumber(row.cached_tokens), numeric: true },
            { label: 'Salida', render: (row) => formatNumber(row.output_tokens), numeric: true },
            { label: 'Coste', render: (row) => formatCost(row.total_cost), numeric: true },
            {
              label: 'Latencia media (ms)',
              render: (row) => formatNumber(row.avg_latency_ms, 1),
              numeric: true,
            },
          ],
          report.items,
          'Sin consumo en el periodo.',
        ),
      ),
    ),
  };
};

// --- Auditoría ---------------------------------------------------------------------------

interface AuditEvent {
  id: string;
  actor_type: string;
  actor_id: string;
  action: string;
  target_type: string;
  target_id: string;
  outcome: string;
  run_id: string | null;
  conversation_id: string | null;
  details: Record<string, unknown>;
  ip: string | null;
  created_at: string;
}

export const auditPage: Page = async (ctx) => {
  const action = ctx.query.get('action') ?? '';
  const runId = ctx.query.get('run_id') ?? '';
  const offset = Math.max(0, Number(ctx.query.get('offset')) || 0);
  const params = new URLSearchParams({ limit: '100', offset: String(offset) });
  if (action) params.set('action', action);
  if (runId) params.set('run_id', runId);
  const { items } = await ctx.api.get<{ items: AuditEvent[] }>(`${base(ctx)}/audit?${params.toString()}`);
  const link = (next: number) => {
    const query = new URLSearchParams({
      ...(action ? { action } : {}),
      ...(runId ? { run_id: runId } : {}),
      offset: String(next),
    });
    return `${ctx.t('audit')}?${query.toString()}`;
  };
  return {
    title: 'Auditoría',
    content: h(
      'div',
      { className: 'stack' },
      section(
        'Filtros',
        form(
          [
            {
              name: 'action',
              label: 'Acción (prefijo)',
              value: action,
              placeholder: 'assistant., action., token.…',
            },
            { name: 'run_id', label: 'Run', value: runId },
          ],
          {
            submitLabel: 'Buscar',
            onSubmit: async (values) => {
              const query = new URLSearchParams();
              if (values.action) query.set('action', String(values.action));
              if (values.run_id) query.set('run_id', String(values.run_id));
              ctx.go(`${ctx.t('audit')}?${query.toString()}`);
            },
          },
        ),
      ),
      table(
        'Eventos de auditoría',
        [
          { label: 'Fecha', render: (e) => formatDate(e.created_at) },
          { label: 'Acción', render: (e) => h('code', { text: e.action }) },
          { label: 'Resultado', render: (e) => badge(e.outcome) },
          { label: 'Actor', render: (e) => `${e.actor_type}: ${e.actor_id}` },
          { label: 'Objeto', render: (e) => (e.target_type ? `${e.target_type}: ${e.target_id}` : '—') },
          { label: 'Run', render: (e) => e.run_id ?? '—' },
          {
            label: 'Detalle',
            render: (e) =>
              Object.keys(e.details ?? {}).length
                ? h('details', {}, h('summary', { text: 'Ver' }), json(e.details))
                : '—',
          },
        ],
        items,
        'Sin eventos con este filtro.',
      ),
      h(
        'nav',
        { className: 'pager', 'aria-label': 'Paginación de auditoría' },
        offset > 0 ? h('a', { href: link(Math.max(0, offset - 100)), text: '← Más recientes' }) : null,
        items.length === 100 ? h('a', { href: link(offset + 100), text: 'Más antiguos →' }) : null,
      ),
    ),
  };
};

// --- Conversaciones y trazas ---------------------------------------------------------------

interface ConversationRow {
  id: string;
  site_id: string;
  assistant_id: string;
  subject_type: string;
  locale: string;
  status: string;
  title: string;
  last_message_at: string | null;
  created_at: string;
}

export const conversationsPage: Page = async (ctx) => {
  const offset = Math.max(0, Number(ctx.query.get('offset')) || 0);
  const { items } = await ctx.api.get<{ items: ConversationRow[] }>(
    `${base(ctx)}/conversations?limit=50&offset=${offset}`,
  );
  return {
    title: 'Conversaciones',
    content: h(
      'div',
      { className: 'stack' },
      h('p', {
        className: 'muted',
        text: 'Metadatos para soporte. Ver una traza queda registrado en la auditoría.',
      }),
      table(
        'Conversaciones',
        [
          {
            label: 'Título',
            render: (c) => h('a', { href: ctx.t(`conversations/${c.id}`), text: c.title || c.id }),
          },
          { label: 'Usuario', render: (c) => c.subject_type },
          { label: 'Idioma', render: (c) => c.locale },
          { label: 'Estado', render: (c) => badge(c.status) },
          { label: 'Último mensaje', render: (c) => formatDate(c.last_message_at) },
          { label: 'Creada', render: (c) => formatDate(c.created_at) },
        ],
        items,
        'No hay conversaciones.',
      ),
      h(
        'nav',
        { className: 'pager', 'aria-label': 'Paginación de conversaciones' },
        offset > 0
          ? h('a', {
              href: `${ctx.t('conversations')}?offset=${Math.max(0, offset - 50)}`,
              text: '← Más recientes',
            })
          : null,
        items.length === 50
          ? h('a', { href: `${ctx.t('conversations')}?offset=${offset + 50}`, text: 'Más antiguas →' })
          : null,
      ),
    ),
  };
};

interface TraceRun {
  run_id: string;
  status: string;
  skill: string | null;
  error_code: string | null;
  assistant_version: number;
  iterations: number;
  tool_calls_count: number;
  rag_used: boolean;
  repaired: boolean;
  latency_ms: number;
  started_at: string;
  usage: Record<string, unknown>;
  context: Record<string, unknown>;
  provider_calls: Array<Record<string, unknown>>;
  tool_calls: Array<Record<string, unknown>>;
  actions: Array<Record<string, unknown>>;
  payloads: Array<{ id: string; kind: string; preview?: string }>;
}

interface Trace {
  conversation: Record<string, unknown>;
  messages: Array<{ role: string; content: string; run_id: string | null; created_at: string }>;
  runs: TraceRun[];
}

function genericTable(caption: string, rows: Array<Record<string, unknown>>, keys: string[]): HTMLElement {
  return table(
    caption,
    keys.map((key) => ({
      label: key.replace(/_/g, ' '),
      render: (row: Record<string, unknown>) => {
        const value = row[key];
        if (value === null || value === undefined || value === '') return '—';
        if (key.endsWith('_at')) return formatDate(value);
        if (key === 'total_cost') return formatCost(value, String(row.currency ?? 'USD'));
        if (key === 'status') return badge(value);
        return typeof value === 'object' ? JSON.stringify(value) : String(value);
      },
    })),
    rows,
    'Sin registros.',
  );
}

export const tracePage: Page = async (ctx) => {
  const id = ctx.params.cid!;
  const trace = await ctx.api.get<Trace>(`${base(ctx)}/conversations/${encodeURIComponent(id)}/trace`);
  const canPayloads = ctx.can('payloads.read');
  const showPayload = async (payloadId: string) => {
    const payload = await ctx.api.get<Record<string, unknown>>(
      `${base(ctx)}/payloads/${encodeURIComponent(payloadId)}`,
    );
    dialog('Payload técnico (acceso auditado)', () => h('div', { className: 'dialog-body' }, json(payload)), {
      wide: true,
    });
  };
  return {
    title: 'Traza de conversación',
    content: h(
      'div',
      { className: 'stack' },
      h('p', {}, h('a', { href: ctx.t('conversations'), text: '← Volver a conversaciones' })),
      section(
        'Mensajes',
        h(
          'ol',
          { className: 'transcript' },
          trace.messages.map((m) =>
            h(
              'li',
              { className: `msg msg-${m.role}` },
              h('p', {
                className: 'meta',
                text: `${m.role === 'user' ? 'Usuario' : 'Asistente'} · ${formatDate(m.created_at)}${m.run_id ? ` · ${m.run_id}` : ''}`,
              }),
              h('p', { text: m.content }),
            ),
          ),
        ),
      ),
      ...trace.runs.map((run) =>
        section(
          `Run ${run.run_id}`,
          h(
            'dl',
            { className: 'facts' },
            h('dt', { text: 'Estado' }),
            h('dd', {}, badge(run.status), run.error_code ? ` ${run.error_code}` : ''),
            h('dt', { text: 'Skill' }),
            h('dd', { text: run.skill ?? '—' }),
            h('dt', { text: 'Versión del assistant' }),
            h('dd', { text: `v${run.assistant_version}` }),
            h('dt', { text: 'Iteraciones / herramientas' }),
            h('dd', { text: `${run.iterations} / ${run.tool_calls_count}` }),
            h('dt', { text: 'RAG / reparación' }),
            h('dd', { text: `${run.rag_used ? 'Sí' : 'No'} / ${run.repaired ? 'Sí' : 'No'}` }),
            h('dt', { text: 'Latencia' }),
            h('dd', { text: `${formatNumber(run.latency_ms)} ms` }),
            h('dt', { text: 'Tokens y coste' }),
            h('dd', {
              text: `${formatNumber(run.usage.input_tokens)} entrada (${formatNumber(run.usage.cached_tokens)} caché) · ${formatNumber(run.usage.output_tokens)} salida · ${formatCost(run.usage.total_cost, String(run.usage.currency ?? 'USD'))}`,
            }),
          ),
          h('h3', { text: 'Llamadas al modelo' }),
          genericTable('Llamadas al modelo', run.provider_calls, [
            'iteration',
            'provider',
            'model',
            'operation',
            'status',
            'error_code',
            'input_tokens',
            'cached_tokens',
            'output_tokens',
            'total_cost',
            'latency_ms',
          ]),
          h('h3', { text: 'Herramientas' }),
          genericTable('Herramientas', run.tool_calls, [
            'iteration',
            'name',
            'status',
            'arguments',
            'result',
            'latency_ms',
            'error_code',
          ]),
          h('h3', { text: 'Acciones' }),
          genericTable('Acciones', run.actions, ['execution_id', 'skill', 'action', 'type', 'status']),
          h('h3', { text: 'Contexto enviado' }),
          h(
            'details',
            {},
            h('summary', { text: 'Estrategia de historial y skills ofrecidas' }),
            json(run.context),
          ),
          h('h3', { text: 'Payloads técnicos' }),
          run.payloads.length
            ? h(
                'ul',
                { className: 'payloads' },
                run.payloads.map((p) =>
                  h(
                    'li',
                    {},
                    h('code', { text: p.kind }),
                    p.preview ? h('p', { className: 'preview', text: p.preview }) : null,
                    canPayloads
                      ? button('Ver descifrado', () => showPayload(p.id), {
                          variant: 'link',
                          ariaLabel: `Ver payload ${p.kind}`,
                        })
                      : h('span', { className: 'muted', text: '(requiere permiso payloads.read)' }),
                  ),
                ),
              )
            : h('p', {
                className: 'muted',
                text: 'Sin payloads (expirados por retención o no registrados).',
              }),
        ),
      ),
    ),
  };
};

// --- Configuración como paquete -------------------------------------------------------------

interface ImportResult {
  dry_run: boolean;
  tenant_id: string | null;
  changes: Array<{ entity: string; key: string; operation: string }>;
}

export const configPage: Page = async (ctx) => {
  let pending: unknown = null;
  const result = h('div', { className: 'stack', 'aria-live': 'polite' });
  const applyButton = button(
    'Aplicar importación',
    async () => {
      if (!pending) return;
      if (
        !(await confirm(
          'Aplicar configuración',
          'Se crearán o actualizarán los elementos listados. Las versiones de assistants nuevas se publican según el paquete.',
          'Aplicar',
        ))
      )
        return;
      const applied = await ctx.api.post<ImportResult>(`${base(ctx)}/config/import?dry_run=false`, pending);
      notify(`Configuración aplicada: ${applied.changes.length} cambios.`);
      pending = null;
      applyButton.disabled = true;
      renderChanges(applied, false);
    },
    { variant: 'primary', disabled: true },
  );
  const renderChanges = (data: ImportResult, dryRun: boolean) => {
    result.replaceChildren(
      h('p', {
        text: dryRun
          ? `Simulación: ${data.changes.length} cambios.`
          : `Aplicado: ${data.changes.length} cambios.`,
      }),
      table(
        'Cambios',
        [
          { label: 'Elemento', render: (c) => c.entity },
          { label: 'Clave', render: (c) => h('code', { text: c.key }) },
          { label: 'Operación', render: (c) => badge(c.operation) },
        ],
        data.changes,
        'La configuración ya está al día.',
      ),
    );
  };
  const importForm = form(
    [
      {
        name: 'package',
        label: 'Paquete de configuración (JSON)',
        type: 'json',
        rows: 14,
        value: {},
        required: true,
        help: 'Exportado desde otro entorno. No contiene secretos. Primero se simula (dry run).',
      },
    ],
    {
      submitLabel: 'Simular importación',
      onSubmit: async (values) => {
        pending = values.package;
        const simulated = await ctx.api.post<ImportResult>(
          `${base(ctx)}/config/import?dry_run=true`,
          pending,
        );
        renderChanges(simulated, true);
        applyButton.disabled = !ctx.can('config.publish');
      },
    },
  );
  const file = h('input', { type: 'file', id: 'config-file', accept: 'application/json,.json' });
  file.addEventListener('change', async () => {
    const chosen = file.files?.[0];
    if (!chosen) return;
    const area = importForm.querySelector('textarea');
    if (area) area.value = await chosen.text();
  });
  return {
    title: 'Configuración',
    content: h(
      'div',
      { className: 'stack' },
      section(
        'Exportar',
        h('p', {
          className: 'muted',
          text: 'Paquete versionable del tenant (assistants publicados, agentes, sites, bases de conocimiento, políticas, retención y precios), sin secretos.',
        }),
        toolbar(
          button('Descargar paquete', async () => {
            const data = await ctx.api.get<Record<string, unknown>>(`${base(ctx)}/config/export`);
            download(
              `webassistant-config-${ctx.tenantId}.json`,
              `${JSON.stringify(data, null, 2)}\n`,
              'application/json',
            );
          }),
        ),
      ),
      ctx.can('config.write')
        ? section(
            'Importar',
            h(
              'div',
              { className: 'field' },
              h('label', { for: 'config-file', text: 'Cargar desde fichero' }),
              file,
            ),
            importForm,
            toolbar(applyButton),
            result,
          )
        : null,
    ),
  };
};
