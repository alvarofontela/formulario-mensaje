import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { AdminApi, contactDefinition, notifyTo, submitWithoutJs, visitorContext } from './support/forms';
import { clearMail, waitForMail } from './support/mailpit';

// Pantalla de envíos: listado, detalle, entregas fallidas, borrado, exportación y ajustes de datos.

const submissionsScreen = '/wp-admin/admin.php?page=formulario-mensaje-submissions';

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test('un envío guardado se ve en el listado y en su detalle, como texto', async ({ page }) => {
  const formId = await api.createForm('Guardados e2e');

  await submitWithoutJs(formId, {
    nombre: 'Ana "la jefa" & Cía',
    correo: 'ana@example.test',
    mensaje: 'Primera línea\nSegunda línea con {reference} y =1+1',
  });

  await page.goto(`${submissionsScreen}&form=${formId}`);

  const row = page.locator('tbody tr').first();
  await expect(row).toContainText('Ana "la jefa" & Cía');
  await expect(row).toContainText('Guardados e2e');
  await expect(row.locator('.fm-badge')).toHaveCount(0);

  await row.locator('.fm-admin__row-title').click();

  await expect(page).toHaveURL(/submission=\d+/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Envío [A-Z2-9]{4}-[A-Z2-9]{4}/);

  const fields = page.locator('.fm-detail__fields');
  await expect(fields.locator('dt')).toHaveText(['Nombre', 'Correo', 'Mensaje']);
  await expect(fields.locator('dd').nth(0)).toHaveText('Ana "la jefa" & Cía');
  // Las etiquetas del plugin no se interpretan dentro de lo que escribió el visitante.
  await expect(fields.locator('dd').nth(2)).toHaveText('Primera línea\nSegunda línea con {reference} y =1+1');
  await expect(page.locator('.fm-detail__deliveries li')).toContainText('Notificación por correo');
  await expect(page.locator('.fm-detail__deliveries .fm-badge')).toHaveText('Entregada');
  // Ni IP ni navegador: el formulario no los pide.
  await expect(page.locator('.fm-detail__meta')).not.toContainText('Dirección IP');

  await page.getByRole('button', { name: 'Envíos' }).click();
  await expect(page).not.toHaveURL(/submission=/);
});

test('una notificación fallida se avisa, se reintenta y se entrega tras corregir el formulario', async ({ page }) => {
  const broken = contactDefinition();
  broken.actions[0].settings.to = [];
  const formId = await api.createForm('Fallos e2e', broken);

  await clearMail();
  await submitWithoutJs(formId, { nombre: 'Luis Gómez', correo: 'luis@example.test' });

  await page.goto(`${submissionsScreen}&form=${formId}`);

  // El menú avisa de que hay entregas fallidas pendientes.
  await expect(page.locator('#toplevel_page_formulario-mensaje .awaiting-mod').first()).toBeVisible();
  await expect(page.locator('tbody tr').first().locator('.fm-badge')).toHaveText('Error');

  await page.getByLabel('Solo con errores').check();
  await expect(page.locator('tbody tr')).toHaveCount(1);

  await page.locator('.fm-admin__row-title').first().click();

  const delivery = page.locator('.fm-detail__deliveries li');
  await expect(delivery.locator('.fm-badge')).toHaveText('Fallida');
  await expect(delivery).toContainText('La notificación no tiene destinatarios válidos');

  await delivery.getByRole('button', { name: 'Reintentar' }).click();
  await expect(page.locator('.components-notice')).toContainText('Ha vuelto a fallar');
  await expect(delivery).toContainText('Intentos: 2');

  // Se corrige el destinatario del formulario y el reintento usa la configuración nueva.
  await api.updateForm(formId, 'Fallos e2e', contactDefinition());
  await delivery.getByRole('button', { name: 'Reintentar' }).click();

  await expect(page.locator('.components-notice')).toContainText('Entregada.');
  await expect(delivery.locator('.fm-badge')).toHaveText('Entregada');
  await expect(delivery.getByRole('button', { name: 'Reintentar' })).toHaveCount(0);

  const mail = await waitForMail((message) => message.To.some((to) => to.Address === notifyTo));
  expect(mail.Subject).toBe('Nuevo envío: Fallos e2e');
});

test('las entregas fallidas se listan aparte y se pueden descartar', async ({ page }) => {
  const broken = contactDefinition();
  broken.actions[0].settings.to = [];
  const formId = await api.createForm(`Descartar ${Date.now()}`, broken);

  await submitWithoutJs(formId, { nombre: 'Marta Ruiz', correo: 'marta@example.test' });

  await page.goto(submissionsScreen);
  await page.locator('.fm-admin__status', { hasText: 'Entregas fallidas' }).click();

  const row = page.locator('tbody tr').first();
  await expect(row).toContainText('Notificación por correo');
  await expect(row).toContainText('La notificación no tiene destinatarios válidos');

  const before = await page.locator('tbody tr').count();
  await row.getByRole('button', { name: 'Descartar' }).click();

  await expect(page.locator('.components-notice')).toContainText('Fallo descartado.');
  await expect(page.locator('tbody tr')).toHaveCount(before - 1);
});

