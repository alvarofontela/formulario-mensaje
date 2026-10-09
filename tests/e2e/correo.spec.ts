import { expect, test } from '@playwright/test';
import { env } from './support/env';
import { AdminApi, contactDefinition, notifyTo, submitWithoutJs } from './support/forms';
import { clearMail, getMail, mailMatching, waitForMail } from './support/mailpit';

// Correo avanzado: varias notificaciones con condición, correo en HTML con la marca, envío por SMTP y pruebas.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';
const settingsScreen = '/wp-admin/admin.php?page=formulario-mensaje-settings';

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test.beforeEach(async () => {
  await clearMail();
});

test.afterEach(async () => {
  // Los ajustes de correo vuelven a los de fábrica para no afectar a otros tests.
  await api.saveMailSettings({});
});

const sentTo = (address: string) => (mail: { To: { Address: string }[] }) => mail.To.some((to) => to.Address === address);

test('dos notificaciones con condición avisan a un destinatario u otro según la respuesta', async ({ page }) => {
  const definition = contactDefinition();

  definition.fields.push({
    id: 'dpto',
    type: 'select',
    settings: {
      label: 'Departamento',
      options: [
        { label: 'Ventas', value: 'ventas' },
        { label: 'Soporte', value: 'soporte' },
      ],
    },
  });

  const formId = await api.createForm('Departamentos e2e', definition);

  await page.goto(`${formsScreen}&form=${formId}`);
  await page.getByRole('tab', { name: 'Notificaciones' }).click();

  // La única notificación se muestra abierta: se le pone nombre y condición.
  await page.getByLabel('Nombre', { exact: true }).fill('Aviso a ventas');
  await page.getByRole('button', { name: 'Añadir regla' }).click();
  await page.getByLabel('Campo', { exact: true }).selectOption({ label: 'Departamento' });
  await page.getByLabel('Valor', { exact: true }).selectOption({ label: 'Ventas' });

  // La segunda se abre al añadirla y la primera se pliega.
  await page.getByRole('button', { name: 'Añadir notificación' }).click();
  await page.getByLabel('Nombre', { exact: true }).fill('Aviso a soporte');
  await page.getByLabel('Enviar a', { exact: true }).fill('soporte@example.test');
  await page.getByLabel('Asunto', { exact: true }).fill('Soporte: {field:nombre}');
  await page.getByRole('button', { name: 'Añadir regla' }).click();
  await page.getByLabel('Campo', { exact: true }).selectOption({ label: 'Departamento' });
  await page.getByLabel('Valor', { exact: true }).selectOption({ label: 'Soporte' });

  const rows = page.locator('.fm-fields__item');
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toContainText('Aviso a ventas');
  await expect(rows.nth(0)).toContainText('Con condición');

  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario guardado.');

  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test', dpto: 'soporte' });

  const mail = await waitForMail(sentTo('soporte@example.test'));
  expect(mail.Subject).toBe('Soporte: Ana');
  expect(await mailMatching(sentTo(notifyTo))).toHaveLength(0);

  await clearMail();
  await submitWithoutJs(formId, { nombre: 'Luis', correo: 'luis@example.test', dpto: 'ventas' });

  await waitForMail(sentTo(notifyTo));
  expect(await mailMatching(sentTo('soporte@example.test'))).toHaveLength(0);

  // Las condiciones se conservan al volver a abrir el formulario.
  await page.reload();
  await page.getByRole('tab', { name: 'Notificaciones' }).click();
  await page.locator('.fm-fields__toggle', { hasText: 'Aviso a soporte' }).click();
  await expect(page.getByLabel('Valor', { exact: true })).toHaveValue('soporte');
});

test('la notificación en HTML lleva la marca del sitio y una versión en texto', async () => {
  await api.saveMailSettings({
    from_email: 'web@example.test',
    from_name: 'Mi Tienda',
    brand_color: '#ff6600',
    brand_footer: 'Mi Tienda S.L. · Calle Mayor 1',
  });

  const definition = contactDefinition();
  definition.actions[0].settings.format = 'html';

  const formId = await api.createForm('Marca e2e', definition);

  await submitWithoutJs(formId, { nombre: 'Ana <b>Pérez</b>', correo: 'ana@example.test', mensaje: 'Hola' });

  const mail = await waitForMail(sentTo(notifyTo));
  const detail = await getMail(mail.ID);

  expect(mail.From).toEqual({ Name: 'Mi Tienda', Address: 'web@example.test' });
  expect(detail.HTML).toContain('border-top:4px solid #ff6600');
  expect(detail.HTML).toContain('Mi Tienda S.L. · Calle Mayor 1');
  expect(detail.HTML).toContain('<table role="presentation"');
  // Lo que escribe el visitante llega como texto, no como HTML.
  expect(detail.HTML).not.toContain('<b>Pérez</b>');
  expect(detail.Text).toContain('Nombre: Ana');
});

