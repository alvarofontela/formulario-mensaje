import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { AdminApi, contactDefinition, notifyTo, submitWithoutJs, visitorContext } from './support/forms';
import { clearMail, waitForMail } from './support/mailpit';

// Pantalla de mensajes: la bandeja y el mensaje abierto, entregas fallidas, borrado, exportación y ajustes de datos.

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

  const item = page.locator('li.fm-message').first();
  await expect(item).toContainText('Ana "la jefa" & Cía');
  await expect(item).toContainText('Guardados e2e');
  await expect(item.locator('.fm-badge')).toHaveCount(0);

  await item.locator('.fm-message__open').click();

  await expect(page).toHaveURL(/submission=\d+/);

  const reader = page.locator('.fm-reader');

  // Quien escribe encabeza el mensaje, su correo va debajo y el texto largo se lee como el mensaje en sí.
  await expect(reader.locator('h2.fm-reader__title')).toHaveText('Ana "la jefa" & Cía');
  await expect(reader.locator('.fm-reader__contact')).toHaveText('ana@example.test');
  await expect(reader.locator('.fm-reader__meta')).toContainText('Enviado con el formulario Guardados e2e');
  // Las etiquetas del plugin no se interpretan dentro de lo que escribió el visitante.
  await expect(reader.locator('.fm-reader__text')).toHaveText('Primera línea\nSegunda línea con {reference} y =1+1');
  // Los tres datos están ya en la cabecera y en el cuerpo: no se repiten en la lista de los demás.
  await expect(reader.locator('.fm-reader__facts')).toHaveCount(0);

  // Tras el «Guardado en Mensajes», que va siempre, la única entrega del formulario.
  const trail = reader.locator('ol.fm-trail > li');
  await expect(trail).toHaveCount(2);
  await expect(trail.nth(1)).toContainText('Notificación por correo');
  await expect(trail.nth(1).locator('.fm-badge')).toHaveText('Entregada');

  // La referencia está en los detalles técnicos. Ni IP ni navegador: el formulario no los pide.
  const details = reader.locator('.fm-reader__details');
  await details.locator('summary').click();
  await expect(details.locator('dt').first()).toHaveText('Referencia');
  await expect(details.locator('dd').first()).toHaveText(/^[A-Z2-9]{4}-[A-Z2-9]{4}$/);
  await expect(details).not.toContainText('Dirección IP');

  // En un ordenador la lista y el mensaje se ven a la vez y no hay botón de volver (el del móvil lo prueba
  // panel.spec.ts): el mensaje se cierra con el botón de volver del navegador.
  await page.goBack();
  await expect(page).not.toHaveURL(/submission=/);
  await expect(page.locator('.fm-inbox__placeholder')).toBeVisible();
});

