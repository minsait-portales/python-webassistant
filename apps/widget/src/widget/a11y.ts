/**
 * Accesibilidad (CG-14): anuncios únicos en regiones vivas y diálogo modal con
 * foco atrapado, cierre con Escape y retorno del foco.
 */
import { h, uid } from './dom';
import type { Translate } from './i18n';

export class Announcer {
  readonly polite: HTMLElement;
  readonly assertive: HTMLElement;
  private last = '';

  constructor() {
    this.polite = h('div', { className: 'wa-sr', 'aria-live': 'polite', 'aria-atomic': 'true' });
    this.assertive = h('div', { className: 'wa-sr', 'aria-live': 'assertive', 'aria-atomic': 'true' });
  }

  /** Anuncia un cambio una sola vez (se ignoran repeticiones inmediatas). */
  say(message: string, urgent = false): void {
    if (!message || message === this.last) return;
    this.last = message;
    const region = urgent ? this.assertive : this.polite;
    region.textContent = '';
    window.setTimeout(() => {
      region.textContent = message;
    }, 30);
    window.setTimeout(() => {
      if (this.last === message) this.last = '';
    }, 1500);
  }
}

/** Elemento con foco atravesando Shadow DOM (chat, resultados externos o página). */
export function deepActiveElement(): HTMLElement | null {
  let active: Element | null = document.activeElement;
  while (active?.shadowRoot?.activeElement) active = active.shadowRoot.activeElement;
  return active instanceof HTMLElement ? active : null;
}

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select, [tabindex]:not([tabindex="-1"])';

export function trapFocus(container: HTMLElement, event: KeyboardEvent): void {
  if (event.key !== 'Tab') return;
  const items = Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    (el) => !el.hasAttribute('hidden'),
  );
  if (!items.length) return;
  const first = items[0]!;
  const last = items[items.length - 1]!;
  const root = container.getRootNode() as Document | ShadowRoot;
  const active = root.activeElement;
  if (event.shiftKey && active === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && active === last) {
    event.preventDefault();
    first.focus();
  }
}

export interface ConfirmOptions {
  title: string;
  description?: string;
  details?: Array<[string, string]>;
  confirmLabel: string;
}

/** Diálogo de confirmación accesible (role="alertdialog"). Resuelve `true` si se confirma. */
export function confirmDialog(host: HTMLElement, t: Translate, options: ConfirmOptions): Promise<boolean> {
  return new Promise((resolve) => {
    const titleId = uid('wa-dialog-title');
    const descId = uid('wa-dialog-desc');
    const previous = deepActiveElement();
    const cancel = h('button', { type: 'button', className: 'wa-secondary' }, t('cancel'));
    const confirm = h('button', { type: 'button', className: 'wa-primary' }, options.confirmLabel);
    const dialog = h(
      'div',
      {
        className: 'wa-dialog',
        role: 'alertdialog',
        'aria-modal': 'true',
        'aria-labelledby': titleId,
        'aria-describedby': descId,
      },
      h('h2', { id: titleId, text: options.title }),
      h('p', { id: descId, text: options.description || '' }),
      options.details?.length
        ? h(
            'dl',
            {},
            options.details.flatMap(([k, v]) => [h('dt', { text: k }), h('dd', { text: v })]),
          )
        : null,
      h('div', { className: 'wa-dialog-actions' }, cancel, confirm),
    );
    const backdrop = h('div', { className: 'wa-backdrop' }, dialog);
    const close = (value: boolean) => {
      backdrop.remove();
      previous?.focus();
      resolve(value);
    };
    cancel.addEventListener('click', () => close(false));
    confirm.addEventListener('click', () => close(true));
    backdrop.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        close(false);
      }
      trapFocus(dialog, event);
    });
    host.appendChild(backdrop);
    // Foco inicial en la opción segura (cancelar), como recomienda WAI-ARIA para acciones con efectos.
    cancel.focus();
  });
}
