import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { BrowserContext, Page, expect, request as playwrightRequest, test } from '@playwright/test';
import { env } from './support/env';
import { AdminApi, FormDefinition, contactDefinition, notifyTo, visitorContext } from './support/forms';
import { clearMail, getMail, waitForMail } from './support/mailpit';

// Subida de ficheros con subidas de verdad: tipos cerrados, adjuntos en el correo, almacén que no
// se sirve por URL, descarga con sesión y enlace firmado.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';
const submissionsScreen = '/wp-admin/admin.php?page=formulario-mensaje-submissions';

const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);
const pdfFile = (name = 'informe.pdf', buffer = pdf) => ({ name, mimeType: 'application/pdf', buffer });
const pngFile = (name = 'foto.png') => ({ name, mimeType: 'image/png', buffer: png });

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

/** Formulario de contacto con un campo de ficheros. */
function uploadDefinition(settings: Record<string, unknown> = {}, overrides: Partial<FormDefinition> = {}): FormDefinition {
  return contactDefinition({
    fields: [
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
      { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
      { id: 'adjunto', type: 'file', settings: { label: 'Documentación', max_files: '2', ...settings } },
    ],
    ...overrides,
  });
}

/**
 * Petición sin sesión: lo que vería cualquiera desde fuera. El estado vacío es necesario: dentro de
 * un test, un contexto nuevo hereda la sesión de administrador de la configuración.
 */
async function anonymousGet(url: string) {
  const anonymous = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: { cookies: [], origins: [] } });
  const response = await anonymous.get(url, { maxRedirects: 0 });
  const result = { status: response.status(), headers: response.headers(), body: await response.body() };

  await anonymous.dispose();

  return result;
}

test('los ficheros llegan adjuntos al correo, se guardan en privado y se descargan desde la administración', async ({
  page: adminPage,
}) => {
  const { formId, link } = await api.createFormPage('Ficheros e2e', uploadDefinition({ required: true }));

  await page.goto(link);

  const input = page.getByLabel('Documentación');
  await expect(input).toHaveAttribute('accept', '.jpg,.jpeg,.jpe,.png,.gif,.webp,.pdf');
  await expect(page.locator('.fm-file-hint')).toHaveText('Permitidos: JPG, JPEG, JPE, PNG, GIF, WEBP, PDF. Hasta 2 ficheros, 5 MB en total.');

  await page.getByLabel('Nombre').fill('Ana Pérez');
  await page.getByLabel('Correo').fill('ana@example.test');

  // Obligatorio: sin fichero no se acepta.
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('[data-fm-field="adjunto"] .fm-error')).toHaveText('Este campo es obligatorio.');

  await input.setInputFiles([pdfFile('Informe anual.pdf'), pngFile()]);
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  // El correo lleva los dos ficheros, con su nombre, y el texto dice qué se ha recibido.
  const mail = await getMail((await waitForMail(toSales)).ID);
  expect(mail.Attachments.map((attachment) => attachment.FileName).sort()).toEqual(['Informe-anual.pdf', 'foto.png']);
  expect(mail.Text).toContain('Informe-anual.pdf (');
  expect(mail.Text).not.toContain('admin-post.php');

  // En la administración, el detalle del envío los lista y se descargan con la sesión.
  await adminPage.goto(`${submissionsScreen}&form=${formId}`);
  await adminPage.locator('tbody tr').first().locator('.fm-admin__row-title').click();

  const files = adminPage.locator('.fm-detail__files');
  await expect(files.getByRole('heading', { name: 'Ficheros' })).toBeVisible();
  await expect(files.getByRole('link')).toHaveText(['Informe-anual.pdf', 'foto.png']);

  const download = adminPage.waitForEvent('download');
  await files.getByRole('link', { name: 'Informe-anual.pdf' }).click();
  const saved = await download;

  expect(saved.suggestedFilename()).toBe('Informe-anual.pdf');
  expect(readFileSync(await saved.path()).equals(pdf)).toBe(true);

  // Ese mismo enlace no le sirve a quien no tiene sesión.
  const adminUrl = (await files.getByRole('link', { name: 'Informe-anual.pdf' }).getAttribute('href')) as string;
  const token = new URL(adminUrl).searchParams.get('file') as string;

  expect(token).toMatch(/^[a-f0-9]{32}$/);
  expect((await anonymousGet(adminUrl)).status).toBe(403);

  // Y el almacén no se sirve por URL. El identificador del enlace, además, no es el nombre del fichero en disco.
  const store = '/wp-content/uploads/formulario-mensaje-private';
  expect((await anonymousGet(`${store}/`)).status).toBe(403);
  expect((await anonymousGet(`${store}/${token.slice(0, 2)}/${token}.dat`)).status).toBe(403);
  expect(existsSync(path.join(env.paths.www, store, token.slice(0, 2), `${token}.dat`))).toBe(false);
  expect(readdirSync(path.join(env.paths.www, store), { recursive: true }).some((name) => String(name).endsWith('.dat'))).toBe(true);

  // Al borrar el envío, el fichero deja de existir.
  await adminPage.getByRole('button', { name: 'Borrar' }).click();
  await adminPage.getByRole('dialog').getByRole('button', { name: /^Borrar/ }).click();
  await expect(adminPage).not.toHaveURL(/submission=/);
  expect((await adminPage.request.get(adminUrl)).status()).toBe(403);
});

