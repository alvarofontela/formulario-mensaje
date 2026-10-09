import { BrowserContext, Page, expect, test } from '@playwright/test';
import { AdminApi, FormDefinition, contactDefinition, notifyTo, visitorContext } from './support/forms';
import { clearMail, getMail, mailMatching, waitForMail } from './support/mailpit';

// Formularios por pasos: de uno en uno, con barra de progreso, validación de cada paso en el
// servidor y pasos que solo aparecen si se cumple su condición.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';

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

/** Formulario de tres pasos; el segundo solo se muestra a las empresas. */
function stepsDefinition(): FormDefinition {
  return contactDefinition({
    fields: [
      { id: 'p_datos', type: 'step', settings: { label: 'Tus datos' } },
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
      {
        id: 'tipo',
        type: 'radio',
        settings: {
          label: 'Tipo de cliente',
          required: true,
          options: [
            { label: 'Particular', value: 'particular' },
            { label: 'Empresa', value: 'empresa' },
          ],
        },
      },
      {
        id: 'p_empresa',
        type: 'step',
        settings: { label: 'Tu empresa' },
        condition: { match: 'all', rules: [{ field: 'tipo', operator: 'equals', value: 'empresa' }] },
      },
      { id: 'empresa', type: 'text', settings: { label: 'Razón social', required: true } },
      { id: 'p_mensaje', type: 'step', settings: { label: 'Tu mensaje' } },
      { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje', required: true } },
    ],
  });
}

test('los pasos se recorren de uno en uno, cada uno validado en el servidor antes de avanzar', async () => {
  const { link } = await api.createFormPage('Pasos e2e', stepsDefinition());

  await page.goto(link);

  const steps = page.locator('.fm-step');
  const progress = page.locator('.fm-progress__text');
  const bar = page.locator('.fm-progress__bar');
  const next = page.getByRole('button', { name: 'Siguiente' });
  const previous = page.getByRole('button', { name: 'Anterior' });
  const send = page.getByRole('button', { name: 'Enviar' });

  // Solo se ve el primer paso. Sin saber aún si es empresa, el paso de la empresa no cuenta.
  await expect(steps.nth(0)).toBeVisible();
  await expect(steps.nth(1)).toBeHidden();
  await expect(steps.nth(2)).toBeHidden();
  await expect(progress).toHaveText('Paso 1 de 2: Tus datos');
  await expect(bar).toHaveAttribute('aria-valuenow', '1');
  await expect(bar).toHaveAttribute('aria-valuemax', '2');
  await expect(previous).toBeHidden();
  await expect(send).toBeHidden();

  // No se avanza con el paso sin rellenar: los errores los da el servidor.
  await next.click();
  await expect(page.locator('[data-fm-field="nombre"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(steps.nth(0)).toBeVisible();
  await expect(page.getByLabel('Nombre')).toBeFocused();

  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Empresa', { exact: true }).check();

  // Al decir que es una empresa aparece un paso más.
  await expect(progress).toHaveText('Paso 1 de 3: Tus datos');

  await next.click();
  await expect(steps.nth(1)).toBeVisible();
  await expect(steps.nth(0)).toBeHidden();
  await expect(progress).toHaveText('Paso 2 de 3: Tu empresa');
  await expect(page.locator('.fm-step__title', { hasText: 'Tu empresa' })).toBeFocused();
  await expect(previous).toBeVisible();

  // Intro en un paso intermedio avanza (si es válido), no envía el formulario.
  await page.getByLabel('Razón social').fill('ACME');
  await page.getByLabel('Razón social').press('Enter');
  await expect(steps.nth(2)).toBeVisible();
  await expect(progress).toHaveText('Paso 3 de 3: Tu mensaje');
  await expect(next).toBeHidden();
  await expect(send).toBeVisible();
  expect(await mailMatching(toSales, 300)).toHaveLength(0);

  // Volver atrás conserva lo escrito.
  await previous.click();
  await expect(page.getByLabel('Razón social')).toHaveValue('ACME');
  await next.click();

  await page.getByLabel('Mensaje').fill('Quiero un presupuesto.');
  await send.click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const detail = await getMail((await waitForMail(toSales)).ID);
  expect(detail.Text).toContain('Nombre: Ana');
  expect(detail.Text).toContain('Razón social: ACME');
  expect(detail.Text).toContain('Mensaje: Quiero un presupuesto.');
});

test('un paso cuya condición no se cumple se salta y sus campos no se envían', async () => {
  const { link } = await api.createFormPage('Pasos condicionales e2e', stepsDefinition());

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Luis');
  await page.getByLabel('Particular').check();
  await page.getByRole('button', { name: 'Siguiente' }).click();

  // Del primer paso se pasa al del mensaje, que es el último.
  await expect(page.locator('.fm-progress__text')).toHaveText('Paso 2 de 2: Tu mensaje');
  await expect(page.locator('.fm-step').nth(1)).toBeHidden();
  await expect(page.locator('.fm-step').nth(2)).toBeVisible();

  await page.getByLabel('Mensaje').fill('Hola');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const detail = await getMail((await waitForMail(toSales)).ID);
  expect(detail.Text).not.toContain('Razón social');
});

test('sin JavaScript los pasos se ven seguidos, como secciones, y el formulario se envía entero', async ({ browser }) => {
  const { link } = await api.createFormPage('Pasos sin JS e2e', stepsDefinition());
  const noJs = await visitorContext(browser, { javaScriptEnabled: false });
  const plain = await noJs.newPage();

  await plain.goto(link);

  await expect(plain.locator('.fm-step')).toHaveCount(3);
  for (const title of ['Tus datos', 'Tu empresa', 'Tu mensaje']) {
    await expect(plain.getByRole('heading', { name: title })).toBeVisible();
  }
  await expect(plain.getByRole('button', { name: 'Siguiente' })).toBeHidden();
  await expect(plain.locator('.fm-progress')).toBeHidden();

  await plain.getByLabel('Nombre').fill('Ana');
  await plain.getByLabel('Particular').check();
  // La razón social es obligatoria, pero su paso no le corresponde a un particular.
  await plain.locator('[data-fm-field="empresa"] input').evaluate((input: HTMLInputElement) => input.removeAttribute('required'));
  await plain.getByLabel('Mensaje').fill('Hola');
  await plain.getByRole('button', { name: 'Enviar' }).click();

  await expect(plain.locator('.fm-success')).toBeVisible();
  await waitForMail(toSales);
  await noJs.close();
});

test('los pasos se añaden en el constructor como un elemento más de la lista', async ({ page: builder }) => {
  const formId = await api.createForm('Pasos en el constructor e2e');

  await builder.goto(`${formsScreen}&form=${formId}`);

  await builder.getByRole('button', { name: 'Añadir campo' }).click();
  await builder.getByRole('menuitem', { name: 'Paso' }).click();
  await builder.getByLabel('Título del paso').fill('Tu mensaje');

  // Un paso no tiene ancho: no ocupa sitio en la rejilla.
  await expect(builder.getByLabel('Ancho', { exact: true })).toHaveCount(0);

  // Se coloca delante del mensaje: nombre y correo quedan en un primer paso sin título.
  await builder.getByRole('button', { name: 'Subir «Tu mensaje»' }).click();
  await expect(builder.locator('.fm-fields__name')).toHaveText(['Nombre', 'Correo', 'Tu mensaje', 'Mensaje']);
  await expect(builder.locator('.fm-fields__item.is-section')).toHaveCount(1);

  const preview = builder.frameLocator('.fm-preview__frame');
  await expect(preview.locator('.fm-step')).toHaveCount(2);
  await expect(preview.getByRole('heading', { name: 'Tu mensaje' })).toBeVisible();

  await builder.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(builder.locator('.components-notice').first()).toContainText('Formulario guardado.');
});
