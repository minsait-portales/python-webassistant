import { describe, expect, it, vi } from 'vitest';

import { Announcer, confirmDialog } from '../src/widget/a11y';
import { EventBus } from '../src/widget/events';
import { translator } from '../src/widget/i18n';
import { testGlobal } from './helpers';

describe('Eventos del widget', () => {
  it('notifica a los suscriptores, al comodín y como CustomEvent del documento', () => {
    const global = testGlobal();
    const typed = vi.fn();
    global.on('response', typed);
    const domListener = vi.fn();
    document.addEventListener('webassistant:response', domListener);
    const broken = vi.fn(() => {
      throw new Error('fallo del portal');
    });
    global.on('response', broken);
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const event = new EventBus('site_demo:default:panel', global).emit(
      'response',
      { skill: 'default' },
      { conversation_id: 'conv_1' },
    );
    expect(event).toMatchObject({
      version: '1',
      type: 'response',
      instance_id: 'site_demo:default:panel',
      conversation_id: 'conv_1',
    });
    expect(typed).toHaveBeenCalledWith(event);
    expect(global.events.map((e) => e.type)).toEqual(['response']);
    expect(domListener).toHaveBeenCalledTimes(1);
    expect(broken).toHaveBeenCalled();
  });
});

describe('Accesibilidad', () => {
  it('anuncia cada mensaje una sola vez', async () => {
    vi.useFakeTimers();
    const announcer = new Announcer();
    announcer.say('El asistente ha respondido');
    announcer.say('El asistente ha respondido');
    vi.advanceTimersByTime(40);
    expect(announcer.polite.textContent).toBe('El asistente ha respondido');
    expect(announcer.polite.getAttribute('aria-live')).toBe('polite');
    announcer.say('Error', true);
    vi.advanceTimersByTime(40);
    expect(announcer.assertive.textContent).toBe('Error');
    vi.useRealTimers();
  });

  it('el diálogo de confirmación enfoca Cancelar, se cierra con Escape y devuelve el foco', async () => {
    const host = document.createElement('div');
    const trigger = document.createElement('button');
    document.body.append(host, trigger);
    trigger.focus();
    const result = confirmDialog(host, translator('es'), {
      title: 'Eliminar producto',
      description: 'Se quitará del carrito',
      details: [['producto', 'p-1']],
      confirmLabel: 'Confirmar',
    });
    const dialog = host.querySelector('[role="alertdialog"]')!;
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(document.activeElement?.textContent).toBe('Cancelar');
    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await expect(result).resolves.toBe(false);
    expect(host.querySelector('[role="alertdialog"]')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  it('confirmar resuelve true', async () => {
    const host = document.createElement('div');
    document.body.append(host);
    const result = confirmDialog(host, translator('es'), { title: 'Añadir', confirmLabel: 'Confirmar' });
    (host.querySelector('.wa-primary') as HTMLButtonElement).click();
    await expect(result).resolves.toBe(true);
  });
});