test('el navegador avisa del exceso antes de subir nada y el servidor rechaza un fichero disfrazado', async () => {
  const { link } = await api.createFormPage('Ficheros límites e2e', uploadDefinition({ max_size: '1' }));
  const sent: string[] = [];

  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url().includes('/submissions')) {
      sent.push(request.url());
    }
  });

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');

  const input = page.getByLabel('Documentación');
  const error = page.locator('[data-fm-field="adjunto"] .fm-error');

  await input.setInputFiles([pdfFile('a.pdf'), pdfFile('b.pdf'), pdfFile('c.pdf')]);
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(error).toHaveText('Puedes subir 2 ficheros como máximo.');

  await input.setInputFiles([pdfFile('grande.pdf', Buffer.concat([pdf, Buffer.alloc(1_200_000, 32)]))]);
  await expect(error).toBeHidden();
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(error).toHaveText('Los ficheros pesan demasiado. El máximo es de 1 MB en total.');
  expect(sent).toHaveLength(0);

  // Con nombre y tipo declarado de PDF, pero sin serlo: lo que cuenta es el contenido.
  await input.setInputFiles([pdfFile('informe.pdf', Buffer.from('Esto no es un PDF, por mucho que lo diga su nombre.'))]);
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(error).toHaveText('Este tipo de fichero no está permitido. Permitidos: JPG, JPEG, JPE, PNG, GIF, WEBP, PDF.');
  await expect(page.locator('.fm-success')).toBeHidden();
  expect(sent).toHaveLength(1);

  // Corregido, el envío sale.
  await input.setInputFiles([pdfFile()]);
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();
});

test('el enlace firmado de la notificación descarga el fichero sin sesión y no admite cambios', async () => {
  const { link } = await api.createFormPage('Ficheros enlace e2e', uploadDefinition({ attach: false, signed_link: true, link_days: '1' }));

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Documentación').setInputFiles([pdfFile('Presupuesto 2026.pdf')]);
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const mail = await getMail((await waitForMail(toSales)).ID);
  expect(mail.Attachments).toHaveLength(0);

  const signed = (mail.Text.match(/https?:\/\/\S+admin-post\.php\?\S+/) || [''])[0];
  expect(signed).toContain('sig=');

  const response = await anonymousGet(signed);

  expect(response.status).toBe(200);
  expect(response.body.equals(pdf)).toBe(true);
  // Siempre como descarga y sin que el navegador adivine el tipo.
  expect(response.headers['content-disposition']).toContain('attachment; filename="Presupuesto-2026.pdf"');
  expect(response.headers['content-type']).toBe('application/octet-stream');
  expect(response.headers['x-content-type-options']).toBe('nosniff');

  // Ni otra caducidad ni otro fichero valen con esa firma.
  const later = new URL(signed);
  later.searchParams.set('expires', String(Number(later.searchParams.get('expires')) + 86_400));
  expect((await anonymousGet(later.toString())).status).toBe(403);

  const other = new URL(signed);
  other.searchParams.set('file', 'a'.repeat(32));
  expect((await anonymousGet(other.toString())).status).toBe(403);

  const unsigned = new URL(signed);
  unsigned.searchParams.delete('sig');
  expect((await anonymousGet(unsigned.toString())).status).toBe(403);
});

