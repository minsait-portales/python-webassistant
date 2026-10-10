import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

const ADMIN = { email: 'admin@demo.local', password: 'Demo-Admin-2026' };

async function login(page: Page, email = ADMIN.email, password = ADMIN.password): Promise<void> {
  await page.goto('/admin/');
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña').fill(password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Inicio' })).toBeVisible();
}

async function open(page: Page, section: string, heading: string | RegExp = section): Promise<void> {
  await page
    .getByRole('navigation', { name: 'Secciones' })
    .getByRole('link', { name: section, exact: true })
    .click();
  await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
  await expect(page.locator('main[aria-busy="true"]')).toHaveCount(0);
}

async function axe(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  expect(
    results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`),
  ).toEqual([]);
}

test('credenciales incorrectas muestran un error accesible', async ({ page }) => {
  await page.goto('/admin/');
  await axe(page);
  await page.getByLabel('Correo electrónico').fill(ADMIN.email);
  await page.getByLabel('Contraseña').fill('incorrecta');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.locator('.form-error')).toContainText(/credenciales|incorrect/i);
});

test('todas las secciones cargan y cumplen WCAG 2.1 AA', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await login(page);
  await axe(page);
  const sections: Array<[string, string]> = [
    ['Assistants', 'Assistants'],
    ['Políticas de agente', 'Políticas de agente'],
    ['Conocimiento', 'Bases de conocimiento'],
    ['Sites', 'Sites'],
    ['Clientes API', 'Clientes API'],
    ['Usuarios', 'Usuarios de administración'],
    ['Políticas y retención', 'Políticas y retención'],
    ['Uso y costes', 'Uso y costes'],
    ['Conversaciones', 'Conversaciones'],
    ['Auditoría', 'Auditoría'],
    ['Exportar / importar', 'Configuración'],
    ['Tenants', 'Tenants'],
    ['Precios, skills e índices', 'Plataforma'],
    ['Mi cuenta', 'Mi cuenta'],
  ];
  for (const [link, heading] of sections) {
    await open(page, link, heading);
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeFocused();
    await axe(page);
  }
  await page
    .getByRole('navigation', { name: 'Secciones' })
    .getByRole('link', { name: 'Conocimiento', exact: true })
    .click();
  await page.getByRole('link', { name: 'Documentos de Catálogo de productos' }).click();
  await expect(page.getByRole('heading', { level: 1, name: /Documentos/ })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Documentos' }).getByText('ready').first()).toBeVisible();
  await axe(page);
  expect(errors).toEqual([]);
});

test('validación de formularios con errores por campo', async ({ page }) => {
  await login(page);
  await open(page, 'Sites');
  await page.getByRole('button', { name: 'Registrar site' }).click();
  const dialog = page.getByRole('dialog', { name: 'Registrar site' });
  await dialog.getByRole('button', { name: 'Registrar site', exact: true }).click();
  await expect(dialog.getByLabel('Identificador público')).toHaveAttribute('aria-invalid', 'true');
  await expect(dialog.getByLabel('Identificador público')).toBeFocused();
  await axe(page);
  await dialog.getByLabel('Identificador público').fill('site_pruebas');
  await dialog.getByRole('textbox', { name: 'Nombre', exact: true }).fill('Portal de pruebas');
  await dialog.getByLabel('Orígenes permitidos').fill('notanorigin');
  await dialog.getByRole('button', { name: 'Registrar site', exact: true }).click();
  await expect(dialog.locator('.form-error')).not.toBeEmpty();
  await dialog.getByLabel('Orígenes permitidos').fill('https://pruebas.example.com');
  await dialog.getByRole('button', { name: 'Registrar site', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(page.getByRole('cell', { name: /^Portal de pruebas/ })).toBeVisible();
});

test('publicar, ver y restaurar versiones de un assistant', async ({ page }) => {
  await login(page);
  await open(page, 'Assistants');
  await page.getByRole('link', { name: /Asistente de catálogo/ }).click();
  await expect(
    page.getByRole('heading', { level: 1, name: 'Assistant Asistente de catálogo' }),
  ).toBeVisible();
  await page.getByLabel('Nota de la versión').fill('Versión de prueba E2E');
  await page.getByRole('button', { name: 'Publicar borrador' }).click();
  await expect(page.getByRole('cell', { name: 'Versión de prueba E2E' })).toBeVisible();
  await page.getByRole('button', { name: 'Ver versión 1' }).click();
  await expect(page.getByRole('dialog', { name: /Versión 1/ })).toBeVisible();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Restaurar versión 1' }).click();
  await page
    .getByRole('dialog', { name: 'Restaurar versión' })
    .getByRole('button', { name: 'Restaurar y publicar' })
    .click();
  await expect(page.getByRole('cell', { name: 'restaurada v1' })).toBeVisible();
});

test('exporta CSV de uso, exporta e importa configuración en simulación', async ({ page }) => {
  await login(page);
  await open(page, 'Uso y costes');
  const csv = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Exportar CSV' }).click();
  expect((await csv).suggestedFilename()).toMatch(/^uso-.*\.csv$/);

  await open(page, 'Exportar / importar', 'Configuración');
  const exported = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Descargar paquete' }).click();
  const file = await exported;
  const path = await file.path();
  await page.getByLabel('Cargar desde fichero').setInputFiles(path);
  await page.getByRole('button', { name: 'Simular importación' }).click();
  await expect(page.getByText(/Simulación: \d+ cambios/)).toBeVisible();
});

test('los permisos del rol limitan la navegación', async ({ page, request }) => {
  const token = (await (await request.post('/v1/admin/auth/login', { data: ADMIN })).json())
    .access_token as string;
  const tenants = (
    await (await request.get('/v1/admin/tenants', { headers: { Authorization: `Bearer ${token}` } })).json()
  ).items as Array<{ id: string; slug: string }>;
  const tenant = tenants.find((t) => t.slug === 'demo')!;
  const email = `lector-${Date.now()}@demo.local`;
  const created = await request.post(`/v1/admin/tenants/${tenant.id}/users`, {
    headers: { Authorization: `Bearer ${token}` },
    data: { email, name: 'Lector', role: 'viewer', password: 'Lector-Seguro-2026' },
  });
  expect(created.ok()).toBe(true);
  await login(page, email, 'Lector-Seguro-2026');
  const nav = page.getByRole('navigation', { name: 'Secciones' });
  await expect(nav.getByRole('link', { name: 'Assistants', exact: true })).toBeVisible();
  await expect(nav.getByRole('link', { name: 'Sites', exact: true })).toHaveCount(0);
  await expect(nav.getByRole('link', { name: 'Auditoría', exact: true })).toHaveCount(0);
  await page.goto(`/admin/#/t/${tenant.id}/sites`);
  await expect(page.getByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeVisible();
  await open(page, 'Assistants');
  await expect(page.getByRole('button', { name: 'Nuevo assistant' })).toHaveCount(0);
});
