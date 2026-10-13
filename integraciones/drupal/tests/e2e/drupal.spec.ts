import AxeBuilder from '@axe-core/playwright';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

/**
 * Conector Drupal 11 ↔ plataforma WebAssistant, extremo a extremo (proveedor simulado):
 * sincronización asíncrona con la outbox, originales PDF por URL firmada, búsqueda
 * server-to-server, widget montado en el portal, token del usuario autenticado, host
 * action de contenido y retirada al despublicar.
 */

const DRUPAL = process.env.DRUPAL_URL || 'http://localhost:8080';
const API = process.env.WEBASSISTANT_URL || 'http://localhost:8000';
const CLIENT_ID = process.env.WEBASSISTANT_DRUPAL_CLIENT_ID || 'wa_demo_portal';
const CLIENT_SECRET = process.env.WEBASSISTANT_DRUPAL_CLIENT_SECRET || 'demo-portal-secret-change-me';
const PREFIX = 'drupal-demo';
const LANG = process.env.DRUPAL_DEFAULT_LANGCODE || 'es';
const REPO = path.resolve(__dirname, '../../../..');
const COMPOSE = (
  process.env.COMPOSE || 'docker compose -f compose.yaml -f integraciones/drupal/compose.drupal.yaml'
).split(' ');

/** Ejecuta drush dentro del contenedor de Drupal. */
function drush(...args: string[]): string {
  return execFileSync(
    COMPOSE[0]!,
    [...COMPOSE.slice(1), 'exec', '-T', '-u', 'www-data', 'drupal', 'drush', ...args],
    { cwd: REPO, encoding: 'utf8' },
  );
}

async function serverToken(request: APIRequestContext): Promise<string> {
  const response = await request.post(`${API}/v1/token`, {
    data: { grant_type: 'client_credentials', client_id: CLIENT_ID, client_secret: CLIENT_SECRET },
  });
  expect(response.status()).toBe(200);
  return (await response.json()).access_token as string;
}

/** Procesa la outbox de Drupal y espera un estado remoto del documento. */
async function waitForDocument(
  request: APIRequestContext,
  knowledgeBase: string,
  sourceId: string,
  status: string,
): Promise<Record<string, unknown>> {
  const token = await serverToken(request);
  let last: Record<string, unknown> = {};
  await expect
    .poll(
      async () => {
        drush('webassistant:sync');
        const response = await request.get(
          `${API}/v1/ingest/documents/${knowledgeBase}/${encodeURIComponent(sourceId)}`,
          { headers: { Authorization: `Bearer ${token}` } },
        );
        last = response.ok() ? await response.json() : { status: `http_${response.status()}` };
        return last.status;
      },
      { timeout: 120_000, intervals: [2_000, 3_000, 5_000] },
    )
    .toBe(status);
  return last;
}

