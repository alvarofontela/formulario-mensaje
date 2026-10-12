import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { AdminApi, contactDefinition, submitWithoutJs } from './support/forms';
import { clearMail } from './support/mailpit';
import { resetSink, sinkRequests, sinkUrl, waitForSink } from './support/sink';

// Webhooks: se configuran y se prueban en el constructor, y se envían en segundo plano con cada envío.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';
const submissionsScreen = '/wp-admin/admin.php?page=formulario-mensaje-submissions';
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
  await resetSink();
});

/** Definición de un formulario de contacto cuya única acción es un webhook al receptor del entorno. */
function webhookDefinition(settings: Record<string, unknown> = {}) {
  return contactDefinition({
    actions: [{ id: 'wh', type: 'webhook', enabled: true, name: 'CRM', settings: { url: sinkUrl, ...settings } }],
  });
}

test('un webhook se configura, se prueba desde el constructor y firma lo que envía', async ({ page }) => {
  const secret = 'secreto-e2e-7c1d';
  const formId = await api.createForm('Webhook e2e', contactDefinition({ actions: [] }));

  await page.goto(`${formsScreen}&form=${formId}`);
  // Los webhooks son uno de los apartados plegables del paso «Al recibirlo»; al abrirlo se cierra el de las notificaciones.
  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  await page.getByRole('button', { name: /^Conectar con otra aplicación/ }).click();
  await expect(page.locator('.fm-fields__empty')).toContainText('no envía ningún webhook');

  await page.getByRole('button', { name: 'Añadir webhook' }).click();
  await page.getByLabel('Nombre', { exact: true }).fill('CRM');

  // Sin dirección no hay nada que probar.
  await expect(page.getByRole('button', { name: 'Enviar webhook de prueba' })).toBeDisabled();

  // Una dirección de la red interna se rechaza sin llegar a pedirla.
  await page.getByLabel('Dirección de destino').fill('http://127.0.0.1:1/interno');
  await page.getByRole('button', { name: 'Enviar webhook de prueba' }).click();
  await expect(page.locator('.fm-test .components-notice.is-error')).toBeVisible();
  expect(await sinkRequests()).toHaveLength(0);

  await page.getByLabel('Dirección de destino').fill(sinkUrl);
  await page.getByRole('button', { name: 'Añadir cabecera' }).click();
  await page.getByLabel('Cabecera', { exact: true }).fill('X-Origen');
  await page.getByLabel('Valor', { exact: true }).fill('web');
  await page.getByLabel('Secreto de firma').fill(secret);

  // La prueba usa lo que hay en pantalla, sin guardar.
  await page.getByRole('button', { name: 'Enviar webhook de prueba' }).click();
  await expect(page.locator('.fm-test .components-notice.is-success')).toContainText('código HTTP 200');
  await expect(page.locator('.fm-test__response')).toContainText('"ok":true');

  const [request] = await sinkRequests();
  const body = JSON.parse(request.body);

  expect(request.method).toBe('POST');
  expect(request.headers.x_origen).toBe('web');
  expect(request.headers.x_formulario_mensaje_test).toBe('1');
  expect(request.headers.content_type).toContain('application/json');
  expect(body.form.title).toBe('Webhook e2e');
  expect(body.fields.correo).toBe('name@example.com');

  // El destino puede comprobar la firma con el secreto compartido.
  const expected = createHmac('sha256', secret).update(`${request.headers.x_formulario_mensaje_timestamp}.${request.body}`).digest('hex');
  expect(request.headers.x_formulario_mensaje_signature).toBe(`sha256=${expected}`);

  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Formulario guardado.');

  // Al volver, el secreto está guardado pero no viaja al navegador.
  await page.reload();
  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  // El apartado resume lo que hay sin abrirlo.
  await expect(page.getByRole('button', { name: /^Conectar con otra aplicación/ })).toContainText('1 webhook activo');
  await page.getByRole('button', { name: /^Conectar con otra aplicación/ }).click();

  await expect(page.locator('.fm-fields__item')).toContainText('CRM');
  await expect(page.locator('.fm-fields__item')).toContainText(`POST ${sinkUrl}`);
  await expect(page.getByLabel('Secreto de firma')).toHaveValue('');
  await expect(page.getByLabel('Secreto de firma')).toHaveAttribute('placeholder', /valor guardado/);
  await expect(page.getByLabel('Cabecera', { exact: true })).toHaveValue('X-Origen');
  expect(await page.content()).not.toContain(secret);
});

