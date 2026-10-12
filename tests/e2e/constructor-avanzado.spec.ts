import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { AdminApi, contactDefinition, visitorContext } from './support/forms';

// Constructor avanzado: plantillas de partida, campos en columnas, duplicar, exportar e importar.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test('un formulario nuevo parte de la plantilla elegida, con sus campos en columnas', async ({ page, browser }) => {
  const title = `Presupuesto ${Date.now()}`;

  await page.goto(formsScreen);
  await page.getByRole('button', { name: 'Añadir formulario' }).first().click();

  const picker = page.getByRole('dialog', { name: 'Elige una plantilla' });
  await expect(picker.locator('.fm-templates__item')).toHaveCount(5);
  await picker.getByRole('button', { name: /Solicitud de presupuesto/ }).click();

  await expect(page).toHaveURL(/form=new(%3A|:)quote/);

  const names = page.locator('.fm-fields__name');
  await expect(names).toHaveText(['Nombre', 'Empresa', 'Correo electrónico', 'Teléfono', '¿Qué necesitas?', 'Privacidad']);
  await expect(page.locator('.fm-fields__item').nth(0)).toContainText('50 %');

  // El ancho se cambia en los ajustes de cada campo.
  await page.locator('.fm-fields__toggle', { hasText: '¿Qué necesitas?' }).click();
  await page.getByLabel('Ancho', { exact: true }).selectOption({ label: 'Dos tercios' });
  await expect(page.locator('.fm-fields__item', { hasText: '¿Qué necesitas?' })).toContainText('66 %');

  const preview = page.frameLocator('.fm-preview__frame');
  await expect(preview.locator('[data-fm-field="message"]')).toHaveClass(/fm-w-66/);

  await page.getByLabel('Nombre del formulario').fill(title);
  await page.getByRole('button', { name: 'Crear formulario' }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Formulario guardado.');

  // En la web, los campos de media fila comparten fila; en un móvil, cada uno ocupa la suya.
  const formId = Number(new URL(page.url()).searchParams.get('form'));
  const created = await api.createPage(title, `<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`);
  const visitor = await visitorContext(browser);
  const front = await visitor.newPage();

  await front.setViewportSize({ width: 1200, height: 900 });
  await front.goto(created.link);

  const name = front.locator('[data-fm-field="name"]');
  const company = front.locator('[data-fm-field="company"]');
  const email = front.locator('[data-fm-field="email"]');

  const [nameBox, companyBox, emailBox] = await Promise.all([name.boundingBox(), company.boundingBox(), email.boundingBox()]);
  expect(Math.abs(nameBox!.y - companyBox!.y)).toBeLessThan(2);
  expect(companyBox!.x).toBeGreaterThan(nameBox!.x + nameBox!.width - 1);
  expect(emailBox!.y).toBeGreaterThan(nameBox!.y + nameBox!.height - 1);

  await front.setViewportSize({ width: 390, height: 800 });
  const [narrowName, narrowCompany] = await Promise.all([name.boundingBox(), company.boundingBox()]);
  expect(narrowCompany!.y).toBeGreaterThan(narrowName!.y + narrowName!.height - 1);

  await visitor.close();
});

test('un formulario se duplica, se exporta sin secretos y se importa con las acciones en pausa', async ({ page }) => {
  const title = `Origen ${Date.now()}`;
  const definition = contactDefinition();

  definition.actions.push({
    id: 'wh',
    type: 'webhook',
    enabled: true,
    name: 'CRM',
    settings: { url: 'https://crm.example.test/entrada', secret: 'secreto-e2e-export', headers: [{ name: 'Authorization', value: 'Bearer privado-e2e' }] },
  });

  await api.createForm(title, definition);
  await page.goto(formsScreen);

  // Duplicar y exportar están en el menú de acciones de cada fila. El menú se busca por su nombre
  // exacto: la copia tendrá el suyo, con «(copia)».
  const moreActions = page.getByRole('button', { name: `Más acciones de «${title}»`, exact: true });

  await moreActions.click();
  await page.getByRole('menuitem', { name: 'Duplicar', exact: true }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Formulario duplicado.');
  await expect(page.locator('li.fm-form-row', { hasText: `${title} (copia)` })).toHaveCount(1);

  const downloadPromise = page.waitForEvent('download');
  await moreActions.click();
  await page.getByRole('menuitem', { name: 'Exportar', exact: true }).click();
  const download = await downloadPromise;
  const path = await download.path();
  const text = readFileSync(path, 'utf8');
  const exported = JSON.parse(text);

  expect(download.suggestedFilename()).toMatch(/^formulario-\d+\.json$/);
  expect(exported.format).toBe('formulario-mensaje-form');
  expect(exported.title).toBe(title);
  expect(exported.definition.actions[1].settings.url).toBe('https://crm.example.test/entrada');
  expect(text).not.toContain('secreto-e2e-export');
  expect(text).not.toContain('privado-e2e');
  expect(text).not.toContain('fm1:');

  // El mismo fichero se importa como un formulario nuevo.
  exported.title = `${title} importado`;
  await page.getByLabel('Fichero del formulario que se importa').setInputFiles({
    name: 'formulario.json',
    mimeType: 'application/json',
    buffer: Buffer.from(JSON.stringify(exported)),
  });

  await expect(page.locator('.components-notice').first()).toContainText(`«${title} importado» importado`);

  await page.locator('li.fm-form-row', { hasText: `${title} importado` }).locator('.fm-admin__row-title').click();

  // Notificaciones y webhooks son apartados del paso «Al recibirlo»; el de las notificaciones ya
  // está abierto al entrar. Cada lista se busca dentro de su apartado: solo hay uno abierto a la vez.
  const openSection = (name: string) => page.locator('.fm-section.is-open', { hasText: name });

  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  await expect(openSection('Notificaciones por correo').locator('.fm-fields__item').first()).toContainText('En pausa');
  await page.getByRole('button', { name: /^Conectar con otra aplicación/ }).click();
  await expect(openSection('Conectar con otra aplicación').locator('.fm-fields__item').first()).toContainText('En pausa');

  // Un fichero cualquiera no se importa.
  await page.goto(formsScreen);
  await page.getByLabel('Fichero del formulario que se importa').setInputFiles({
    name: 'otro.json',
    mimeType: 'application/json',
    buffer: Buffer.from('{"name":"otra-cosa"}'),
  });
  await expect(page.locator('.components-notice.is-error')).toContainText('no es un formulario exportado');
});
