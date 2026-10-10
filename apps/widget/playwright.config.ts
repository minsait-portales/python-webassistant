/**
 * Pruebas E2E del widget (CG-15.5, CG-15.6, CG-14): flujo con teclado, accesibilidad con
 * axe, CSS agresivo, contenedor externo, móvil y CSP estricta, contra la API real con la
 * demo habilitada (proveedor simulado, SQLite y Qdrant en memoria).
 */
import { defineConfig, devices } from '@playwright/test';

const PORT = Number(process.env.E2E_PORT || 8765);
const BASE_URL = process.env.E2E_BASE_URL || `http://localhost:${PORT}`;

export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH }
      : {},
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } },
      testIgnore: /mobile\.spec\.ts/,
    },
    { name: 'mobile', use: { ...devices['Pixel 7'] }, testMatch: /mobile\.spec\.ts/ },
  ],
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: `node e2e/serve.mjs ${PORT}`,
        url: `${BASE_URL}/health/ready`,
        timeout: 120_000,
        reuseExistingServer: !process.env.CI,
      },
});