test('los envíos se buscan y se borran varios a la vez', async ({ page }) => {
  const formId = await api.createForm('Borrar e2e');

  await submitWithoutJs(formId, { nombre: 'Persona Uno', correo: 'uno@example.test' });
  await submitWithoutJs(formId, { nombre: 'Persona Dos', correo: 'dos@example.test' });

  await page.goto(`${submissionsScreen}&form=${formId}`);
  await expect(page.locator('tbody tr')).toHaveCount(2);

  await page.getByPlaceholder('Buscar por referencia o contenido').fill('persona dos');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr')).toContainText('Persona Dos');

  await page.getByPlaceholder('Buscar por referencia o contenido').fill('');
  await expect(page.locator('tbody tr')).toHaveCount(2);

  await page.getByLabel('Seleccionar todos').check();
  await page.getByRole('button', { name: 'Borrar 2 envíos' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Borrar', exact: true }).click();

  await expect(page.locator('.components-notice')).toContainText('2 envíos borrados.');
  await expect(page.locator('.fm-admin__empty')).toContainText('Ningún envío cumple estos filtros.');
});

test('los envíos de un formulario se exportan a CSV con las fórmulas neutralizadas', async ({ page }) => {
  const formId = await api.createForm('Exportar e2e');

  await submitWithoutJs(formId, { nombre: '=HYPERLINK("http://malo.example")', correo: 'ana@example.test', mensaje: 'Hola; "con comillas"' });

  await page.goto(submissionsScreen);

  // Sin elegir formulario no se puede exportar: las columnas dependen de sus campos.
  await expect(page.getByRole('button', { name: /Exportar CSV|Elige un formulario/ })).toBeDisabled();

  await page.getByLabel('Formulario', { exact: true }).selectOption(String(formId));
  await page.getByRole('button', { name: 'Exportar CSV' }).click();

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Descargar CSV' }).click();
  const download = await downloadPromise;

  expect(download.suggestedFilename()).toMatch(/^Exportar-e2e-\d{4}-\d{2}-\d{2}\.csv$/);

  const csv = readFileSync(await download.path(), 'utf8');
  const [header, row] = csv.replace(/^﻿/, '').trim().split('\n');

  expect(header).toBe('Referencia,Fecha,Página,Nombre,Correo,Mensaje');
  expect(row).toContain(`"'=HYPERLINK(""http://malo.example"")"`);
  expect(row).toContain('"Hola; ""con comillas"""');
});

test('el guardado de cada formulario se configura en el constructor', async ({ page }) => {
  const formId = await api.createForm('Guardado e2e');

  await page.goto(`/wp-admin/admin.php?page=formulario-mensaje&form=${formId}`);
  await page.getByRole('tab', { name: 'Guardado' }).click();

  await expect(page.getByLabel('Guardar los envíos')).toBeChecked();
  await page.getByLabel('Dirección IP').selectOption('anonymized');
  await page.getByLabel('Guardar el navegador (user agent)').check();
  await page.getByLabel('Borrar los envíos pasados (días)').fill('90');
  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario guardado.');

  await page.reload();
  await page.getByRole('tab', { name: 'Guardado' }).click();
  await expect(page.getByLabel('Dirección IP')).toHaveValue('anonymized');
  await expect(page.getByLabel('Borrar los envíos pasados (días)')).toHaveValue('90');

  // Un envío real guarda la IP recortada y el navegador.
  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });
  await page.goto(`${submissionsScreen}&form=${formId}`);
  await page.locator('.fm-admin__row-title').first().click();

  const meta = page.locator('.fm-detail__meta');
  await expect(meta).toContainText('Dirección IP');
  await expect(meta).toContainText(/127\.0\.0\.0|::(?!1)/);
  await expect(meta).toContainText('Navegador');
});

test('el listado de formularios enlaza a sus envíos y al borrar uno se pueden borrar con él', async ({ page }) => {
  const title = `Con envíos ${Date.now()}`;
  const formId = await api.createForm(title);

  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });

  await page.goto('/wp-admin/admin.php?page=formulario-mensaje');

  const row = page.locator('tr', { hasText: title });
  await expect(row.getByRole('link', { name: '1', exact: true })).toHaveAttribute('href', new RegExp(`formulario-mensaje-submissions&form=${formId}`));

  await row.getByRole('button', { name: `Enviar «${title}» a la papelera` }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario enviado a la papelera.');
  await page.locator('.fm-admin__status', { hasText: 'Papelera' }).click();
  await row.getByRole('button', { name: `Borrar «${title}» definitivamente` }).click();

  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Borrar también su envío guardado (1)').check();
  await dialog.getByRole('button', { name: 'Borrar definitivamente' }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario borrado.');

  await page.goto(`${submissionsScreen}&form=${formId}`);
  await expect(page.locator('.fm-admin__empty')).toBeVisible();
});

test('los ajustes permiten rechazar los envíos sin JavaScript y lanzar la limpieza', async ({ page, browser }) => {
  const { link } = await api.createFormPage('Solo con JS e2e');

  await page.goto('/wp-admin/admin.php?page=formulario-mensaje-settings');
  await expect(page.getByLabel('Aceptar envíos sin JavaScript')).toBeChecked();
  await expect(page.getByLabel('Borrar todos los datos al desinstalar el plugin')).not.toBeChecked();

  await page.getByRole('button', { name: 'Ejecutar la limpieza ahora' }).click();
  await expect(page.locator('.components-notice')).toContainText('Limpieza hecha.');

  await page.getByLabel('Aceptar envíos sin JavaScript').uncheck();
  await page.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(page.locator('.components-notice')).toContainText('Ajustes guardados.');

  const context = await visitorContext(browser, { javaScriptEnabled: false });
  const visitor = await context.newPage();

  try {
    await visitor.goto(link);
    await visitor.getByLabel('Nombre').fill('Ana');
    await visitor.getByLabel('Correo').fill('ana@example.test');
    await visitor.getByRole('button', { name: 'Enviar' }).click();

    await expect(visitor.locator('body')).toContainText('Este formulario necesita JavaScript para enviarse');
  } finally {
    await context.close();
    await api.saveSettings({ accept_no_js: true });
  }
});
