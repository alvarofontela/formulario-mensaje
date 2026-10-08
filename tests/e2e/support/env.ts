import { readFileSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(__dirname, '../../..');

export interface RuntimeEnv {
  baseUrl: string;
  slug: string;
  admin: { user: string; password: string; email: string };
  mailpit: { url: string; smtpPort: number };
  paths: { root: string; runtime: string; www: string; plugin: string; logs: string };
}

// Lo genera `rt setup` a partir de scripts/runtime.config.psd1.
export const env: RuntimeEnv = JSON.parse(
  readFileSync(path.join(root, '.runtime/conf/env.json'), 'utf8'),
);

/** Sesión de administrador que guarda auth.setup.ts y reutilizan los demás tests. */
export const adminStorage = path.join(root, '.runtime/tmp/playwright/admin.json');
