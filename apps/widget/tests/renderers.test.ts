import type { ActionDescriptor } from '@webassistant/sdk';
import { describe, expect, it, vi } from 'vitest';

import { translator } from '../src/widget/i18n';
import { hasRenderer, render, type RenderContext } from '../src/widget/renderers';

function ctx(overrides: Partial<RenderContext> = {}): RenderContext {
  return {
    t: translator('es'),
    locale: 'es',
    actions: [],
    runAction: vi.fn(),
    quickReply: vi.fn(),
    ...overrides,
  };
}

const addToCart: ActionDescriptor = {
  name: 'add_to_cart',
  skill: 'product_results',
  type: 'host',
  label: 'Añadir al carrito',
  confirmation: 'none',
  target: 'item',
};

describe('Renderers', () => {
  it('registra todos los renderers v1 del contrato', () => {
    for (const type of [
      'text',
      'answer',
      'product_grid',
      'product_comparison',
      'product_summary',
      'event_selection',
      'document_citations',
      'clarification',
      'steps',
      'cart',
      'host_action',
    ]) {
      expect(hasRenderer(type, '1')).toBe(true);
    }
  });

  it('usa el fallback de texto con una presentación desconocida', () => {
    const node = render(
      { type: 'mapa_3d', version: '7', data: { description: 'Resultado alternativo' } },
      ctx(),
    );
    expect(node.textContent).toContain('Resultado alternativo');
  });

  it('no interpreta HTML ni URLs peligrosas de productos', () => {
    const node = render(
      {
        type: 'product_grid',
        version: '1',
        data: {
          heading: '<b>Ofertas</b>',
          items: [
            {
              product_id: 'p-1',
              title: '<img src=x onerror=alert(1)>Zapatilla',
              url: 'javascript:alert(1)',
              image_url: 'data:image/svg+xml,<svg onload=alert(1)>',
              price: '79,95 €',
            },
          ],
        },
      },
      ctx(),
    );
    expect(node.querySelector('img')).toBeNull();
    expect(node.querySelector('a')).toBeNull();
    expect(node.querySelector('b')).toBeNull();
    expect(node.textContent).toContain('<img src=x onerror=alert(1)>Zapatilla');
  });

  it('abre en pestaña nueva los enlaces externos con noopener', () => {
    const node = render(
      {
        type: 'answer',
        version: '1',
        data: { answer: 'Hola', sources: [{ title: 'Guía', url: 'https://otro.example.com/guia' }] },
      },
      ctx(),
    );
    const link = node.querySelector('a')!;
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    expect(link.textContent).toContain('(se abre en una pestaña nueva)');
  });

  it('las acciones por ítem envían el producto y su variación', () => {
    const runAction = vi.fn();
    const node = render(
      {
        type: 'product_grid',
        version: '1',
        data: {
          items: [{ product_id: 'p-1', variation_id: 'v-1', title: 'Zapatilla', actions: ['add_to_cart'] }],
        },
      },
      ctx({ actions: [addToCart], runAction }),
    );
    const button = node.querySelector('button')!;
    expect(button.getAttribute('aria-label')).toBe('Añadir al carrito: Zapatilla');
    button.click();
    expect(runAction).toHaveBeenCalledWith(addToCart, {
      product_id: 'p-1',
      variation_id: 'v-1',
      quantity: 1,
    });
  });

  it('no muestra acciones automáticas como botones', () => {
    const auto: ActionDescriptor = {
      name: 'get_cart',
      skill: 'shopping_cart_view',
      type: 'host',
      label: 'Cargar carrito',
      confirmation: 'none',
      auto: true,
    };
    const node = render({ type: 'text', version: '1', data: { description: 'x' } }, ctx({ actions: [auto] }));
    expect(node.querySelector('button')).toBeNull();
  });

  it('la aclaración ofrece respuestas rápidas', () => {
    const quickReply = vi.fn();
    const node = render(
      {
        type: 'clarification',
        version: '1',
        data: { question: '¿Para cuántas personas?', options: ['4', '10'] },
      },
      ctx({ quickReply }),
    );
    const buttons = node.querySelectorAll('button');
    expect(buttons).toHaveLength(2);
    buttons[1]!.click();
    expect(quickReply).toHaveBeenCalledWith('10');
  });

  it('marca las citas verificadas y muestra el documento', () => {
    const node = render(
      {
        type: 'document_citations',
        version: '1',
        data: {
          answer: 'La batería es de 5000 mAh.',
          citations: [{ document_title: 'Manual', quote: '5000 mAh', verified: true, page: 3 }],
        },
      },
      ctx(),
    );
    expect(node.querySelector('blockquote')!.textContent).toBe('5000 mAh');
    expect(node.textContent).toContain('Cita verificada');
    expect(node.textContent).toContain('Página 3');
  });

  it('el carrito muestra estado de carga accesible', () => {
    const node = render({ type: 'cart', version: '1', data: { intro: 'Tu carrito', loading: true } }, ctx());
    expect(node.getAttribute('aria-busy')).toBe('true');
    expect(node.textContent).toContain('Cargando');
  });

  it('un renderer que falla degrada a texto', () => {
    const node = render(
      {
        type: 'product_grid',
        version: '1',
        data: { items: 'no-es-una-lista', description: 'Plan B' } as never,
      },
      ctx(),
    );
    expect(node).toBeInstanceOf(HTMLElement);
  });
});
