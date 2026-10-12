import { Page } from '@playwright/test';
import { FormField } from '../e2e/support/forms';
import { expect, fieldError, files, options, send, test, when } from './support/lab';

// Formularios por pasos combinados con anchos, condiciones y ficheros; y las combinaciones de
// opciones del campo de ficheros.

function alta(): FormField[] {
  return [
    { id: 'p_datos', type: 'step', settings: { label: 'Tus datos' } },
    { id: 'nombre', type: 'text', width: 50, settings: { label: 'Nombre', required: true } },
    { id: 'correo', type: 'email', width: 50, settings: { label: 'Correo', required: true } },
    { id: 'tipo', type: 'radio', settings: { label: 'Tipo de cliente', required: true, options: options('Particular', 'Empresa') } },
    { id: 'p_empresa', type: 'step', settings: { label: 'Tu empresa' }, condition: when('tipo', 'equals', 'Empresa') },
    { id: 'razon', type: 'text', width: 66, settings: { label: 'Razón social', required: true } },
    { id: 'cif', type: 'text', width: 33, settings: { label: 'CIF', required: true } },
    { id: 'empleados', type: 'number', settings: { label: 'Empleados' } },
    { id: 'p_docs', type: 'step', settings: { label: 'Documentación' } },
    { id: 'docs', type: 'file', settings: { label: 'Documentos', required: true, max_files: '2', file_types: ['images', 'pdf'] } },
    { id: 'escritura', type: 'file', settings: { label: 'Escritura', required: true, file_types: ['pdf'] }, condition: when('tipo', 'equals', 'Empresa') },
    { id: 'p_fin', type: 'step', settings: { label: 'Para terminar' } },
    { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
    { id: 'acepto', type: 'consent', settings: { label: 'Privacidad', required: true, text: 'Acepto la política de privacidad' } },
  ];
}

const pasos = (page: Page) => page.locator('.fm-step');
const progreso = (page: Page) => page.locator('.fm-progress__text');
const siguiente = (page: Page) => page.getByRole('button', { name: 'Siguiente' });
const anterior = (page: Page) => page.getByRole('button', { name: 'Anterior' });

test('alta de empresa en cuatro pasos: anchos, paso condicional, ficheros y validación de cada paso', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(alta()), 'shortcode', 'Alta');
  const page = await lab.visitor({ viewport: { width: 1280, height: 900 } });

  await page.goto(link);
  await expect(pasos(page).nth(0)).toBeVisible();
  // Sin saber aún el tipo de cliente, el paso de la empresa no cuenta.
  await expect(progreso(page)).toHaveText('Paso 1 de 3: Tus datos');
  await expect(page.getByRole('button', { name: 'Enviar' })).toBeHidden();
  await expect(anterior(page)).toBeHidden();

  // Los anchos valen también dentro de un paso.
  const nombre = (await page.locator('[data-fm-field="nombre"]').boundingBox())!;
  const correo = (await page.locator('[data-fm-field="correo"]').boundingBox())!;

  expect(Math.abs(nombre.y - correo.y)).toBeLessThan(2);

  // El paso se valida en el servidor antes de avanzar.
  await siguiente(page).click();
  await expect(fieldError(page, 'nombre')).toHaveText('Este campo es obligatorio.');
  await expect(fieldError(page, 'tipo')).toHaveText('Este campo es obligatorio.');
  await expect(pasos(page).nth(0)).toBeVisible();

  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('no-es-correo');
  await page.getByLabel('Empresa', { exact: true }).check();
  await expect(progreso(page)).toHaveText('Paso 1 de 4: Tus datos');
  await siguiente(page).click();
  await expect(fieldError(page, 'correo')).toHaveText('Escribe una dirección de correo válida.');
  await page.getByLabel('Correo').fill('ana@example.test');

  // Intro en un campo avanza de paso; no envía el formulario.
  await page.getByLabel('Correo').press('Enter');
  await expect(pasos(page).nth(1)).toBeVisible();
  await expect(progreso(page)).toHaveText('Paso 2 de 4: Tu empresa');
  await expect(page.locator('.fm-step__title').nth(1)).toBeFocused();

  await page.getByLabel('Razón social').fill('ACME S.L.');
  await siguiente(page).click();
  await expect(fieldError(page, 'cif')).toHaveText('Este campo es obligatorio.');
  await page.getByLabel('CIF').fill('B12345678');
  await page.getByLabel('Empleados').fill('muchos');
  await siguiente(page).click();
  await expect(fieldError(page, 'empleados')).toHaveText('Escribe un número válido.');
  await page.getByLabel('Empleados').fill('25');
  await siguiente(page).click();

  // Paso de ficheros: los obligatorios se piden aquí, sin subir nada todavía.
  await expect(progreso(page)).toHaveText('Paso 3 de 4: Documentación');
  await siguiente(page).click();
  await expect(fieldError(page, 'docs')).toHaveText('Este campo es obligatorio.');
  await expect(fieldError(page, 'escritura')).toHaveText('Este campo es obligatorio.');

  await page.getByLabel('Documentos').setInputFiles([files.pdf('dni.pdf'), files.png('logo.png')]);
  await page.getByLabel('Escritura').setInputFiles([files.pdf('escritura.pdf')]);
  await siguiente(page).click();
  await expect(progreso(page)).toHaveText('Paso 4 de 4: Para terminar');
  await expect(siguiente(page)).toBeHidden();

  // Volver atrás conserva lo escrito y los ficheros elegidos.
  await anterior(page).click();
  await anterior(page).click();
  await expect(page.getByLabel('Razón social')).toHaveValue('ACME S.L.');
  await siguiente(page).click();
  await expect(page.getByLabel('Documentos')).toHaveJSProperty('files.length', 2);
  await siguiente(page).click();

  await page.getByLabel('Mensaje').fill('Todo en orden');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(fieldError(page, 'acepto')).toHaveText('Este campo es obligatorio.');
  await page.getByLabel('Acepto la política de privacidad').check();
  await send(page);

  const [mail] = await lab.mail();

  expect(mail.Text).toContain('Razón social: ACME S.L.');
  expect(mail.Text).toContain('Empleados: 25');
  expect(mail.Text).toMatch(/Documentos: dni\.pdf \([^)]+\)\s+logo\.png \(/);
  expect(mail.Attachments.map((attachment) => attachment.FileName).sort()).toEqual(['dni.pdf', 'escritura.pdf', 'logo.png']);

  const guardado = await lab.lastSubmission(formId);

  expect(guardado.entries.map((entry) => entry.field_id)).toEqual(['nombre', 'correo', 'tipo', 'razon', 'cif', 'empleados', 'docs', 'escritura', 'mensaje', 'acepto']);
  expect(guardado.files.map((file) => [file.field_id, file.name])).toEqual([
    ['docs', 'dni.pdf'],
    ['docs', 'logo.png'],
    ['escritura', 'escritura.pdf'],
  ]);
});