async function inventory(
  request: APIRequestContext,
  knowledgeBase: string,
): Promise<Array<Record<string, unknown>>> {
  const token = await serverToken(request);
  const response = await request.get(`${API}/v1/ingest/inventory/${knowledgeBase}?limit=1000`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  expect(response.ok()).toBeTruthy();
  return (await response.json()).items;
}

async function search(request: APIRequestContext, query: string, knowledgeBase: string) {
  const token = await serverToken(request);
  const response = await request.post(`${API}/v1/search`, {
    headers: { Authorization: `Bearer ${token}` },
    data: { query, knowledge_bases: [knowledgeBase], site_id: 'site_demo', limit: 5 },
  });
  expect(response.ok()).toBeTruthy();
  return (await response.json()).items as Array<{ url: string; title: string; source_id: string }>;
}

function launcher(page: Page) {
  return page.getByRole('button', { name: 'WebAssistant AI' });
}

async function ask(page: Page, text: string): Promise<void> {
  const input = page.getByRole('textbox', { name: 'Escribe tu consulta' });
  await input.fill(text);
  await input.press('Enter');
  await expect(page.getByRole('button', { name: 'Detener' })).toBeHidden();
  await expect(page.locator('webassistant-chat .wa-bubble[aria-busy="true"]')).toHaveCount(0);
}

async function freshPage(page: Page, url: string): Promise<string[]> {
  const problems: string[] = [];
  page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
  await page.goto(url);
  await page.evaluate(() => {
    localStorage.clear();
    sessionStorage.clear();
  });
  await page.reload();
  await page.waitForSelector('webassistant-chat', { state: 'attached' });
  return problems;
}

test.describe.configure({ mode: 'serial' });

test.describe('Conector Drupal 11', () => {
  test('sincroniza lo publicado (texto y PDF por referencia) y nunca el borrador', async ({ request }) => {
    const page = await waitForDocument(request, 'ayuda', `${PREFIX}:node:1:${LANG}`, 'ready');
    expect(page.canonical_url).toBe(`${DRUPAL}/node/1`);
    expect(page.acl).toEqual(['public']);

    const attachments = (await inventory(request, 'documentos')).filter((item) =>
      String(item.source_id).startsWith(`${PREFIX}:node:2:${LANG}:file:`),
    );
    expect(attachments).toHaveLength(1);
    const pdf = await waitForDocument(request, 'documentos', String(attachments[0]!.source_id), 'ready');
    expect(pdf.canonical_url).toBe(`${DRUPAL}/node/2`);
    expect(Number(pdf.chunk_count)).toBeGreaterThan(0);

    const draft = await request.get(
      `${API}/v1/ingest/documents/ayuda/${encodeURIComponent(`${PREFIX}:node:3:${LANG}`)}`,
      { headers: { Authorization: `Bearer ${await serverToken(request)}` } },
    );
    expect(draft.status()).toBe(404);
  });

  test('la búsqueda server-to-server cita las URL de Drupal, nunca la URL firmada', async ({ request }) => {
    const pages = await search(request, 'horario de la oficina de Zaragoza', 'ayuda');
    // La plataforma añade el ancla del encabezado del fragmento citado (#cita-previa…).
    expect(pages[0]?.url).toMatch(new RegExp(`^${DRUPAL}/node/1(#[\\w-]+)?$`));
    const documents = await search(request, 'garantía GAR-DRUPAL-2026 servicio técnico', 'documentos');
    expect(documents[0]?.url).toMatch(new RegExp(`^${DRUPAL}/node/2(#[\\w=-]+)?$`));
    expect(documents.map((item) => item.url).join(' ')).not.toContain('/webassistant/document/');
  });

  test('el widget se monta en Drupal y cita el contenido sincronizado', async ({ page }) => {
    const problems = await freshPage(page, `${DRUPAL}/node/1`);
    await expect(page.locator('script[src$="/widget/v1/loader.js"]')).toHaveCount(1);
    await launcher(page).click();
    await ask(page, '¿Hay alguna guía de ayuda sobre la cita previa en la oficina de Zaragoza?');
    const citation = page.getByRole('link', { name: /Horario de la oficina de Zaragoza/ }).first();
    await expect(citation).toBeVisible();
    expect(await citation.getAttribute('href')).toMatch(new RegExp(`^${DRUPAL}/node/1`));
    // El PDF privado se cita con la página que lo publica, nunca con la URL firmada.
    await ask(page, '¿Qué dice el documento de garantía GAR-DRUPAL-2026?');
    const pdfCitation = page.getByRole('link', { name: /Guía de garantía/ }).first();
    await expect(pdfCitation).toBeVisible();
    expect(await pdfCitation.getAttribute('href')).toMatch(new RegExp(`^${DRUPAL}/node/2`));
    const results = await new AxeBuilder({ page })
      .include('webassistant-chat')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.map((violation) => violation.id)).toEqual([]);
    expect(problems).toEqual([]);
  });

  test('usuario autenticado: token del portal y host action de contenido', async ({ page }) => {
    await page.goto(`${DRUPAL}/user/login`);
    await page.locator('#edit-name').fill('editor');
    await page.locator('#edit-pass').fill(process.env.DRUPAL_EDITOR_PASSWORD || 'editor');
    await page.locator('#edit-submit').click();
    await expect(page).not.toHaveURL(/user\/login/);

    const session = page.waitForResponse(
      (response) => response.url().endsWith('/v1/sessions') && response.request().method() === 'POST',
    );
    await freshPage(page, `${DRUPAL}/node/1`);
    await launcher(page).click();
    await ask(page, 'Hola');
    expect((await (await session).json()).subject_type).toBe('user');

    await ask(page, 'Quiero crear una página nueva en el portal');
    // La host action content.create la ejecuta Drupal (permiso y acceso de creación) y navega al formulario.
    await page.getByRole('button', { name: 'Abrir formulario' }).click();
    await page.waitForURL(/\/node\/add\/page/, { timeout: 30_000 });
    await expect(page.locator('#edit-title-0-value')).toBeVisible();
  });

  test('despublicar en Drupal retira el documento de la plataforma', async ({ request }) => {
    drush('php:eval', '$n = \\Drupal\\node\\Entity\\Node::load(1); $n->setUnpublished(); $n->save();');
    await waitForDocument(request, 'ayuda', `${PREFIX}:node:1:${LANG}`, 'deleted');
    const pages = await search(request, 'horario de la oficina de Zaragoza', 'ayuda');
    expect(pages.filter((item) => item.url.startsWith(`${DRUPAL}/node/1`))).toEqual([]);
    drush('php:eval', '$n = \\Drupal\\node\\Entity\\Node::load(1); $n->setPublished(); $n->save();');
    await waitForDocument(request, 'ayuda', `${PREFIX}:node:1:${LANG}`, 'ready');
  });
});
