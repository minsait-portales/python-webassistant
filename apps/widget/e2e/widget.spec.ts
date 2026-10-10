import { expect, test } from '@playwright/test';

import { ask, axe, freshPage, input, launcher } from './fixtures';

test.describe('Widget en la página anfitriona', () => {
  test('flujo completo solo con teclado y foco gestionado', async ({ page }) => {
    const problems = await freshPage(page);
    await launcher(page).focus();
    await page.keyboard.press('Enter');
    await expect(input(page)).toBeFocused();
    await expect(page.getByRole('dialog', { name: 'Asistente de catálogo' })).toBeVisible();
    await page.keyboard.type('¿Cómo puedo devolver un pedido?');
    await page.keyboard.press('Enter');
    await expect(page.getByRole('region', { name: 'Fuentes' }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /Devoluciones y reembolsos/ }).first()).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Asistente de catálogo' })).toBeHidden();
    await expect(launcher(page)).toBeFocused();
    expect(problems).toEqual([]);
  });

  test('sin violaciones WCAG 2.1 AA con resultados ricos', async ({ page }) => {
    await freshPage(page);
    await axe(page);
    await launcher(page).click();
    await axe(page);
    await ask(page, 'Organiza una barbacoa para 10 personas');
    await expect(page.getByRole('button', { name: 'Añadir toda la selección' })).toBeVisible();
    await axe(page);
  });

  test('acción con efectos: confirmación accesible y host action del portal', async ({ page }) => {
    await freshPage(page);
    await launcher(page).click();
    await ask(page, 'Organiza una barbacoa para 10 personas');
    await page.getByRole('button', { name: 'Añadir toda la selección' }).click();
    const dialog = page.getByRole('alertdialog', { name: 'Añadir toda la selección' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Cancelar' })).toBeFocused();
    await axe(page);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.locator('[data-cart-count]').first()).toHaveText('0');
    await page.getByRole('button', { name: 'Añadir toda la selección' }).click();
    await dialog.getByRole('button', { name: 'Confirmar' }).click();
    await expect(page.locator('[data-cart-count]').first()).toHaveText('5');

    await ask(page, 'Enséñame el carrito');
    const remove = page.getByRole('button', { name: /^Eliminar: / });
    await expect(remove).toHaveCount(5);
    await remove.first().click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Confirmar' }).click();
    await expect(page.locator('[data-cart-count]').first()).toHaveText('4');
    await expect(page.getByRole('button', { name: /^Eliminar: / })).toHaveCount(4);
  });

  test('sugerencias, citas verificadas, fuera de ámbito y nuevo chat', async ({ page }) => {
    await freshPage(page);
    await launcher(page).click();
    await page.getByRole('button', { name: '¿Qué dice el manual sobre la batería?' }).click();
    await expect(page.getByText('Cita verificada').first()).toBeVisible();
    await ask(page, '¿Quién ganó el mundial de 1982?');
    await expect(page.locator('webassistant-chat .wa-message--assistant').last()).toContainText(
      /sitio|catálogo|tienda/i,
    );
    await page.getByRole('button', { name: 'Nuevo chat' }).click();
    await expect(page.getByText('Cita verificada')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Organiza una barbacoa para 10 personas' })).toBeVisible();
  });

  test('conserva la conversación al recargar la página', async ({ page }) => {
    await freshPage(page);
    await launcher(page).click();
    await ask(page, 'Recomiéndame unas zapatillas para empezar a correr');
    await page.reload();
    await launcher(page).click();
    await expect(page.locator('webassistant-chat .wa-message--user')).toHaveCount(1);
    await expect(page.locator('webassistant-chat .wa-message--user')).toContainText(
      'Recomiéndame unas zapatillas para empezar a correr',
    );
    await expect(page.getByRole('button', { name: /^Añadir al carrito: / }).first()).toBeVisible();
  });

  test('resiste CSS agresivo de la página', async ({ page }) => {
    const problems = await freshPage(page, '/demo/css-agresivo.html');
    await launcher(page).click();
    const panel = page.getByRole('dialog', { name: 'Asistente de catálogo' });
    await expect(panel).toBeVisible();
    const box = await panel.boundingBox();
    expect(box?.width).toBe(420);
    expect(box?.x).toBe(0);
    const styles = await input(page).evaluate((el) => {
      const css = getComputedStyle(el);
      return { font: css.fontFamily, display: css.display, color: css.color };
    });
    expect(styles.font).not.toContain('Comic');
    expect(styles.display).not.toBe('none');
    expect(styles.color).not.toBe('rgb(0, 255, 0)');
    await ask(page, '¿Cómo puedo devolver un pedido?');
    await expect(page.getByRole('link', { name: /Devoluciones y reembolsos/ }).first()).toBeVisible();
    await axe(page, 'webassistant-chat');
    expect(problems).toEqual([]);
  });

  test('muestra los resultados en el contenedor de la página y lo restaura', async ({ page }) => {
    await freshPage(page, '/demo/contenedor.html');
    await expect(page.getByText('Contenido original de la página')).toBeVisible();
    await launcher(page).click();
    await ask(page, 'Recomiéndame unas zapatillas para empezar a correr');
    const region = page.getByRole('region', { name: 'Resultado del asistente' });
    await expect(region.getByRole('button', { name: /^Añadir al carrito: / }).first()).toBeVisible();
    await expect(page.getByText('Contenido original de la página')).toBeHidden();
    await page.getByRole('button', { name: 'Ver resultado' }).click();
    await expect(region).toBeFocused();
    await page.getByRole('button', { name: 'Nuevo chat' }).click();
    await expect(page.getByText('Contenido original de la página')).toBeVisible();
    await expect(region).toHaveCount(0);
  });

  test('sesión del portal con usuario autenticado', async ({ page }) => {
    await freshPage(page);
    const sessions = page.waitForRequest(
      (request) =>
        request.url().endsWith('/v1/sessions') && (request.postData() ?? '').includes('host_token'),
    );
    await page.getByLabel('Sesión del portal').selectOption('editor');
    await page.waitForLoadState('load');
    await launcher(page).click();
    await sessions;
    await ask(page, 'Quiero crear una página nueva sobre la barbacoa');
    await expect(page.locator('webassistant-chat .wa-message--assistant').last()).not.toBeEmpty();
  });

  test('funciona con la CSP estricta de la demo y sin errores de consola', async ({ page }) => {
    const response = await page.goto('/demo/');
    expect(response?.headers()['content-security-policy']).toContain("script-src 'self'");
    const problems = await freshPage(page);
    await launcher(page).click();
    await ask(page, 'Enséñame el carrito');
    expect(problems).toEqual([]);
  });
});
