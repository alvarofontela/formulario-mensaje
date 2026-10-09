import { BrowserContext, Page, expect, test } from '@playwright/test';
import { AdminApi, contactDefinition, notifyTo, visitorContext } from './support/forms';
import { clearMail, getMail, mailMatching, waitForMail } from './support/mailpit';

// El formulario tal como lo usa un visitante anónimo: envío por REST, errores, confirmación y correo.

let api: AdminApi;
let context: BrowserContext;
let page: Page;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test.beforeEach(async ({ browser }) => {
  await clearMail();
  context = await visitorContext(browser);
  page = await context.newPage();
});

test.afterEach(async () => {
  await context.close();
});

const toSales = (mail: { To: { Address: string }[] }) => mail.To.some((to) => to.Address === notifyTo);

test('un envío correcto muestra el mensaje de éxito y manda la notificación', async () => {
  const { link } = await api.createFormPage(
    'Contacto e2e',
    contactDefinition({ settings: { submit_label: 'Enviar', success_message: 'Gracias, {field:nombre}. Referencia {reference}.' } }),
  );

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana Pérez');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Mensaje').fill('Quiero un presupuesto.');
  await page.getByRole('button', { name: 'Enviar' }).click();

  const success = page.locator('.fm-success');
  await expect(success).toBeVisible();
  await expect(success).toContainText(/Gracias, Ana Pérez\. Referencia [A-Z2-9]{4}-[A-Z2-9]{4}\./);
  await expect(success).toBeFocused();
  await expect(page.locator('.fm-form')).toBeHidden();

  const mail = await waitForMail(toSales);
  const detail = await getMail(mail.ID);

  expect(mail.Subject).toBe('Nuevo envío: Contacto e2e');
  expect(detail.ReplyTo.map((address) => address.Address)).toEqual(['ana@example.test']);
  expect(detail.Text).toContain('Nombre: Ana Pérez');
  expect(detail.Text).toContain('Mensaje: Quiero un presupuesto.');
});

test('los errores aparecen junto a cada campo y el foco va al primero', async () => {
  const { link } = await api.createFormPage('Errores e2e');

  await page.goto(link);
  await page.getByLabel('Correo').fill('no-es-un-correo');
  await page.getByRole('button', { name: 'Enviar' }).click();

  const name = page.getByLabel('Nombre');
  const email = page.getByLabel('Correo');

  await expect(page.locator('[data-fm-field="nombre"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(page.locator('[data-fm-field="correo"] .fm-error')).toHaveText('Escribe una dirección de correo válida.');
  await expect(page.locator('[data-fm-field="mensaje"] .fm-error')).toBeHidden();
  await expect(name).toHaveAttribute('aria-invalid', 'true');
  await expect(name).toHaveAttribute('aria-describedby', /-nombre-error/);
  await expect(name).toBeFocused();
  await expect(page.getByRole('alert')).toHaveText('Revisa los campos marcados.');
  await expect(page.locator('.fm-success')).toBeHidden();

  // Al corregir un campo desaparece su error, sin esperar a otro envío.
  await email.fill('ana@example.test');
  await expect(page.locator('[data-fm-field="correo"] .fm-error')).toBeHidden();
  await expect(email).not.toHaveAttribute('aria-invalid', 'true');

  expect(await mailMatching(toSales)).toHaveLength(0);
});

test('el formulario se puede vaciar para permitir otro envío', async () => {
  const { link } = await api.createFormPage('Vaciar e2e', contactDefinition({ settings: { submit_label: 'Enviar', after_submit: 'reset' } }));

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page.locator('.fm-success')).toBeVisible();
  await expect(page.locator('.fm-form')).toBeVisible();
  await expect(page.getByLabel('Nombre')).toHaveValue('');
});

test('tras el envío se redirige a la dirección configurada con los campos elegidos', async () => {
  const { link } = await api.createFormPage(
    'Redirigir e2e',
    contactDefinition({
      settings: { submit_label: 'Enviar', redirect_enabled: true, redirect_url: '/?gracias=1', redirect_fields: [{ field: 'nombre', param: 'cliente' }] },
    }),
  );

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana & Cía');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();

  await page.waitForURL(/gracias=1/);

  const url = new URL(page.url());
  expect(url.pathname).toBe('/');
  expect(url.searchParams.get('cliente')).toBe('Ana & Cía');
  expect(url.searchParams.has('correo')).toBe(false);
});

test('el envío avisa con un evento y en dataLayer, sin datos personales', async () => {
  const { link, formId } = await api.createFormPage('Medir e2e');

  await page.goto(link);
  await page.evaluate(() => {
    document.addEventListener('formulario-mensaje:submitted', (event) => {
      (window as unknown as { fmEvent: unknown }).fmEvent = (event as CustomEvent).detail;
    });
  });
  await page.getByLabel('Nombre').fill('Ana Secreta');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const tracked = await page.evaluate(() => {
    const scope = window as unknown as { dataLayer: unknown[]; fmEvent: unknown };
    return { dataLayer: scope.dataLayer, event: scope.fmEvent };
  });

  expect(tracked.dataLayer).toEqual([{ event: 'formulario_mensaje_submit', form_id: formId, form_name: 'Medir e2e' }]);
  expect(tracked.event).toMatchObject({ formId });
  expect(JSON.stringify(tracked)).not.toContain('Ana Secreta');
  expect(JSON.stringify(tracked)).not.toContain('ana@example.test');
});

test('un envío que rellena el campo trampa se rechaza y no manda correo', async () => {
  const { link } = await api.createFormPage('Trampa e2e');

  await page.goto(link);

  // El campo existe en la página, pero no se ve ni recibe el foco con el tabulador.
  const honeypot = page.locator('input[name="fm_website"]');
  await expect(honeypot).toHaveAttribute('tabindex', '-1');
  await expect(honeypot).not.toBeInViewport();

  await page.getByLabel('Nombre').fill('Bot');
  await page.getByLabel('Correo').fill('bot@example.test');
  await honeypot.evaluate((input: HTMLInputElement) => (input.value = 'https://spam.example'));
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page.getByRole('alert')).toContainText('No se ha podido enviar tu mensaje');
  await expect(page.locator('.fm-success')).toBeHidden();
  expect(await mailMatching(toSales)).toHaveLength(0);
});

