import { expect, test } from '@playwright/test';
import { env } from './support/env';
import { clearMail, waitForMail } from './support/mailpit';

// Comprueban que el entorno está operativo. Sirven de plantilla para los tests del plugin.

test('la portada carga', async ({ page }) => {
  const response = await page.goto('/');

  expect(response?.status()).toBe(200);
  await expect(page.locator('html')).toHaveAttribute('lang', 'es');
});

test('el escritorio de administración es accesible con la sesión guardada', async ({ page }) => {
  await page.goto('/wp-admin/');

  await expect(page.locator('#wpbody-content h1').first()).toBeVisible();
});

test('los correos que envía WordPress llegan a Mailpit', async ({ page }) => {
  await clearMail();

  await page.goto('/wp-login.php?action=lostpassword');
  await page.locator('#user_login').fill(env.admin.user);
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/checkemail=confirm/);

  const mail = await waitForMail((m) => m.To.some((to) => to.Address === env.admin.email));
  expect(mail.Subject).not.toBe('');
});
