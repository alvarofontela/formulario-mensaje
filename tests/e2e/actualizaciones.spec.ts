import { expect, test } from '@playwright/test';
import { clearSimulatedRelease, simulateRelease } from './support/releases';

// Actualizaciones desde GitHub, vistas donde WordPress enseña las de cualquier plugin: la pantalla
// «Actualizaciones», el listado de plugins y la ventana «Ver detalles». La versión publicada la
// simula el entorno; ningún test pulsa «Actualizar», y el entorno lo impediría: el plugin instalado
// es un enlace al código fuente del proyecto.
//
// El conmutador de actualizaciones automáticas no se comprueba: el entorno las tiene desactivadas
// (AUTOMATIC_UPDATER_DISABLED) y WordPress no pinta esa columna.

const pluginFile = 'formulario-mensaje/formulario-mensaje.php';

test.afterEach(async () => {
  await clearSimulatedRelease();
});

test('una versión nueva publicada en GitHub se ofrece en «Actualizaciones» y en el listado de plugins', async ({ page }) => {
  await simulateRelease({
    version: '99.0.0',
    requires: '7.0',
    requires_php: '8.1',
    tested: '7.1',
    released: '2026-10-12',
    changelog: '= 99.0.0 =\n* Cambio de prueba <b>sin HTML</b>.\n\n= 98.0.0 =\n* Cambio anterior.',
  });

  // Pantalla «Actualizaciones».
  await page.goto('/wp-admin/update-core.php');

  const pending = page.locator('#update-plugins-table tr', { hasText: 'Formulario Mensaje' });

  await expect(pending).toContainText('99.0.0');
  await expect(pending.locator(`input[type="checkbox"][value="${pluginFile}"]`)).toBeVisible();

  // Listado de plugins: el aviso bajo su fila, con el enlace para actualizar y el de los detalles.
  await page.goto('/wp-admin/plugins.php');

  const notice = page.locator(`tr.plugin-update-tr[data-plugin="${pluginFile}"]`);

  await expect(notice).toContainText('99.0.0');
  await expect(notice.locator('a.update-link')).toBeVisible();
  await expect(notice.locator('a.open-plugin-details-modal')).toHaveAttribute('href', /plugin=formulario-mensaje/);

  // Ventana «Ver detalles»: la ficha la da el plugin, no WordPress.org.
  await page.goto('/wp-admin/plugin-install.php?tab=plugin-information&plugin=formulario-mensaje&section=changelog');

  await expect(page.locator('#plugin-information-title')).toContainText('Formulario Mensaje');
  await expect(page.locator('#plugin-information-content .fyi')).toContainText('99.0.0');

  const changelog = page.locator('#section-changelog');

  await expect(changelog.locator('h4').first()).toHaveText('99.0.0');
  await expect(changelog.locator('li').first()).toHaveText('Cambio de prueba sin HTML.');
  await expect(changelog.locator('b')).toHaveCount(0);
  // El botón de la ventana es el de actualizar de WordPress.
  await expect(page.locator('#plugin-information-footer a.button')).toHaveAttribute('href', /action=upgrade-plugin/);
});

test('si la última versión publicada no es más nueva que la instalada, no se ofrece nada', async ({ page }) => {
  await simulateRelease({ version: '0.0.1', changelog: '= 0.0.1 =\n* Versión antigua.' });

  await page.goto('/wp-admin/plugins.php');

  await expect(page.locator(`tr[data-plugin="${pluginFile}"]`).first()).toBeVisible();
  await expect(page.locator(`tr.plugin-update-tr[data-plugin="${pluginFile}"]`)).toHaveCount(0);
});

test('una versión preliminar o con una forma inesperada no se ofrece', async ({ page }) => {
  await simulateRelease({ version: '99.0.0-beta.1', changelog: '= 99.0.0-beta.1 =\n* Preliminar.' });

  await page.goto('/wp-admin/plugins.php');

  await expect(page.locator(`tr[data-plugin="${pluginFile}"]`).first()).toBeVisible();
  await expect(page.locator(`tr.plugin-update-tr[data-plugin="${pluginFile}"]`)).toHaveCount(0);
});
