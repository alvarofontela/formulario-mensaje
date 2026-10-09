import { readFileSync } from 'node:fs';
import path from 'node:path';
import { BrowserContext, Page, expect, test } from '@playwright/test';
import { env } from './support/env';
import { AdminApi, FormDefinition, contactDefinition, notifyTo, visitorContext } from './support/forms';
import { clearMail, getMail, waitForMail } from './support/mailpit';

// Lógica condicional: los campos aparecen y desaparecen mientras se rellena el formulario, con el
// mismo motor de reglas que aplica el servidor al recibir el envío.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';
const cases = JSON.parse(readFileSync(path.resolve(__dirname, '../fixtures/reglas.json'), 'utf8'));

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

const isCompany = { match: 'all' as const, rules: [{ field: 'tipo', operator: 'equals', value: 'empresa' }] };

/** Formulario en el que la empresa, su CIF y un aviso solo aparecen para las empresas. */
function conditionalDefinition(): FormDefinition {
  return contactDefinition({
    fields: [
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
      { id: 'empresa', type: 'text', condition: isCompany, settings: { label: 'Razón social', required: true } },
      {
        id: 'cif',
        type: 'text',
        condition: { match: 'all', rules: [{ field: 'empresa', operator: 'is_not_empty', value: '' }] },
        settings: { label: 'CIF' },
      },
      { id: 'aviso', type: 'paragraph', condition: isCompany, settings: { content: 'Te enviaremos factura.' } },
    ],
  });
}

test('el motor de reglas del navegador da el mismo resultado que el del servidor en los casos compartidos', async () => {
  await page.goto('/');

  const results = await page.evaluate(
    async ({ url, shared }) => {
      const engine = await import(url);

      return {
        conditions: shared.conditions.map((item: any) => engine.passes(item.condition, item.values)),
        visibility: shared.visibility.map((item: any) => {
          const ids = Object.keys(item.conditions);
          const visible = engine.resolveVisibility(
            ids.map((id) => ({ id, condition: item.conditions[id] })),
            item.values,
          );

          return Object.fromEntries(ids.map((id, index) => [id, visible[index]]));
        }),
      };
    },
    { url: `${env.baseUrl}/wp-content/plugins/${env.slug}/assets/js/rules.js`, shared: cases },
  );

  for (const [index, item] of cases.conditions.entries()) {
    expect(results.conditions[index], item.name).toBe(item.expected);
  }

  for (const [index, item] of cases.visibility.entries()) {
    expect(results.visibility[index], item.name).toEqual(item.expected);
  }
});

test('los campos con condición aparecen y desaparecen al rellenar, y solo se envían los visibles', async () => {
  const { link } = await api.createFormPage('Condicional e2e', conditionalDefinition());

  await page.goto(link);

  const company = page.locator('[data-fm-field="empresa"]');
  const vat = page.locator('[data-fm-field="cif"]');
  const note = page.getByText('Te enviaremos factura.');

  // Al cargar, lo que depende de una respuesta que aún no se ha dado está oculto.
  await expect(company).toBeHidden();
  await expect(vat).toBeHidden();
  await expect(note).toBeHidden();

  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Empresa', { exact: true }).check();

  await expect(company).toBeVisible();
  await expect(note).toBeVisible();
  // El CIF depende de que la empresa tenga nombre.
  await expect(vat).toBeHidden();

  await page.getByLabel('Razón social').fill('ACME');
  await expect(vat).toBeVisible();
  await page.getByLabel('CIF').fill('B12345678');

  // Al cambiar de idea, desaparecen el campo y lo que dependía de él, aunque conserven lo escrito.
  await page.getByLabel('Particular').check();
  await expect(company).toBeHidden();
  await expect(vat).toBeHidden();
  await expect(note).toBeHidden();
  await expect(page.getByLabel('Razón social')).toBeDisabled();

  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const mail = await waitForMail((item) => item.To.some((to) => to.Address === notifyTo));
  const detail = await getMail(mail.ID);

  expect(detail.Text).toContain('Nombre: Ana');
  expect(detail.Text).toContain('Tipo de cliente: Particular');
  expect(detail.Text).not.toContain('ACME');
  expect(detail.Text).not.toContain('B12345678');
});

test('un campo obligatorio que está a la vista se exige, y el error se muestra junto a él', async () => {
  const { link } = await api.createFormPage('Condicional obligatorio e2e', conditionalDefinition());

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Empresa', { exact: true }).check();
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page.locator('[data-fm-field="empresa"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(page.getByLabel('Razón social')).toBeFocused();
});

test('la condición de un campo se define en el constructor', async ({ browser }) => {
  const admin = await browser.newContext({ baseURL: env.baseUrl, locale: 'es-ES', storageState: path.resolve(__dirname, '../../.runtime/tmp/playwright/admin.json') });
  const builder = await admin.newPage();
  const definition = contactDefinition();

  definition.fields.splice(2, 0, {
    id: 'motivo',
    type: 'select',
    settings: {
      label: 'Motivo',
      options: [
        { label: 'Consulta', value: 'consulta' },
        { label: 'Queja', value: 'queja' },
      ],
    },
  });

  const formId = await api.createForm('Condición en el constructor e2e', definition);

  await builder.goto(`${formsScreen}&form=${formId}`);
  await builder.locator('.fm-fields__toggle', { hasText: 'Mensaje' }).click();

  await builder.getByRole('button', { name: 'Añadir regla' }).click();
  await builder.getByLabel('Campo', { exact: true }).selectOption({ label: 'Motivo' });
  await builder.getByLabel('Valor', { exact: true }).selectOption({ label: 'Queja' });

  // Un campo no puede depender de sí mismo: no se ofrece en la lista.
  await expect(builder.getByLabel('Campo', { exact: true }).locator('option')).toHaveText(['Elige un campo', 'Nombre', 'Correo', 'Motivo']);
  await expect(builder.locator('.fm-fields__item', { hasText: 'Mensaje' })).toContainText('Condicional');

  await builder.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(builder.locator('.components-notice').first()).toContainText('Formulario guardado.');
  await admin.close();

  const created = await api.createPage('Condición en el constructor e2e', `<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`);

  await page.goto(created.link);
  await expect(page.locator('[data-fm-field="mensaje"]')).toBeHidden();
  await page.getByLabel('Motivo').selectOption({ label: 'Queja' });
  await expect(page.locator('[data-fm-field="mensaje"]')).toBeVisible();
});

test('sin JavaScript se ven todos los campos y el servidor aplica las condiciones', async ({ browser }) => {
  const { link } = await api.createFormPage('Condicional sin JS e2e', conditionalDefinition());
  const noJs = await visitorContext(browser, { javaScriptEnabled: false });
  const plain = await noJs.newPage();

  await plain.goto(link);
  await expect(plain.locator('[data-fm-field="empresa"]')).toBeVisible();
  await expect(plain.getByText('Te enviaremos factura.')).toBeVisible();

  // El visitante rellena la empresa aunque diga ser particular: el servidor no la acepta como dato.
  await plain.getByLabel('Nombre').fill('Ana');
  await plain.getByLabel('Particular').check();
  await plain.getByLabel('Razón social').fill('Sobra');
  await plain.getByRole('button', { name: 'Enviar' }).click();

  await expect(plain.locator('.fm-success')).toBeVisible();

  const mail = await waitForMail((item) => item.To.some((to) => to.Address === notifyTo));
  expect((await getMail(mail.ID)).Text).not.toContain('Sobra');

  await noJs.close();
});