test('una notificación se prueba desde el constructor con datos de ejemplo y sin avisar a los destinatarios', async ({ page }) => {
  const formId = await api.createForm('Prueba e2e');

  await page.goto(`${formsScreen}&form=${formId}`);
  await page.getByRole('tab', { name: 'Notificaciones' }).click();

  await expect(page.getByLabel('Enviar una prueba a')).toHaveValue(env.admin.email);
  await page.getByRole('button', { name: 'Enviar prueba' }).click();
  await expect(page.locator('.fm-test .components-notice')).toContainText(`Prueba enviada a ${env.admin.email}.`);

  const mail = await waitForMail(sentTo(env.admin.email));
  const detail = await getMail(mail.ID);

  expect(mail.Subject).toBe('[Prueba] Nuevo envío: Prueba e2e');
  expect(detail.Text).toContain('Correo: name@example.com');
  expect(await mailMatching(sentTo(notifyTo))).toHaveLength(0);
});

test('los correos se envían por el servidor SMTP configurado y la contraseña nunca vuelve al navegador', async ({ page }) => {
  const password = 'secreto-e2e-9f3a';

  await page.goto(settingsScreen);
  await page.getByRole('tab', { name: 'Correo' }).click();

  await page.getByLabel('Un servidor SMTP').check();
  await page.getByLabel('Servidor', { exact: true }).fill('127.0.0.1');
  // Un puerto en el que no escucha nadie: la prueba tiene que fallar y decirlo.
  await page.getByLabel('Puerto').fill('1');
  await page.getByLabel('Cifrado').selectOption({ label: 'Ninguno' });
  await page.getByLabel('El servidor pide usuario y contraseña').check();
  await page.getByLabel('Usuario', { exact: true }).fill('usuario-e2e');
  await page.getByLabel('Contraseña', { exact: true }).fill(password);

  // Con cambios sin guardar no se puede probar: la prueba usa los ajustes guardados.
  await expect(page.getByRole('button', { name: 'Enviar prueba' })).toBeDisabled();

  await page.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Ajustes guardados.');

  await page.reload();
  await page.getByRole('tab', { name: 'Correo' }).click();

  await expect(page.getByLabel('Un servidor SMTP')).toBeChecked();
  await expect(page.getByLabel('Usuario', { exact: true })).toHaveValue('usuario-e2e');
  await expect(page.getByLabel('Contraseña', { exact: true })).toHaveValue('');
  await expect(page.getByLabel('Contraseña', { exact: true })).toHaveAttribute('placeholder', /Guardada/);
  expect(await page.content()).not.toContain(password);

  await page.getByRole('button', { name: 'Enviar prueba' }).click();
  await expect(page.locator('.fm-test .components-notice.is-error')).toBeVisible({ timeout: 30_000 });
  expect(await mailMatching(sentTo(env.admin.email))).toHaveLength(0);

  // Con el servidor de correo del entorno, que no pide credenciales, la prueba llega.
  await page.getByLabel('Puerto').fill(String(env.mailpit.smtpPort));
  await page.getByLabel('El servidor pide usuario y contraseña').uncheck();
  await page.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Ajustes guardados.');

  await page.getByRole('tab', { name: 'Correo' }).click();
  await page.getByRole('button', { name: 'Enviar prueba' }).click();
  await expect(page.locator('.fm-test .components-notice')).toContainText(`Prueba enviada a ${env.admin.email}.`, { timeout: 30_000 });

  const mail = await waitForMail(sentTo(env.admin.email));
  expect(mail.Subject).toMatch(/^Correo de prueba de /);

  // Y las notificaciones de los formularios salen por el mismo servidor.
  const formId = await api.createForm('SMTP e2e');
  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });
  await waitForMail(sentTo(notifyTo));
});
