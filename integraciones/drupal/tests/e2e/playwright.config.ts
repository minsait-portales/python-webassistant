/**
 * Prueba extremo a extremo del conector Drupal: Drupal 11 con el módulo, la plataforma
 * WebAssistant y el widget, levantados con
 *
 *   docker compose -f compose.yaml -f integraciones/drupal/compose.drupal.yaml up -d --build --wait
 *   npx playwright test -c integraciones/drupal/tests/e2e/playwright.config.ts
 */
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  outputDir: 'test-results',
  timeout: 180_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
    : 'list',
  use: {
    baseURL: process.env.DRUPAL_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    ...devices['Desktop Chrome'],
    viewport: { width: 1280, height: 900 },
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH }
      : {},
  },
});
