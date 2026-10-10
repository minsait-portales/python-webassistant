import { describe, expect, it } from 'vitest';

import { h, safeUrl, text } from '../src/widget/dom';
import { applyTheme, themeVariables } from '../src/widget/theme';

describe('DOM seguro', () => {
  it('trata el contenido como texto, nunca como HTML', () => {
    const node = h('p', { text: '<img src=x onerror="alert(1)">' });
    expect(node.querySelector('img')).toBeNull();
    expect(node.textContent).toBe('<img src=x onerror="alert(1)">');
    const child = h('div', {}, '<script>alert(1)</script>');
    expect(child.querySelector('script')).toBeNull();
  });

  it('prohíbe atributos de evento inline', () => {
    expect(() => h('button', { onclick: 'alert(1)' })).toThrow();
  });

  it('solo acepta URLs http(s)', () => {
    expect(safeUrl('javascript:alert(1)')).toBeNull();
    expect(safeUrl(' JAVASCRIPT:alert(1)')).toBeNull();
    expect(safeUrl('data:text/html,<b>x</b>')).toBeNull();
    expect(safeUrl('vbscript:msgbox')).toBeNull();
    expect(safeUrl('https://shop.example.com/p?id=1')).toBe('https://shop.example.com/p?id=1');
    expect(safeUrl('/demo/producto.html', 'http://localhost:8000/demo/')).toBe(
      'http://localhost:8000/demo/producto.html',
    );
    expect(safeUrl(42)).toBeNull();
  });

  it('recorta textos largos y descarta tipos no textuales', () => {
    expect(text('x'.repeat(20), 10)).toHaveLength(10);
    expect(text({ toString: () => 'evil' })).toBe('');
    expect(text(12)).toBe('12');
  });
});

describe('Tema', () => {
  it('solo admite colores hexadecimales y valores acotados', () => {
    const vars = themeVariables({
      accent: 'red; background: url(javascript:alert(1))',
      text: '#112233',
      radius: 400,
      chat_width: 10,
      font_family: 'url(evil)',
      z_index: -5,
    });
    expect(vars['--wa-accent']).toBe('#480e2a');
    expect(vars['--wa-text']).toBe('#112233');
    expect(vars['--wa-radius']).toBe('24px');
    expect(vars['--wa-chat-width']).toBe('320px');
    expect(vars['--wa-font']).toContain('ForFuture');
    expect(vars['--wa-z']).toBe('1');
  });

  it('aplica las variables CSS al contenedor', () => {
    const node = document.createElement('div');
    applyTheme(node, { highlight: '#00aa00' });
    expect(node.style.getPropertyValue('--wa-highlight')).toBe('#00aa00');
  });
});
