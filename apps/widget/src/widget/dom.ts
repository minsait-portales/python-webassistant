/**
 * Construcción de DOM segura: el widget nunca inserta HTML procedente de la API
 * o del modelo. Todo texto va por `textContent` y las URLs se sanean.
 */
type Attrs = Record<string, string | number | boolean | null | undefined>;
type Child = Node | string | null | undefined | false;

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
    parent.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
  }
}

export function clear(element: Element): void {
  while (element.firstChild) element.removeChild(element.firstChild);
}

const SAFE_PROTOCOLS = new Set(['http:', 'https:']);

/** Devuelve una URL absoluta http(s) o `null`. Rechaza `javascript:`, `data:`, etc. */
export function safeUrl(value: unknown, base: string = document.baseURI): string | null {
  if (typeof value !== 'string' || !value.trim() || value.length > 2048) return null;
  try {
    const url = new URL(value.trim(), base);
    return SAFE_PROTOCOLS.has(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
}

export function text(value: unknown, max = 4000): string {
  if (value === null || value === undefined) return '';
  const str = typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '';
  return str.length > max ? `${str.slice(0, max - 1)}…` : str;
}

let counter = 0;
export function uid(prefix: string): string {
  counter += 1;
  return `${prefix}-${counter}-${Math.random().toString(36).slice(2, 7)}`;
}