test('el mismo formulario como particular: tres pasos, sin empresa ni escritura', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(alta()), 'block', 'Alta');
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Luis');
  await page.getByLabel('Correo').fill('luis@example.test');
  await page.getByLabel('Particular').check();
  await expect(progreso(page)).toHaveText('Paso 1 de 3: Tus datos');
  await siguiente(page).click();

  // Salta directamente a la documentación; la escritura, que es solo de empresas, ni se ve.
  await expect(progreso(page)).toHaveText('Paso 2 de 3: Documentación');
  await expect(page.getByLabel('Escritura')).toBeHidden();
  await page.getByLabel('Documentos').setInputFiles([files.png('dni.png')]);
  await siguiente(page).click();
  await expect(progreso(page)).toHaveText('Paso 3 de 3: Para terminar');

  // Si cambia de idea, el paso de la empresa aparece en su sitio.
  await anterior(page).click();
  await anterior(page).click();
  await page.getByLabel('Empresa', { exact: true }).check();
  await expect(progreso(page)).toHaveText('Paso 1 de 4: Tus datos');
  await page.getByLabel('Particular').check();
  await siguiente(page).click();
  await siguiente(page).click();

  await page.getByLabel('Acepto la política de privacidad').check();
  await send(page);

  const guardado = await lab.lastSubmission(formId);

  expect(guardado.entries.map((entry) => entry.field_id)).toEqual(['nombre', 'correo', 'tipo', 'docs', 'mensaje', 'acepto']);
  expect(guardado.files.map((file) => file.name)).toEqual(['dni.png']);
  expect((await lab.mail())[0].Attachments.map((attachment) => attachment.FileName)).toEqual(['dni.png']);
});

