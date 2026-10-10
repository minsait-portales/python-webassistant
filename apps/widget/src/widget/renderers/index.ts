/**
 * Registro de renderers (CG-10.5): `presentation.type@version` -> componente seguro.
 * Una presentación desconocida usa el fallback de texto. Todos los renderers
 * construyen DOM con `textContent` y URLs saneadas: nunca interpretan HTML.
 */
import type { ActionDescriptor, Presentation, ProductItem, SourceLink, Step } from '@webassistant/sdk';

import { h, safeUrl, text } from '../dom';
import type { Translate } from '../i18n';

export interface RenderContext {
  t: Translate;
  locale: string;
  actions: ActionDescriptor[];
  runAction: (action: ActionDescriptor, params: Record<string, unknown>) => void;
  quickReply: (text: string) => void;
  headingLevel?: 'h3' | 'h4';
}

export type Renderer = (data: Record<string, unknown>, ctx: RenderContext) => HTMLElement;

const registry = new Map<string, Renderer>();

export function registerRenderer(type: string, version: string, renderer: Renderer): void {
  registry.set(`${type}@${version}`, renderer);
}

export function hasRenderer(type: string, version: string): boolean {
  return registry.has(`${type}@${version}`);
}

export function render(presentation: Presentation, ctx: RenderContext): HTMLElement {
  const key = `${presentation.type}@${presentation.version}`;
  const renderer = registry.get(key) ?? registry.get('text@1')!;
  const data = presentation.data && typeof presentation.data === 'object' ? presentation.data : {};
  try {
    return renderer(data as Record<string, unknown>, ctx);
  } catch {
    return renderText({ description: text((data as Record<string, unknown>).description ?? '') }, ctx);
  }
}

// --- utilidades -------------------------------------------------------------------

function list<T>(value: unknown): T[] {
  return Array.isArray(value) ? (value as T[]) : [];
}

function heading(ctx: RenderContext, value: string): HTMLElement {
  return h(ctx.headingLevel ?? 'h3', { text: value });
}

function paragraph(value: unknown, className?: string): HTMLElement | null {
  const content = text(value);
  return content ? h('p', { className, text: content }) : null;
}

function link(href: string | null, label: string, ctx: RenderContext): HTMLElement {
  if (!href) return h('span', { text: label });
  const external = new URL(href).origin !== window.location.origin;
  return h(
    'a',
    { href, target: external ? '_blank' : null, rel: external ? 'noopener noreferrer' : null },
    label,
    external ? h('span', { className: 'wa-sr', text: ` ${ctx.t('openLink')}` }) : null,
  );
}

function steps(items: Step[]): HTMLElement | null {
  const valid = items.filter((s) => s && typeof s.title === 'string' && s.title.trim());
  if (!valid.length) return null;
  return h(
    'ol',
    { className: 'wa-steps' },
    valid.map((step) =>
      h('li', {}, h('strong', { text: text(step.title, 300) }), step.body ? ` ${text(step.body)}` : ''),
    ),
  );
}

function sources(items: SourceLink[], ctx: RenderContext): HTMLElement | null {
  const valid = items.filter((s) => s && typeof s.title === 'string');
  if (!valid.length) return null;
  return h(
    'section',
    { 'aria-label': ctx.t('sources') },
    h('h4', { text: ctx.t('sources') }),
    h(
      'ul',
      { className: 'wa-sources' },
      valid.map((s) => h('li', {}, link(safeUrl(s.url), text(s.title, 300), ctx))),
    ),
  );
}

export function productCard(item: ProductItem, ctx: RenderContext): HTMLElement {
  const url = safeUrl(item.url);
  const image = safeUrl(item.image_url);
  // Sin lista explícita en el ítem se ofrecen todas las acciones por ítem de la skill.
  const itemActions = ctx.actions.filter(
    (a) => a.target === 'item' && (!item.actions || item.actions.includes(a.name)),
  );
  const title = text(item.title, 300);
  const card = h(
    'li',
    { className: 'wa-product' },
    image ? h('img', { src: image, alt: text(item.image_alt || item.title, 300), loading: 'lazy' }) : null,
    h(
      'div',
      { className: 'wa-product-body' },
      h('h4', { className: 'wa-product-title' }, url ? link(url, title, ctx) : title),
      item.brand || item.category
        ? h('p', {
            className: 'wa-meta',
            text: [item.brand, item.category]
              .filter(Boolean)
              .map((v) => text(v, 80))
              .join(' · '),
          })
        : null,
      paragraph(item.body),
      item.availability ? h('p', { className: 'wa-meta', text: text(item.availability, 60) }) : null,
      item.price
        ? h(
            'p',
            { className: 'wa-price' },
            h('span', { className: 'wa-sr', text: `${ctx.t('price')}: ` }),
            text(item.price, 60),
          )
        : null,
      item.quantity && item.quantity > 1
        ? h('p', { className: 'wa-meta', text: `${ctx.t('quantity')}: ${item.quantity}` })
        : null,
      itemActions.length
        ? h(
            'div',
            { className: 'wa-product-actions' },
            itemActions.map((action) => {
              const button = h(
                'button',
                { type: 'button', 'aria-label': `${action.label}: ${title}` },
                action.label,
              );
              button.addEventListener('click', () =>
                ctx.runAction(action, {
                  product_id: item.product_id,
                  ...(item.variation_id ? { variation_id: item.variation_id } : {}),
                  ...(action.name === 'add_to_cart' || action.name === 'update_item'
                    ? { quantity: item.quantity ?? 1 }
                    : {}),
                }),
              );
              return button;
            }),
          )
        : null,
    ),
  );
  return card;
}

