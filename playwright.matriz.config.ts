import { defineConfig, devices } from '@playwright/test';
import { adminStorage, env } from './tests/e2e/support/env';

// Matriz de combinaciones: muchos formularios con configuraciones distintas, probados a la vez.
// Ejecutar con `rt matrix`. Es una suite aparte de `rt e2e`, que va de uno en uno porque sus tests
// vacían el buzón y cambian ajustes del sitio.
//
// Reglas para que un test pueda correr en paralelo con los demás (proyecto «paralelo»):
// - crea sus propios formularios y páginas y no toca los de otros;
// - reconoce sus correos por su destinatario y sus webhooks por su canal, y no vacía ni el buzón ni el receptor;
// - no cambia ajustes del sitio (correo, captcha, ajustes generales, plugins activos).
// Lo que necesita cambiar ajustes del sitio va en ficheros `*.global.ts`, que corren después y de uno en uno.
// Los que trabajan en el constructor van en ficheros `*.admin.ts`: cumplen las mismas reglas, con menos navegadores a la vez.
const workers = Number(process.env.RT_MATRIX_WORKERS) || 4;

export default defineConfig({
  testDir: 'tests/matriz',
  outputDir: '.runtime/tmp/playwright/matriz-results',
  workers,
  // Cada envío con JavaScript espera el tiempo mínimo de relleno (3 s) y hay varios navegadores a la vez.
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { outputFolder: '.runtime/tmp/playwright/matriz-report', open: 'never' }]],
  use: {
    baseURL: env.baseUrl,
    locale: 'es-ES',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testDir: 'tests/e2e', testMatch: /auth\.setup\.ts/ },
    {
      name: 'paralelo',
      testMatch: /\.spec\.ts$/,
      fullyParallel: true,
      use: { ...devices['Desktop Chrome'], storageState: adminStorage },
      dependencies: ['setup'],
    },
    {
      // Tests que trabajan en la pantalla del constructor. También corren en paralelo, pero de dos en dos:
      // la aplicación de administración es pesada para el Chromium del entorno (x64 emulado) y con cuatro
      // abiertas a la vez los navegadores no dan abasto, aunque el servidor responde en décimas de segundo.
      name: 'admin',
      testMatch: /\.admin\.ts$/,
      fullyParallel: true,
      workers: 2,
      timeout: 180_000,
      use: { ...devices['Desktop Chrome'], storageState: adminStorage },
      dependencies: ['setup'],
    },
    {
      name: 'global',
      testMatch: /\.global\.ts$/,
      workers: 1,
      use: { ...devices['Desktop Chrome'], storageState: adminStorage },
      // Nunca a la vez que los paralelos: cambian ajustes que afectan a todos los formularios.
      dependencies: ['paralelo', 'admin'],
    },
  ],
});
