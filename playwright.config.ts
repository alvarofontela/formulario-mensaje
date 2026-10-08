import { defineConfig, devices } from '@playwright/test';
import { adminStorage, env } from './tests/e2e/support/env';

// Ejecutar con `rt e2e`: arranca los servicios y apunta Playwright al Chromium de .runtime.
export default defineConfig({
  testDir: 'tests/e2e',
  outputDir: '.runtime/tmp/playwright/results',
  // Todos los tests comparten un único WordPress y una única base de datos.
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: '.runtime/tmp/playwright/report', open: 'never' }]],
  use: {
    baseURL: env.baseUrl,
    locale: 'es-ES',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: adminStorage },
      dependencies: ['setup'],
    },
  ],
});