test('en un formulario por pasos el fichero obligatorio se pide al avanzar y viaja con el envío', async () => {
  const { link } = await api.createFormPage(
    'Ficheros pasos e2e',
    contactDefinition({
      fields: [
        { id: 'p_docs', type: 'step', settings: { label: 'Documentos' } },
        { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
        { id: 'adjunto', type: 'file', settings: { label: 'Documentación', required: true } },
        { id: 'p_contacto', type: 'step', settings: { label: 'Contacto' } },
        { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
      ],
    }),
  );

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByRole('button', { name: 'Siguiente' }).click();

  await expect(page.locator('[data-fm-field="adjunto"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(page.locator('.fm-step').nth(1)).toBeHidden();

  await page.getByLabel('Documentación').setInputFiles([pdfFile()]);
  await page.getByRole('button', { name: 'Siguiente' }).click();
  await expect(page.locator('.fm-step').nth(1)).toBeVisible();

  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();

  const mail = await getMail((await waitForMail(toSales)).ID);
  expect(mail.Attachments.map((attachment) => attachment.FileName)).toEqual(['informe.pdf']);
});

test('el campo de subida se configura en el constructor', async ({ page: adminPage }) => {
  const formId = await api.createForm('Ficheros constructor e2e');

  await adminPage.goto(`${formsScreen}&form=${formId}`);
  await adminPage.getByRole('button', { name: 'Añadir campo' }).click();
  await adminPage.getByRole('menuitem', { name: 'Subida de ficheros' }).click();

  await adminPage.getByLabel('Etiqueta', { exact: true }).fill('Tu currículum');

  // De partida, imágenes y PDF. Se dejan solo los documentos.
  const types = adminPage.getByRole('group', { name: 'Tipos de fichero permitidos' });
  await expect(types.getByLabel(/^Imágenes/)).toBeChecked();
  await types.getByLabel(/^Imágenes/).uncheck();
  await types.getByLabel(/^Documentos de oficina/).check();
  await adminPage.getByLabel('Número máximo de ficheros').selectOption('3');

  // El enlace de descarga solo tiene sentido si el fichero se conserva.
  const linkToggle = adminPage.getByLabel('Incluir un enlace de descarga en las notificaciones');
  await linkToggle.check();
  await expect(adminPage.getByLabel('El enlace caduca tras')).toBeVisible();
  await adminPage.getByLabel('Conservar los ficheros con el envío guardado').uncheck();
  await expect(linkToggle).toBeHidden();
  await expect(adminPage.getByLabel('El enlace caduca tras')).toBeHidden();
  await adminPage.getByLabel('Conservar los ficheros con el envío guardado').check();

  const preview = adminPage.frameLocator('.fm-preview__frame');
  await expect(preview.getByLabel('Tu currículum')).toHaveAttribute('accept', /\.pdf,\.doc,\.docx/);
  await expect(preview.getByLabel('Tu currículum')).toHaveAttribute('multiple', '');

  await adminPage.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(adminPage.locator('.components-notice')).toContainText('Formulario guardado.');

  await adminPage.reload();
  await adminPage.locator('.fm-fields__item', { hasText: 'Tu currículum' }).locator('.fm-fields__name').click();
  await expect(adminPage.getByRole('group', { name: 'Tipos de fichero permitidos' }).getByLabel(/^Imágenes/)).not.toBeChecked();
  await expect(adminPage.getByRole('group', { name: 'Tipos de fichero permitidos' }).getByLabel(/^Documentos de oficina/)).toBeChecked();
});

test.describe('sin JavaScript', () => {
  test.beforeEach(async ({ browser }) => {
    await context.close();
    context = await visitorContext(browser, { javaScriptEnabled: false });
    page = await context.newPage();
  });

  test('el formulario envía los ficheros como POST normal', async () => {
    const { link } = await api.createFormPage('Ficheros sin JS e2e', uploadDefinition({ required: true }));

    await page.goto(link);
    await expect(page.locator('form.fm-form')).toHaveAttribute('enctype', 'multipart/form-data');

    await page.getByLabel('Nombre').fill('Ana');
    await page.getByLabel('Correo').fill('ana@example.test');
    await page.getByLabel('Documentación').setInputFiles([pdfFile()]);
    await page.getByRole('button', { name: 'Enviar' }).click();

    await expect(page).toHaveURL(/formulario_mensaje_sent=\d+/);
    await expect(page.locator('.fm-success')).toBeVisible();

    const mail = await getMail((await waitForMail(toSales)).ID);
    expect(mail.Attachments.map((attachment) => attachment.FileName)).toEqual(['informe.pdf']);
  });
});