test('si el servidor rechaza al final algo de un paso anterior, el formulario vuelve a ese paso', async ({ lab }) => {
  const { link } = await lab.formPage(lab.definition(alta()));
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Luis');
  await page.getByLabel('Correo').fill('luis@example.test');
  await page.getByLabel('Particular').check();
  await siguiente(page).click();

  // Un fichero con nombre de PDF que no lo es: el contenido solo lo puede comprobar el servidor, al enviar.
  await page.getByLabel('Documentos').setInputFiles([files.fake('dni.pdf', 'application/pdf')]);
  await siguiente(page).click();
  await page.getByLabel('Acepto la política de privacidad').check();
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(fieldError(page, 'docs')).toHaveText(/Este tipo de fichero no está permitido/, { timeout: 20_000 });
  await expect(progreso(page)).toHaveText('Paso 2 de 3: Documentación');
  await expect(page.getByLabel('Documentos')).toBeVisible();

  // Corregido, se envía.
  await page.getByLabel('Documentos').setInputFiles([files.pdf('dni.pdf')]);
  await siguiente(page).click();
  await send(page);
  await lab.mail();
});

test('sin JavaScript los pasos son secciones seguidas y el envío, con sus ficheros, es uno solo', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(alta()));
  const page = await lab.visitor({ javaScriptEnabled: false });

  await page.goto(link);
  await expect(pasos(page)).toHaveCount(4);

  for (const titulo of ['Tus datos', 'Tu empresa', 'Documentación', 'Para terminar']) {
    await expect(page.getByRole('heading', { name: titulo })).toBeVisible();
  }

  await expect(siguiente(page)).toBeHidden();
  await expect(page.locator('.fm-progress')).toBeHidden();

  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Empresa', { exact: true }).check();
  await page.getByLabel('Razón social').fill('ACME');
  await page.getByLabel('CIF').fill('B1');
  await page.getByLabel('Documentos').setInputFiles([files.pdf('dni.pdf')]);
  await page.getByLabel('Escritura').setInputFiles([files.pdf('escritura.pdf')]);
  await page.getByLabel('Acepto la política de privacidad').check();
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page).toHaveURL(/formulario_mensaje_sent=\d+/);

  const guardado = await lab.lastSubmission(formId);

  expect(guardado.files.map((file) => file.name).sort()).toEqual(['dni.pdf', 'escritura.pdf']);
  expect((await lab.mail())[0].Attachments).toHaveLength(2);
});

// --- Combinaciones del campo de ficheros --------------------------------------------------------

