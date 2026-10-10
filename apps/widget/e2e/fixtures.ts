import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page } from '@playwright/test';

export const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

export async function freshPage(page: Page, path = '/demo/'): Promise<string[]> {
  const problems: string[] = [];
  page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') problems.push(`console: ${message.text()}`);
  });
  await page.goto(path);
  await page.evaluate(() => {
    localStorage.clear();
    sessionStorage.clear();
  });
  await page.reload();
  await page.waitForSelector('webassistant-chat', { state: 'attached' });
  return problems;
}

export function launcher(page: Page): Locator {
  return page.getByRole('button', { name: 'WebAssistant AI' });
}

export function input(page: Page): Locator {
  return page.getByRole('textbox', { name: 'Escribe tu consulta' });
}

/** Envía una consulta y espera a que termine el run. */
export async function ask(page: Page, text: string): Promise<void> {
  await input(page).fill(text);
  await input(page).press('Enter');
  await expect(page.getByRole('button', { name: 'Detener' })).toBeHidden();
  await expect(page.locator('webassistant-chat .wa-bubble[aria-busy="true"]')).toHaveCount(0);
}

export async function axe(page: Page, include?: string): Promise<void> {
  let builder = new AxeBuilder({ page }).withTags(WCAG_TAGS);
  if (include) builder = builder.include(include);
  const results = await builder.analyze();
  const summary = results.violations.map(
    (v) => `${v.id} (${v.impact}): ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`,
  );
  expect(summary).toEqual([]);
}