function productGrid(items: ProductItem[], ctx: RenderContext, label: string): HTMLElement {
  const valid = items.filter((i) => i && typeof i.product_id === 'string' && typeof i.title === 'string');
  if (!valid.length) return h('p', { text: ctx.t('noResults') });
  return h(
    'ul',
    { className: 'wa-grid', 'aria-label': label },
    valid.map((item) => productCard(item, ctx)),
  );
}

function presentationActions(ctx: RenderContext): HTMLElement | null {
  const actions = ctx.actions.filter((a) => (a.target ?? 'presentation') === 'presentation' && !a.auto);
  if (!actions.length) return null;
  return h(
    'div',
    { className: 'wa-actions' },
    actions.map((action) => {
      const button = h('button', { type: 'button', className: 'wa-primary' }, action.label);
      button.addEventListener('click', () => ctx.runAction(action, { ...(action.params ?? {}) }));
      return button;
    }),
  );
}

// --- renderers v1 ----------------------------------------------------------------------

function renderText(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  return h(
    'article',
    { className: 'wa-card' },
    data.title ? h('p', { className: 'wa-eyebrow', text: text(data.title, 300) }) : null,
    paragraph(data.description),
    list<string>(data.paragraphs).map((p) => paragraph(p)),
    steps(list<Step>(data.items)),
    presentationActions(ctx),
  );
}

function renderAnswer(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  return h(
    'article',
    { className: 'wa-card' },
    paragraph(data.answer),
    steps(list<Step>(data.steps)),
    paragraph(data.outro),
    sources(list<SourceLink>(data.sources), ctx),
  );
}

function renderProductGrid(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const label = text(data.heading, 200) || text(data.intro, 200) || ctx.t('resultRegion');
  return h(
    'article',
    { className: 'wa-card' },
    data.heading ? heading(ctx, text(data.heading, 300)) : null,
    paragraph(data.intro),
    productGrid(list<ProductItem>(data.items), ctx, label),
    paragraph(data.outro),
    presentationActions(ctx),
  );
}

function renderComparison(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const rows = list<{ label: string; value: string }>(data.rows).filter((r) => r && r.label);
  return h(
    'article',
    { className: 'wa-card' },
    paragraph(data.description),
    productGrid(list<ProductItem>(data.items), ctx, ctx.t('resultRegion')),
    rows.length
      ? h(
          'table',
          { className: 'wa-table' },
          h(
            'tbody',
            {},
            rows.map((row) =>
              h(
                'tr',
                {},
                h('th', { scope: 'row', text: text(row.label, 300) }),
                h('td', { text: text(row.value) }),
              ),
            ),
          ),
        )
      : null,
    paragraph(data.outro),
  );
}

function renderSummary(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  return h(
    'article',
    { className: 'wa-card' },
    paragraph(data.description),
    productGrid(list<ProductItem>(data.items), ctx, ctx.t('resultRegion')),
    steps(list<Step>(data.points)),
    paragraph(data.outro),
  );
}