const conFichero = (settings: Record<string, unknown>): FormField[] => [
  { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
  { id: 'adjunto', type: 'file', settings: { label: 'Adjunto', max_files: '3', ...settings } },
];

const grupos: { nombre: string; tipos: string[]; aceptados: (keyof typeof tipos)[]; rechazados: (keyof typeof tipos)[] }[] = [
  { nombre: 'solo imágenes', tipos: ['images'], aceptados: ['png'], rechazados: ['pdf', 'txt', 'docx'] },
  { nombre: 'solo PDF', tipos: ['pdf'], aceptados: ['pdf'], rechazados: ['png', 'csv'] },
  { nombre: 'solo documentos de oficina', tipos: ['documents'], aceptados: ['txt', 'csv', 'docx'], rechazados: ['png', 'pdf'] },
  { nombre: 'imágenes y documentos', tipos: ['images', 'documents'], aceptados: ['png', 'txt'], rechazados: ['pdf'] },
];

const tipos = { pdf: files.pdf, png: files.png, txt: files.txt, csv: files.csv, docx: files.docx };

for (const grupo of grupos) {
  test(`tipos de fichero permitidos: ${grupo.nombre}`, async ({ lab }) => {
    const formId = await lab.form(lab.definition(conFichero({ file_types: grupo.tipos })));

    // Cada tipo no permitido se rechaza, uno a uno.
    for (const tipo of grupo.rechazados) {
      const respuesta = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { adjunto: [tipos[tipo]()] });

      expect(respuesta.status, tipo).toBe(422);
      expect(respuesta.body.data.errors.adjunto, tipo).toContain('Este tipo de fichero no está permitido');
    }

    // Uno permitido junto a uno que no lo es: se rechaza el envío entero.
    const mezcla = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { adjunto: [tipos[grupo.aceptados[0]](), tipos[grupo.rechazados[0]]()] });

    expect(mezcla.status).toBe(422);
    expect(await lab.submissions(formId)).toHaveLength(0);

    // Todos los permitidos, juntos.
    const buenos = grupo.aceptados.map((tipo) => tipos[tipo]());
    const aceptado = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { adjunto: buenos });

    expect(aceptado.status, JSON.stringify(aceptado.body)).toBe(200);
    expect((await lab.lastSubmission(formId)).files.map((file) => file.name)).toEqual(buenos.map((file) => file.name));
    expect((await lab.mail())[0].Attachments.map((attachment) => attachment.FileName).sort()).toEqual(buenos.map((file) => file.name).sort());
  });
}

const destinos: { adjuntar: boolean; conservar: boolean; enlace: boolean }[] = [
  { adjuntar: true, conservar: true, enlace: false },
  { adjuntar: true, conservar: false, enlace: false },
  { adjuntar: false, conservar: true, enlace: true },
  { adjuntar: false, conservar: false, enlace: false },
  { adjuntar: true, conservar: true, enlace: true },
];

for (const destino of destinos) {
  const nombre = `adjuntar ${destino.adjuntar ? 'sí' : 'no'}, conservar ${destino.conservar ? 'sí' : 'no'}, enlace ${destino.enlace ? 'sí' : 'no'}`;

  test(`destino de los ficheros: ${nombre}`, async ({ lab }) => {
    const formId = await lab.form(
      lab.definition(conFichero({ attach: destino.adjuntar, keep: destino.conservar, signed_link: destino.enlace, link_days: '1' })),
    );
    const respuesta = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { adjunto: [files.pdf('uno.pdf'), files.png('dos.png')] });

    expect(respuesta.status, JSON.stringify(respuesta.body)).toBe(200);

    const [mail] = await lab.mail();
    const guardado = await lab.lastSubmission(formId);

    // El correo dice siempre qué se recibió; los ficheros van adjuntos solo si el campo lo pide.
    expect(mail.Text).toMatch(/Adjunto: uno\.pdf \([^)]+\)/);
    expect(mail.Attachments.map((attachment) => attachment.FileName).sort()).toEqual(destino.adjuntar ? ['dos.png', 'uno.pdf'] : []);

    // Con el envío quedan solo si el campo los conserva.
    expect(guardado.files.map((file) => file.name)).toEqual(destino.conservar ? ['uno.pdf', 'dos.png'] : []);
    expect(guardado.entries.find((entry) => entry.field_id === 'adjunto')!.value).toContain('uno.pdf');

    // El enlace de descarga va en el correo y funciona sin sesión; en el envío guardado no queda.
    const enlaces = mail.Text.match(/https?:\/\/\S+admin-post\.php\?\S+/g) ?? [];

    expect(enlaces).toHaveLength(destino.enlace ? 2 : 0);
    expect(JSON.stringify(guardado.entries)).not.toContain('sig=');

    if (destino.enlace) {
      const descarga = await fetch(enlaces[0]);

      expect(descarga.status).toBe(200);
      expect(Buffer.from(await descarga.arrayBuffer()).equals(files.pdfBytes)).toBe(true);
      expect(descarga.headers.get('content-disposition')).toContain('attachment; filename="uno.pdf"');
    }
  });
}