test('una notificación fallida se avisa, se reintenta y se entrega tras corregir el formulario', async ({ page }) => {
  const broken = contactDefinition();
  broken.actions[0].settings.to = [];
  const formId = await api.createForm('Fallos e2e', broken);

  await clearMail();
  await submitWithoutJs(formId, { nombre: 'Luis Gómez', correo: 'luis@example.test' });

  await page.goto(`${submissionsScreen}&form=${formId}`);

  // La bandeja avisa de que hay entregas fallidas pendientes: el globo del menú cuenta ahora los mensajes sin leer.
  await expect(page.getByRole('button', { name: /^Entregas fallidas · \d+/ })).toBeVisible();
  await expect(page.locator('li.fm-message').first().locator('.fm-badge')).toHaveText('Error');

  await page.getByRole('button', { name: 'Con problemas' }).click();
  await expect(page.getByRole('button', { name: 'Con problemas' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('li.fm-message')).toHaveCount(1);

  await page.locator('.fm-message__open').first().click();

  const reader = page.locator('.fm-reader');
  const delivery = reader.locator('ol.fm-trail > li', { hasText: 'Notificación por correo' });
  await expect(delivery.locator('.fm-badge')).toHaveText('Fallida');
  await expect(delivery).toContainText('La notificación no tiene destinatarios válidos');

  await delivery.getByRole('button', { name: 'Reintentar' }).click();
  await expect(reader.locator('.components-notice')).toContainText('Ha vuelto a fallar');
  await expect(delivery).toContainText('Intentos: 2');

  // Se corrige el destinatario del formulario y el reintento usa la configuración nueva.
  await api.updateForm(formId, 'Fallos e2e', contactDefinition());
  await delivery.getByRole('button', { name: 'Reintentar' }).click();

  await expect(reader.locator('.components-notice')).toContainText('Entregada.');
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
  // Las de toda la web se abren con un botón de la cabecera, que solo está si hay alguna.
  await page.getByRole('button', { name: /^Entregas fallidas/ }).click();

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

  const items = page.locator('li.fm-message');
  await expect(items).toHaveCount(2);

  await page.getByPlaceholder('Buscar por nombre o por lo que escribieron').fill('persona dos');
  await expect(items).toHaveCount(1);
  await expect(items).toContainText('Persona Dos');

  await page.getByPlaceholder('Buscar por nombre o por lo que escribieron').fill('');
  await expect(items).toHaveCount(2);

  // Los dos están sin leer y cuentan en el filtro, junto a los que hayan dejado otros tests.
  const unreadChip = page.locator('.fm-chips__item', { hasText: 'Sin leer' });
  await expect(unreadChip).toHaveText(/^Sin leer · \d+$/);
  const unreadBefore = Number((await unreadChip.innerText()).replace(/\D/g, ''));

  // No hay casilla para marcarlos todos: se marca cada mensaje con la suya.
  await page.getByLabel('Seleccionar el mensaje de Persona Uno').check();
  await page.getByLabel('Seleccionar el mensaje de Persona Dos').check();
  await page.getByRole('button', { name: 'Borrar 2 mensajes' }).click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('¿Borrar los mensajes?');
  await dialog.getByRole('button', { name: 'Borrar', exact: true }).click();

  await expect(page.locator('.components-notice')).toContainText('2 mensajes borrados.');
  await expect(page.locator('.fm-inbox__empty')).toContainText('Ningún mensaje coincide con estos filtros.');
  // Borrados sin haberlos leído, dejan de contar como pendientes sin recargar la pantalla.
  await expect(unreadChip).toHaveText(unreadBefore > 2 ? `Sin leer · ${unreadBefore - 2}` : 'Sin leer');
});

test('los envíos de un formulario se exportan a CSV con las fórmulas neutralizadas', async ({ page }) => {
  const formId = await api.createForm('Exportar e2e');

  await submitWithoutJs(formId, { nombre: '=HYPERLINK("http://malo.example")', correo: 'ana@example.test', mensaje: 'Hola; "con comillas"' });

  await page.goto(submissionsScreen);
  await page.getByRole('button', { name: 'Exportar CSV' }).click();

  // Las columnas dependen de los campos, así que se exporta formulario a formulario: se elige en la ventana.
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Exportar mensajes');
  await dialog.getByLabel('Formulario', { exact: true }).selectOption(String(formId));
  await expect(dialog.getByRole('link', { name: 'Descargar CSV' })).toHaveAttribute('href', new RegExp(`[?&]form=${formId}(&|$)`));

  const downloadPromise = page.waitForEvent('download');
  await dialog.getByRole('link', { name: 'Descargar CSV' }).click();
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
  // El guardado es uno de los apartados plegables del paso «Al recibirlo».
  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  await page.getByRole('button', { name: /^Guardar los mensajes/ }).click();

  await expect(page.getByLabel('Guardar los envíos')).toBeChecked();
  await page.getByLabel('Dirección IP').selectOption('anonymized');
  await page.getByLabel('Guardar el navegador (user agent)').check();
  await page.getByLabel('Borrar los envíos pasados (días)').fill('90');
  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario guardado.');

  await page.reload();
  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  await page.getByRole('button', { name: /^Guardar los mensajes/ }).click();
  await expect(page.getByLabel('Dirección IP')).toHaveValue('anonymized');
  await expect(page.getByLabel('Borrar los envíos pasados (días)')).toHaveValue('90');

  // Un envío real guarda la IP recortada y el navegador: están en los detalles técnicos del mensaje.
  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });
  await page.goto(`${submissionsScreen}&form=${formId}`);
  await page.locator('.fm-message__open').first().click();

  const details = page.locator('.fm-reader__details');
  await details.locator('summary').click();
  await expect(details.locator('dt')).toContainText(['Dirección IP', 'Navegador']);
  await expect(details.locator('dt:text-is("Dirección IP") + dd')).toHaveText(/127\.0\.0\.0|::(?!1)/);
  await expect(details.locator('dt:text-is("Navegador") + dd')).not.toBeEmpty();
});

test('el listado de formularios enlaza a sus envíos y al borrar uno se pueden borrar con él', async ({ page }) => {
  const title = `Con envíos ${Date.now()}`;
  const formId = await api.createForm(title);

  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });

  await page.goto('/wp-admin/admin.php?page=formulario-mensaje');

  const row = page.locator('li.fm-form-row', { hasText: title });
  await expect(row.locator('.fm-form-row__activity')).toContainText('1 mensaje este mes');

  // El formulario no está en ninguna página, así que el enlace a sus mensajes está en el menú de la fila.
  await row.getByRole('button', { name: `Más acciones de «${title}»` }).click();
  await expect(page.getByRole('menuitem', { name: 'Ver mensajes' })).toHaveAttribute('href', new RegExp(`formulario-mensaje-submissions&form=${formId}`));

  await page.getByRole('menuitem', { name: 'Mover a la papelera' }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario enviado a la papelera.');
  await page.getByRole('button', { name: 'Papelera', exact: true }).click();
  await row.getByRole('button', { name: `Borrar «${title}» definitivamente` }).click();

  const dialog = page.getByRole('dialog');

  // Marcar la casilla y cancelar no deja la decisión tomada para la próxima vez que se abra el diálogo.
  await dialog.getByLabel('Borrar también su mensaje guardado (1)').check();
  await dialog.getByRole('button', { name: 'Cancelar' }).click();
  await row.getByRole('button', { name: `Borrar «${title}» definitivamente` }).click();
  await expect(dialog.getByLabel('Borrar también su mensaje guardado (1)')).not.toBeChecked();

  await dialog.getByLabel('Borrar también su mensaje guardado (1)').check();
  await dialog.getByRole('button', { name: 'Borrar definitivamente' }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario borrado.');

  await page.goto(`${submissionsScreen}&form=${formId}`);
  await expect(page.locator('.fm-inbox__empty')).toBeVisible();
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
