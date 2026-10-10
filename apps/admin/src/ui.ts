/**
 * Kit de interfaz de la consola: DOM seguro (sin innerHTML), tablas, formularios con
 * validación accesible, diálogos nativos y notificaciones en regiones vivas (CG-14).
 */
import { ApiError } from '@webassistant/sdk';

type Attrs = Record<string, string | number | boolean | null | undefined>;
export type Child = Node | string | number | null | undefined | false;

export function h<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Attrs = {},
  ...children: Array<Child | Child[]>
): HTMLElementTagNameMap[K] {
  const element = document.createElement(tag);
  for (const [name, value] of Object.entries(attrs)) {
    if (value === null || value === undefined || value === false) continue;
    if (name === 'className') element.className = String(value);
    else if (name === 'text') element.textContent = String(value);
    else if (name.startsWith('on')) throw new Error('Usa addEventListener');
    else element.setAttribute(name, value === true ? '' : String(value));
  }
  append(element, ...children);
  return element;
}

export function append(parent: Node, ...children: Array<Child | Child[]>): void {
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    parent.appendChild(
      typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child,
    );
  }
}

export function clear(element: Element): void {
  while (element.firstChild) element.removeChild(element.firstChild);
}

let counter = 0;
export function uid(prefix: string): string {
  counter += 1;
  return `${prefix}-${counter}`;
}

export function button(
  label: string,
  onClick: (event: MouseEvent) => void | Promise<void>,
  options: {
    variant?: 'primary' | 'secondary' | 'danger' | 'link';
    ariaLabel?: string;
    disabled?: boolean;
  } = {},
): HTMLButtonElement {
  const element = h(
    'button',
    {
      type: 'button',
      className: `btn btn-${options.variant ?? 'secondary'}`,
      'aria-label': options.ariaLabel,
      disabled: options.disabled,
    },
    label,
  );
  element.addEventListener('click', async (event) => {
    if (element.disabled) return;
    element.disabled = true;
    try {
      await onClick(event);
    } catch (error) {
      notifyError(error);
    } finally {
      if (element.isConnected) element.disabled = Boolean(options.disabled);
    }
  });
  return element;
}

export function link(label: string, href: string, attrs: Attrs = {}): HTMLAnchorElement {
  return h('a', { href, ...attrs }, label);
}

// --- Formato --------------------------------------------------------------------------

const DATE = new Intl.DateTimeFormat('es-ES', { dateStyle: 'short', timeStyle: 'medium' });