test('dos campos de ficheros, nombres repetidos y límites de número y tamaño', async ({ lab }) => {
  const formId = await lab.form(
    lab.definition([
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
      { id: 'fotos', type: 'file', settings: { label: 'Fotos', max_files: '2', max_size: '1', file_types: ['images'] } },
      { id: 'papeles', type: 'file', settings: { label: 'Papeles', max_files: '2', max_size: '1', file_types: ['pdf'] } },
    ]),
  );

  // Demasiados ficheros en un campo.
  const demasiados = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { fotos: [files.png('a.png'), files.png('b.png'), files.png('c.png')] });

  expect(demasiados.status).toBe(422);
  expect(demasiados.body.data.errors).toEqual({ fotos: 'Puedes subir 2 ficheros como máximo.' });

  // Demasiado peso entre los dos ficheros de un campo; el otro campo no tiene culpa.
  const pesados = await lab.restSubmitFiles(
    formId,
    { nombre: 'Ana' },
    { fotos: [files.png()], papeles: [files.pdf('a.pdf', 600_000), files.pdf('b.pdf', 600_000)] },
  );

  expect(pesados.status).toBe(422);
  expect(pesados.body.data.errors).toEqual({ papeles: 'Los ficheros pesan demasiado. El máximo es de 1 MB en total.' });

  // Un fichero del tipo del otro campo no vale en este.
  const cruzado = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { fotos: [files.pdf()], papeles: [files.png()] });

  expect(Object.keys(cruzado.body.data.errors).sort()).toEqual(['fotos', 'papeles']);

  // Dos ficheros con el mismo nombre en campos distintos: llegan los dos, sin pisarse.
  const repetidos = await lab.restSubmitFiles(
    formId,
    { nombre: 'Ana' },
    { fotos: [files.png('documento.png'), files.png('documento.png')], papeles: [files.pdf('Mi Informe (final).pdf')] },
  );

  expect(repetidos.status, JSON.stringify(repetidos.body)).toBe(200);

  const [mail] = await lab.mail();
  const guardado = await lab.lastSubmission(formId);

  expect(mail.Attachments).toHaveLength(3);
  expect(new Set(mail.Attachments.map((attachment) => attachment.FileName)).size).toBe(3);
  expect(guardado.files.map((file) => file.name)).toEqual(['documento.png', 'documento.png', 'Mi-Informe-final.pdf']);
  // Cada uno tiene su propio enlace de descarga.
  expect(new Set(guardado.files.map((file) => file.url)).size).toBe(3);
});

test('los ficheros de un formulario que no guarda sus envíos se adjuntan y no dejan rastro', async ({ lab }) => {
  const formId = await lab.form(lab.definition(conFichero({ keep: true, signed_link: true }), { settings: { store_enabled: false } }));
  const respuesta = await lab.restSubmitFiles(formId, { nombre: 'Ana' }, { adjunto: [files.pdf()] });

  expect(respuesta.status).toBe(200);

  const [mail] = await lab.mail();

  expect(mail.Attachments.map((attachment) => attachment.FileName)).toEqual(['informe.pdf']);
  // No se enlaza un fichero que no se va a conservar.
  expect(mail.Text).not.toContain('admin-post.php');
  expect(await lab.submissions(formId)).toHaveLength(0);
});
