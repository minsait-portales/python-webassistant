import { expect, test } from '@playwright/test';

import { ask, axe, freshPage, launcher } from './fixtures';

test('móvil: panel a pantalla completa, sin scroll horizontal y accesible', async ({ page }) => {
  await freshPage(page);
  await launcher(page).click();
  const panel = page.getByRole('dialog', { name: 'Asistente de catálogo' });
  const viewport = page.viewportSize()!;
  const box = await panel.boundingBox();
  expect(Math.round(box!.width)).toBe(viewport.width);
  await ask(page, 'Recomiéndame unas zapatillas para empezar a correr');
  const overflow = await page.locator('webassistant-chat').evaluate((host) => {
    const list = host.shadowRoot!.querySelector('.wa-messages') as HTMLElement;
    return list.scrollWidth - list.clientWidth;
  });
  expect(overflow).toBeLessThanOrEqual(0);
  const target = await page.getByRole('button', { name: 'Enviar' }).boundingBox();
  expect(target!.height).toBeGreaterThanOrEqual(44);
  await axe(page, 'webassistant-chat');
});