function renderEvent(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const facts: string[] = [];
  if (typeof data.people === 'number' && data.people > 0) facts.push(`${data.people} ${ctx.t('people')}`);
  if (typeof data.vegetarian_people === 'number' && data.vegetarian_people > 0)
    facts.push(`${data.vegetarian_people} ${ctx.t('vegetarian')}`);
  if (data.budget) facts.push(`${ctx.t('budget')}: ${text(data.budget, 80)}`);
  const categories = list<{ name: string; description?: string; items: ProductItem[] }>(data.categories);
  return h(
    'article',
    { className: 'wa-card' },
    data.event_name ? heading(ctx, text(data.event_name, 300)) : null,
    paragraph(data.intro),
    facts.length ? h('p', { className: 'wa-meta', text: facts.join(' · ') }) : null,
    list<string>(data.dietary_restrictions).length
      ? h(
          'ul',
          {},
          list<string>(data.dietary_restrictions).map((r) => h('li', { text: text(r, 300) })),
        )
      : null,
    categories.map((category) =>
      h(
        'section',
        { 'aria-label': text(category.name, 300) },
        h('h4', { text: text(category.name, 300) }),
        paragraph(category.description),
        productGrid(list<ProductItem>(category.items), ctx, text(category.name, 300)),
      ),
    ),
    data.total_products || data.total_amount
      ? h('p', {
          className: 'wa-price',
          text: `${ctx.t('total')}: ${data.total_products ?? 0} ${ctx.t('products')}${data.total_amount ? ` · ${text(data.total_amount, 60)}` : ''}`,
        })
      : null,
    paragraph(data.notes, 'wa-meta'),
    paragraph(data.outro),
    presentationActions(ctx),
  );
}

function renderCitations(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const citations = list<{
    document_title: string;
    quote: string;
    location?: string;
    page?: number;
    document_url?: string;
    verified?: boolean;
  }>(data.citations);
  return h(
    'article',
    { className: 'wa-card' },
    paragraph(data.answer),
    paragraph(data.summary, 'wa-meta'),
    citations.map((citation) =>
      h(
        'figure',
        { className: 'wa-quote' },
        h('blockquote', { text: text(citation.quote, 1000) }),
        h(
          'figcaption',
          { className: 'wa-meta' },
          link(safeUrl(citation.document_url), text(citation.document_title, 300), ctx),
          citation.location ? ` · ${text(citation.location, 200)}` : '',
          citation.page ? ` · ${ctx.t('page')} ${citation.page}` : '',
          citation.verified ? h('span', { className: 'wa-badge', text: ctx.t('verified') }) : null,
        ),
      ),
    ),
    sources(list<SourceLink>(data.documents), ctx),
  );
}

function renderClarification(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const options = list<string>(data.options).filter((o) => typeof o === 'string' && o.trim());
  return h(
    'article',
    { className: 'wa-card' },
    paragraph(data.question),
    options.length
      ? h(
          'ul',
          { className: 'wa-suggestions', 'aria-label': ctx.t('suggestions') },
          options.map((option) => {
            const button = h('button', { type: 'button', className: 'wa-chip' }, text(option, 120));
            button.addEventListener('click', () => ctx.quickReply(option));
            return h('li', {}, button);
          }),
        )
      : null,
  );
}

function renderSteps(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const items = list<ProductItem>(data.items);
  return h(
    'article',
    { className: 'wa-card' },
    paragraph(data.intro),
    items.length ? productGrid(items, ctx, ctx.t('resultRegion')) : null,
    steps(list<Step>(data.steps)),
    paragraph(data.outro),
    presentationActions(ctx),
  );
}

function renderCart(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const items = list<ProductItem>(data.items);
  return h(
    'article',
    { className: 'wa-card', 'aria-busy': data.loading ? 'true' : null },
    paragraph(data.intro),
    data.loading
      ? h('p', { className: 'wa-meta', text: ctx.t('loading') })
      : productGrid(
          items.map((i) => ({ ...i, actions: i.actions ?? ['update_item', 'remove_item'] })),
          ctx,
          ctx.t('resultRegion'),
        ),
    data.total ? h('p', { className: 'wa-price', text: `${ctx.t('total')}: ${text(data.total, 60)}` }) : null,
    paragraph(data.outro),
  );
}

function renderHostAction(data: Record<string, unknown>, ctx: RenderContext): HTMLElement {
  const details = list<{ label: string; value: string }>(data.details);
  return h(
    'article',
    { className: 'wa-card' },
    data.title ? h('p', { className: 'wa-eyebrow', text: text(data.title, 300) }) : null,
    paragraph(data.description),
    details.length
      ? h(
          'dl',
          {},
          details.flatMap((d) => [
            h('dt', { text: text(d.label, 120) }),
            h('dd', { text: text(d.value, 300) }),
          ]),
        )
      : null,
    presentationActions(ctx),
  );
}

registerRenderer('text', '1', renderText);
registerRenderer('answer', '1', renderAnswer);
registerRenderer('product_grid', '1', renderProductGrid);
registerRenderer('product_comparison', '1', renderComparison);
registerRenderer('product_summary', '1', renderSummary);
registerRenderer('event_selection', '1', renderEvent);
registerRenderer('document_citations', '1', renderCitations);
registerRenderer('clarification', '1', renderClarification);
registerRenderer('steps', '1', renderSteps);
registerRenderer('cart', '1', renderCart);
registerRenderer('host_action', '1', renderHostAction);