test('el webhook de un envío sale en segundo plano con los datos que espera el destino', async ({ page }) => {
  const formId = await api.createForm(
    'Mapeo e2e',
    webhookDefinition({
      method: 'PUT',
      payload_mode: 'custom',
      payload: [
        { key: 'email', value: '{field:correo}' },
        { key: 'origen', value: 'web · {form_title}' },
      ],
    }),
  );

  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });

  const [request] = await waitForSink(1);

  expect(request.method).toBe('PUT');
  expect(JSON.parse(request.body)).toEqual({ email: 'ana@example.test', origen: 'web · Mapeo e2e' });
  expect(request.headers.x_formulario_mensaje_reference).toMatch(/^[A-Z2-9]{4}-[A-Z2-9]{4}$/);
  expect(request.headers.x_formulario_mensaje_test).toBeUndefined();

  // El mensaje, abierto en la bandeja, dice qué se hizo con él: tras el «Guardado en Mensajes», su única entrega.
  await page.goto(`${submissionsScreen}&form=${formId}`);
  await page.locator('.fm-message__open').first().click();

  const trail = page.locator('ol.fm-trail > li');
  await expect(trail).toHaveCount(2);

  const delivery = trail.nth(1);
  await expect(delivery).toContainText('Webhook');
  await expect(delivery.locator('.fm-badge')).toHaveText('Entregada');
});

test('si el destino falla, el webhook queda en cola con su error y la fecha del siguiente intento', async ({ page }) => {
  await resetSink(500);

  const formId = await api.createForm('Fallo e2e', webhookDefinition());

  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });
  await waitForSink(1);

  await page.goto(`${submissionsScreen}&form=${formId}`);
  await page.locator('.fm-message__open').first().click();

  const trail = page.locator('ol.fm-trail > li');
  await expect(trail).toHaveCount(2);

  const delivery = trail.nth(1);
  await expect(delivery.locator('.fm-badge')).toHaveText('En cola');
  await expect(delivery).toContainText('código HTTP 500');
  await expect(delivery).toContainText('Siguiente intento:');
  // Mientras se reintenta solo, no cuenta como fallo ni se puede reintentar a mano.
  await expect(delivery.getByRole('button', { name: 'Reintentar' })).toHaveCount(0);
});

test('los ajustes guardan los avisos de fallos y los días del registro de entregas', async ({ page }) => {
  // Se parte de los valores de fábrica: guardar unos ajustes ya no devuelve los demás a su valor inicial,
  // así que lo que dejó una pasada anterior seguiría ahí.
  await api.saveSettings({ failure_alerts: true, failure_alert_email: '', delivery_log_days: 30 });
  await page.goto(settingsScreen);

  await expect(page.getByLabel('Avisarme por correo cuando falle una acción')).toBeChecked();
  await page.getByLabel('Dirección para esos correos').fill('avisos@example.test');
  await page.getByLabel('Días que se conserva el registro de entregas').fill('90');
  await page.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Ajustes guardados.');

  await page.reload();
  await expect(page.getByLabel('Dirección para esos correos')).toHaveValue('avisos@example.test');
  await expect(page.getByLabel('Días que se conserva el registro de entregas')).toHaveValue('90');

  await page.getByLabel('Avisarme por correo cuando falle una acción').uncheck();
  await expect(page.getByLabel('Dirección para esos correos')).toHaveCount(0);
  await page.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Ajustes guardados.');

  // Se dejan los ajustes como estaban para no afectar a otros tests.
  await api.saveSettings({ failure_alerts: true, failure_alert_email: '', delivery_log_days: 30 });
});
