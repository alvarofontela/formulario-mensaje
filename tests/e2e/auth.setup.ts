import { expect, test as setup } from '@playwright/test';
import { adminStorage, env } from './support/env';

setup('iniciar sesión como administrador', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(env.admin.user);
  await page.locator('#user_pass').fill(env.admin.password);
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();

  await page.context().storageState({ path: adminStorage });
});