export function formatDate(value: unknown): string {
  if (typeof value !== 'string' || !value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

export function formatNumber(value: unknown, digits = 0): string {
  const number = typeof value === 'string' ? Number(value) : value;
  if (typeof number !== 'number' || !Number.isFinite(number)) return '—';
  return number.toLocaleString('es-ES', { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

export function formatCost(value: unknown, currency = 'USD'): string {
  const number = typeof value === 'string' ? Number(value) : value;
  if (typeof number !== 'number' || !Number.isFinite(number)) return '—';
  return `${number.toLocaleString('es-ES', { maximumFractionDigits: 6, minimumFractionDigits: 2 })} ${currency}`;
}

export function badge(value: unknown): HTMLElement {
  const text = String(value ?? '—');
  const tone = [
    'active',
    'published',
    'ready',
    'succeeded',
    'completed',
    'available',
    'success',
    'ok',
  ].includes(text)
    ? 'ok'
    : ['failed', 'error', 'revoked', 'disabled', 'suspended', 'denied', 'invalid'].includes(text)
      ? 'bad'
      : 'neutral';
  return h('span', { className: `badge badge-${tone}`, text });
}

export function json(value: unknown): HTMLElement {
  return h('pre', { className: 'json', tabindex: '0', text: JSON.stringify(value, null, 2) });
}

export function list(values: unknown): string {
  return Array.isArray(values) && values.length ? values.join(', ') : '—';
}

// --- Notificaciones ----------------------------------------------------------------------

let statusRegion: HTMLElement | null = null;
let alertRegion: HTMLElement | null = null;

export function mountRegions(container: HTMLElement): void {
  statusRegion = h('div', { className: 'toast', role: 'status', 'aria-live': 'polite' });
  alertRegion = h('div', { className: 'toast toast-error', role: 'alert' });
  append(container, statusRegion, alertRegion);
}

export function notify(message: string): void {
  if (!statusRegion) return;
  statusRegion.textContent = message;
  statusRegion.classList.add('visible');
  window.setTimeout(() => {
    if (statusRegion?.textContent === message) statusRegion.classList.remove('visible');
  }, 5000);
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const problem = error.problem;
    const details = (problem.errors ?? []).map((e) => `${e.path || '/'}: ${e.message}`).join('; ');
    // En la consola el detalle técnico es útil para el administrador; el widget usa user_message.
    return [problem.detail || problem.user_message || problem.title, details].filter(Boolean).join(' — ');
  }
  return error instanceof Error ? error.message : 'Error inesperado';
}

export function notifyError(error: unknown): void {
  if (!alertRegion) return;
  alertRegion.textContent = errorMessage(error);
  alertRegion.classList.add('visible');
  window.setTimeout(() => alertRegion?.classList.remove('visible'), 8000);
}

// --- Tablas ----------------------------------------------------------------------------

export interface Column<T> {
  label: string;
  render: (row: T) => Child | Child[];
  numeric?: boolean;
}

export function table<T>(
  caption: string,
  columns: Column<T>[],
  rows: T[],
  empty = 'No hay elementos.',
): HTMLElement {
  if (!rows.length) return h('p', { className: 'empty', text: empty });
  return h(
    'div',
    { className: 'table-wrap', role: 'region', 'aria-label': caption, tabindex: '0' },
    h(
      'table',
      {},
      h('caption', { className: 'sr-only', text: caption }),
      h(
        'thead',
        {},
        h(
          'tr',
          {},
          columns.map((c) => h('th', { scope: 'col', className: c.numeric ? 'num' : null, text: c.label })),
        ),
      ),
      h(
        'tbody',
        {},
        rows.map((row) =>
          h(
            'tr',
            {},
            columns.map((c) => h('td', { className: c.numeric ? 'num' : null }, c.render(row))),
          ),
        ),
      ),
    ),
  );
}

// --- Formularios -----------------------------------------------------------------------

export type FieldType =
  | 'text'
  | 'email'
  | 'password'
  | 'number'
  | 'select'
  | 'textarea'
  | 'json'
  | 'list'
  | 'checkbox'
  | 'checkboxes'
  | 'date'
  | 'url';

export interface Field {
  name: string;
  label: string;
  type?: FieldType;
  required?: boolean;
  value?: unknown;
  options?: Array<[string, string]>;
  help?: string;
  rows?: number;
  min?: number;
  max?: number;
  step?: string;
  autocomplete?: string;
  placeholder?: string;
  nullable?: boolean;
}

type Values = Record<string, unknown>;

interface Control {
  field: Field;
  element: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | HTMLFieldSetElement;
  error: HTMLElement;
}

function controlFor(field: Field, id: string, describedBy: string): Control['element'] {
  const type = field.type ?? 'text';
  const common = { id, name: field.name, 'aria-describedby': describedBy, required: field.required };
  if (type === 'select') {
    const select = h(
      'select',
      common,
      (field.options ?? []).map(([value, label]) => h('option', { value, text: label })),
    );
    select.value = field.value === null || field.value === undefined ? '' : String(field.value);
    return select;
  }
  if (type === 'textarea' || type === 'json' || type === 'list') {
    const area = h('textarea', {
      ...common,
      rows: field.rows ?? (type === 'json' ? 10 : 3),
      spellcheck: type === 'json' ? 'false' : null,
      className: type === 'json' ? 'mono' : null,
      placeholder: field.placeholder,
    });
    if (type === 'json') area.value = field.value === undefined ? '{}' : JSON.stringify(field.value, null, 2);
    else if (type === 'list') area.value = Array.isArray(field.value) ? field.value.join('\n') : '';
    else area.value = field.value === null || field.value === undefined ? '' : String(field.value);
    return area;
  }
  if (type === 'checkbox') {
    const input = h('input', { ...common, type: 'checkbox' });
    input.checked = Boolean(field.value);
    return input;
  }
  if (type === 'checkboxes') {
    const selected = new Set(Array.isArray(field.value) ? field.value.map(String) : []);
    return h(
      'fieldset',
      { id, 'aria-describedby': describedBy, className: 'checkboxes' },
      h('legend', { text: field.label }),
      (field.options ?? []).map(([value, label]) => {
        const input = h('input', { type: 'checkbox', name: field.name, value });
        input.checked = selected.has(value);
        return h('label', { className: 'check' }, input, ` ${label}`);
      }),
    );
  }
  const input = h('input', {
    ...common,
    type,
    min: field.min,
    max: field.max,
    step: field.step,
    autocomplete: field.autocomplete ?? (type === 'password' ? 'new-password' : 'off'),
    placeholder: field.placeholder,
  });
  input.value = field.value === null || field.value === undefined ? '' : String(field.value);
  return input;
}

function readValue(control: Control): unknown {
  const { field, element } = control;
  const type = field.type ?? 'text';
  if (type === 'checkbox') return (element as HTMLInputElement).checked;
  if (type === 'checkboxes') {
    return Array.from(element.querySelectorAll<HTMLInputElement>('input[type="checkbox"]'))
      .filter((input) => input.checked)
      .map((input) => input.value);
  }
  const raw = (element as HTMLInputElement).value.trim();
  if (type === 'number') return raw === '' ? null : Number(raw);
  if (type === 'list') {
    return raw
      .split(/[\n,]/)
      .map((item) => item.trim())
      .filter(Boolean);
  }
  if (type === 'json') {
    if (!raw) return {};
    return JSON.parse(raw) as unknown;
  }
  if (raw === '' && (field.nullable || type === 'select')) return field.nullable ? null : '';
  return raw;
}

export interface FormOptions {
  submitLabel: string;
  onSubmit: (values: Values) => Promise<void>;
  onCancel?: () => void;
  intro?: string;
}

/** Formulario accesible: etiquetas, ayudas, errores por campo y error general en alerta. */
export function form(fields: Field[], options: FormOptions): HTMLFormElement {
  const controls: Control[] = [];
  const summary = h('div', { className: 'form-error', role: 'alert' });
  const element = h('form', { className: 'form', novalidate: true });
  if (options.intro) element.appendChild(h('p', { className: 'muted', text: options.intro }));
  element.appendChild(summary);
  for (const field of fields) {
    const id = uid(`f-${field.name}`);
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const describedBy = [field.help ? helpId : null, errorId].filter(Boolean).join(' ');
    const control = controlFor(field, id, describedBy);
    const error = h('p', { id: errorId, className: 'field-error' });
    controls.push({ field, element: control, error });
    const help = field.help ? h('p', { id: helpId, className: 'help', text: field.help }) : null;
    if (field.type === 'checkbox') {
      element.appendChild(
        h(
          'div',
          { className: 'field field-check' },
          control,
          h('label', { for: id, text: field.label }),
          help,
          error,
        ),
      );
    } else if (field.type === 'checkboxes') {
      element.appendChild(h('div', { className: 'field' }, control, help, error));
    } else {
      element.appendChild(
        h(
          'div',
          { className: 'field' },
          h(
            'label',
            { for: id },
            field.label,
            field.required ? h('span', { className: 'req', 'aria-hidden': 'true', text: ' *' }) : null,
          ),
          help,
          control,
          error,
        ),
      );
    }
  }
  const submit = h('button', { type: 'submit', className: 'btn btn-primary', text: options.submitLabel });
  const actions = h('div', { className: 'form-actions' }, submit);
  if (options.onCancel) actions.appendChild(button('Cancelar', options.onCancel));
  element.appendChild(actions);

  const setError = (control: Control, message: string) => {
    control.error.textContent = message;
    if (message) control.element.setAttribute('aria-invalid', 'true');
    else control.element.removeAttribute('aria-invalid');
  };

  element.addEventListener('submit', async (event) => {
    event.preventDefault();
    summary.textContent = '';
    const values: Values = {};
    let firstInvalid: Control | null = null;
    for (const control of controls) {
      setError(control, '');
      try {
        const value = readValue(control);
        const empty = value === '' || value === null || (Array.isArray(value) && !value.length);
        if (control.field.required && empty && control.field.type !== 'checkbox') {
          setError(control, `${control.field.label} es obligatorio.`);
          firstInvalid ??= control;
        } else if (control.field.type === 'number' && value !== null && Number.isNaN(value)) {
          setError(control, 'Introduce un número válido.');
          firstInvalid ??= control;
        }
        values[control.field.name] = value;
      } catch {
        setError(control, 'JSON no válido.');
        firstInvalid ??= control;
      }
    }
    if (firstInvalid) {
      summary.textContent = 'Revisa los campos marcados.';
      (firstInvalid.element instanceof HTMLFieldSetElement
        ? firstInvalid.element.querySelector('input')
        : firstInvalid.element
      )?.focus();
      return;
    }
    submit.disabled = true;
    element.setAttribute('aria-busy', 'true');
    try {
      await options.onSubmit(values);
    } catch (error) {
      summary.textContent = errorMessage(error);
      if (error instanceof ApiError) {
        for (const item of error.problem.errors ?? []) {
          const name = item.path.replace(/^\//, '').split('/')[0];
          const control = controls.find((c) => c.field.name === name);
          if (control) setError(control, item.message);
        }
      }
    } finally {
      submit.disabled = false;
      element.removeAttribute('aria-busy');
    }
  });
  return element;
}

// --- Diálogos --------------------------------------------------------------------------

/** Diálogo modal nativo (`<dialog>`): foco atrapado, Escape y retorno del foco. */
export function dialog(
  title: string,
  build: (close: () => void) => Node,
  options: { wide?: boolean; onClose?: () => void } = {},
): void {
  const previous = document.activeElement as HTMLElement | null;
  const titleId = uid('dlg');
  const element = h('dialog', {
    className: options.wide ? 'dialog wide' : 'dialog',
    'aria-labelledby': titleId,
  });
  const close = () => {
    element.close();
  };
  element.addEventListener('close', () => {
    element.remove();
    previous?.focus();
    options.onClose?.();
  });
  append(
    element,
    h(
      'div',
      { className: 'dialog-head' },
      h('h2', { id: titleId, text: title }),
      button('Cerrar', close, { variant: 'link', ariaLabel: `Cerrar: ${title}` }),
    ),
    build(close),
  );
  document.body.appendChild(element);
  element.showModal();
  const focusable = element.querySelector<HTMLElement>(
    '.dialog-body input, .dialog-body select, .dialog-body textarea, .form input, .form select, .form textarea',
  );
  focusable?.focus();
}

export function confirm(
  title: string,
  message: string,
  confirmLabel = 'Confirmar',
  danger = false,
): Promise<boolean> {
  return new Promise((resolve) => {
    let result = false;
    dialog(
      title,
      (close) => {
        const done = (value: boolean) => {
          result = value;
          close();
        };
        const cancel = button('Cancelar', () => done(false));
        window.setTimeout(() => cancel.focus(), 0);
        return h(
          'div',
          { className: 'dialog-body' },
          h('p', { text: message }),
          h(
            'div',
            { className: 'form-actions' },
            button(confirmLabel, () => done(true), { variant: danger ? 'danger' : 'primary' }),
            cancel,
          ),
        );
      },
      { onClose: () => resolve(result) },
    );
  });
}

/** Muestra un secreto una sola vez (clientes API) con opción de copiar. */
export function revealSecret(title: string, label: string, secret: string): void {
  dialog(title, () => {
    const input = h('input', {
      id: uid('secret'),
      className: 'mono',
      readonly: true,
      value: secret,
      'aria-describedby': 'secret-warning',
    });
    const copy = button('Copiar', async () => {
      await navigator.clipboard?.writeText(secret);
      notify('Secreto copiado al portapapeles.');
    });
    return h(
      'div',
      { className: 'dialog-body' },
      h('p', {
        id: 'secret-warning',
        className: 'warning',
        text: 'Guárdalo ahora: no se volverá a mostrar. Solo se almacena su hash.',
      }),
      h('label', { for: input.id, text: label }),
      h('div', { className: 'inline' }, input, copy),
    );
  });
}

export function download(filename: string, content: string, type: string): void {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const anchor = h('a', { href: url, download: filename });
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function section(title: string, ...children: Array<Child | Child[]>): HTMLElement {
  const id = uid('sec');
  return h(
    'section',
    { className: 'card', 'aria-labelledby': id },
    h('h2', { id, text: title }),
    ...children,
  );
}

export function toolbar(...children: Array<Child | Child[]>): HTMLElement {
  return h('div', { className: 'toolbar' }, ...children);
}
