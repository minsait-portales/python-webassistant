/** Aplicación de tokens de tema validados (CG-10.7): nunca se acepta CSS arbitrario. */
export interface ThemeTokens {
  accent?: string;
  on_accent?: string;
  text?: string;
  surface?: string;
  muted_surface?: string;
  border?: string;
  highlight?: string;
  font_family?: string;
  radius?: number;
  position?: 'left' | 'right';
  launcher_label?: string;
  logo_url?: string;
  chat_width?: number;
  z_index?: number;
}

const COLOR = /^#[0-9a-fA-F]{6}$/;
const FONTS: Record<string, string> = {
  system: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
  forfuture: '"ForFuture Sans", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
  serif: 'Georgia, "Times New Roman", serif',
  inherit: 'inherit',
};

const DEFAULTS: Required<
  Pick<ThemeTokens, 'accent' | 'on_accent' | 'text' | 'surface' | 'muted_surface' | 'border' | 'highlight'>
> = {
  accent: '#480e2a',
  on_accent: '#ffffff',
  text: '#260717',
  surface: '#ffffff',
  muted_surface: '#f6f5f1',
  border: '#e3e2da',
  highlight: '#ff0054',
};

export function themeVariables(theme: ThemeTokens | undefined): Record<string, string> {
  const vars: Record<string, string> = {};
  const source = { ...DEFAULTS, ...(theme ?? {}) };
  for (const key of Object.keys(DEFAULTS) as Array<keyof typeof DEFAULTS>) {
    const value = source[key];
    vars[`--wa-${key.replace('_', '-')}`] =
      typeof value === 'string' && COLOR.test(value) ? value : DEFAULTS[key];
  }
  const font = theme?.font_family && FONTS[theme.font_family] ? FONTS[theme.font_family] : FONTS.forfuture;
  vars['--wa-font'] = font ?? 'system-ui, sans-serif';
  const radius = Number(theme?.radius);
  vars['--wa-radius'] = `${Number.isFinite(radius) ? Math.min(24, Math.max(0, radius)) : 8}px`;
  const width = Number(theme?.chat_width);
  vars['--wa-chat-width'] = `${Number.isFinite(width) ? Math.min(640, Math.max(320, width)) : 420}px`;
  const z = Number(theme?.z_index);
  vars['--wa-z'] = String(Number.isFinite(z) ? Math.min(2147483000, Math.max(1, z)) : 2147482000);
  return vars;
}

export function applyTheme(element: HTMLElement, theme: ThemeTokens | undefined): void {
  for (const [name, value] of Object.entries(themeVariables(theme))) {
    element.style.setProperty(name, value);
  }
}