test('los campos ocultos recogen la página, los parámetros de la URL y el prellenado', async () => {
  const definition = contactDefinition();
  definition.fields[0].settings = { label: 'Nombre', required: true, prefill_source: 'query', prefill_value: 'nombre' };
  definition.fields.push(
    { id: 'origen', type: 'hidden', settings: { label: 'Página', source: 'page_url' } },
    { id: 'campana', type: 'hidden', settings: { label: 'Campaña', source: 'utm_campaign' } },
    { id: 'plan', type: 'hidden', settings: { label: 'Plan', source: 'fixed', value: 'profesional' } },
  );

  const { link } = await api.createFormPage('Contexto e2e', definition);

  await page.goto(`${link}?utm_campaign=otoño&nombre=Ana`);
  await expect(page.getByLabel('Nombre')).toHaveValue('Ana');
  // El valor fijo sale de la configuración en el servidor: no viaja en el HTML.
  await expect(page.locator('input[name="fm[plan]"]')).toHaveCount(0);

  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const detail = await getMail((await waitForMail(toSales)).ID);

  expect(detail.Text).toContain('Campaña: otoño');
  expect(detail.Text).toContain('Plan: profesional');
  expect(detail.Text).toMatch(/Página: http.*contexto-e2e.*utm_campaign/);
});

test('las páginas sin formulario no cargan ni el CSS ni el JavaScript del plugin', async () => {
  const { link } = await api.createFormPage('Carga e2e');

  await page.goto('/');
  expect(await page.content()).not.toContain('formulario-mensaje');

  await page.goto(link);
  await expect(page.locator('link#formulario-mensaje-form-css')).toHaveCount(1);
  await expect(page.locator('script[type="module"][src*="formulario-mensaje/assets/js/form.js"]')).toHaveCount(1);
});

test.describe('sin JavaScript', () => {
  test.beforeEach(async ({ browser }) => {
    await context.close();
    context = await visitorContext(browser, { javaScriptEnabled: false });
    page = await context.newPage();
  });

  test('el formulario se envía como POST normal y vuelve a la página con el mensaje de éxito', async () => {
    const { link } = await api.createFormPage(
      'Sin JS e2e',
      contactDefinition({ settings: { submit_label: 'Enviar', success_message: 'Recibido. Referencia {reference}.' } }),
    );

    await page.goto(link);
    await page.getByLabel('Nombre').fill('Ana Pérez');
    await page.getByLabel('Correo').fill('ana@example.test');
    await page.getByRole('button', { name: 'Enviar' }).click();

    await expect(page).toHaveURL(/formulario_mensaje_sent=\d+/);
    await expect(page.locator('.fm-success')).toContainText(/Recibido\. Referencia [A-Z2-9]{4}-[A-Z2-9]{4}\./);
    await expect(page.locator('.fm-form')).toHaveCount(0);
    // Ningún dato del envío viaja en la URL.
    expect(decodeURIComponent(page.url())).not.toContain('Ana');
    expect(page.url()).not.toContain('example.test');

    const mail = await waitForMail(toSales);
    expect((await getMail(mail.ID)).Text).toContain('Nombre: Ana Pérez');
  });

  test('un envío no válido muestra los errores con un enlace para volver', async () => {
    const { link } = await api.createFormPage('Sin JS errores e2e');

    await page.goto(link);
    // Sin JavaScript el navegador valida por su cuenta; se le quita para llegar al servidor.
    await page.setContent((await page.content()).replace(/ required/g, '').replace('type="email"', 'type="text"'));
    await page.getByLabel('Correo').fill('no-es-un-correo');
    await page.getByRole('button', { name: 'Enviar' }).click();

    await expect(page.locator('body')).toContainText('Revisa los campos marcados.');
    await expect(page.locator('li').filter({ hasText: 'Nombre' })).toContainText('Este campo es obligatorio.');
    await expect(page.locator('li').filter({ hasText: 'Correo' })).toContainText('Escribe una dirección de correo válida.');
    await expect(page.getByRole('link', { name: /Volver/ })).toBeVisible();
    expect(await mailMatching(toSales)).toHaveLength(0);
  });
});
