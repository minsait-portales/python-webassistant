/** E2E de la consola (CG-11, CG-14): flujos de administración y accesibilidad con axe. */
import { defineConfig, devices } from '@playwright/test';

const PORT = Number(process.env.E2E_PORT || 8766);
const BASE_URL = process.env.E2E_BASE_URL || `http://localhost:${PORT}`;

export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    ...devices['Desktop Chrome'],
    viewport: { width: 1360, height: 900 },
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_PATH
      ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH }
      : {},
  },
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: `node ../widget/e2e/serve.mjs ${PORT}`,
        url: `${BASE_URL}/health/ready`,
        timeout: 120_000,
        reuseExistingServer: !process.env.CI,
      },
});
