import { Page } from '@playwright/test';
import { FormField } from '../e2e/support/forms';
import { expect, field, options, send, test, when } from './support/lab';

// Lógica condicional con cada operador sobre cada tipo de campo: lo que el navegador muestra u
// oculta es lo mismo que el servidor exige o descarta.

type Condicion = NonNullable<FormField['condition']>;

/** Campo de texto obligatorio que solo se muestra si se cumple la condición. */
const dependiente = (id: string, condition: Condicion): FormField => ({
  id,
  type: 'text',
  settings: { label: `Dependiente ${id}`, required: true },
  condition,
});

function operadores(): FormField[] {
  return [
    { id: 'texto', type: 'text', settings: { label: 'Texto' } },
    { id: 'numero', type: 'number', settings: { label: 'Número' } },
    { id: 'lista', type: 'select', settings: { label: 'Lista', options: options('Ventas', 'Soporte') } },
    { id: 'radios', type: 'radio', settings: { label: 'Radios', options: options('Particular', 'Empresa') } },
    { id: 'casillas', type: 'checkboxes', settings: { label: 'Casillas', options: options('A', 'B', 'C') } },
    { id: 'acepto', type: 'consent', settings: { label: 'Acepto', required: false, text: 'Acepto las condiciones' } },
    { id: 'fecha', type: 'date', settings: { label: 'Fecha' } },

    dependiente('d_contiene', when('texto', 'contains', 'urgente')),
    dependiente('d_no_contiene', when('texto', 'not_contains', 'spam')),
    dependiente('d_vacio', when('texto', 'is_empty')),
    dependiente('d_no_vacio', when('texto', 'is_not_empty')),
    dependiente('d_mayor', when('numero', 'greater_than', '10')),
    dependiente('d_menor', when('numero', 'less_than', '10')),
    dependiente('d_es', when('lista', 'equals', 'ventas')),
    dependiente('d_no_es', when('lista', 'not_equals', 'Ventas')),
    dependiente('d_radio', when('radios', 'equals', 'Empresa')),
    dependiente('d_casilla_b', when('casillas', 'equals', 'B')),
    dependiente('d_sin_casillas', when('casillas', 'is_empty')),
    dependiente('d_acepto', when('acepto', 'is_not_empty')),
    dependiente('d_fecha', when('fecha', 'is_not_empty')),
    dependiente('d_todas', {
      match: 'all',
      rules: [
        { field: 'lista', operator: 'equals', value: 'Ventas' },
        { field: 'numero', operator: 'greater_than', value: '10' },
      ],
    }),
    dependiente('d_alguna', {
      match: 'any',
      rules: [
        { field: 'radios', operator: 'equals', value: 'Empresa' },
        { field: 'casillas', operator: 'equals', value: 'C' },
      ],
    }),
    { id: 'aviso', type: 'paragraph', settings: { content: 'Para importes altos te llamaremos.', css_class: 'aviso-importe' }, condition: when('numero', 'greater_than', '100') },
  ];
}

interface Escenario {
  nombre: string;
  valores: { texto?: string; numero?: string; lista?: string; radios?: string; casillas?: string[]; acepto?: boolean; fecha?: string };
  visibles: string[];
}

const escenarios: Escenario[] = [
  {
    nombre: 'sin rellenar nada',
    valores: {},
    visibles: ['d_no_contiene', 'd_vacio', 'd_no_es', 'd_sin_casillas'],
  },
  {
    nombre: 'todo relleno',
    valores: { texto: 'Es URGENTE de verdad', numero: '10,5', lista: 'Ventas', radios: 'Empresa', casillas: ['B'], acepto: true, fecha: '2026-03-15' },
    visibles: ['d_contiene', 'd_no_contiene', 'd_no_vacio', 'd_mayor', 'd_es', 'd_radio', 'd_casilla_b', 'd_acepto', 'd_fecha', 'd_todas', 'd_alguna'],
  },
  {
    nombre: 'los contrarios',
    valores: { texto: 'esto es SPAM', numero: '-3', lista: 'Soporte', radios: 'Particular', casillas: ['A', 'C'] },
    visibles: ['d_no_vacio', 'd_menor', 'd_no_es', 'd_alguna'],
  },
  {
    nombre: 'en el límite',
    valores: { texto: '   ', numero: '10', lista: 'Ventas' },
    visibles: ['d_no_contiene', 'd_vacio', 'd_es', 'd_sin_casillas'],
  },
];

const dependientes = operadores()
  .map((campo) => campo.id)
  .filter((id) => id.startsWith('d_'));

/** Deja el formulario con los valores de un escenario, partiendo de cualquier estado anterior. */
async function aplicar(page: Page, valores: Escenario['valores']): Promise<void> {
  await page.getByLabel('Texto', { exact: true }).fill(valores.texto ?? '');
  await page.getByLabel('Número', { exact: true }).fill(valores.numero ?? '');
  await page.getByLabel('Lista', { exact: true }).selectOption(valores.lista ?? '');
  await page.getByLabel('Fecha', { exact: true }).fill(valores.fecha ?? '');

  if (valores.radios) {
    await page.getByRole('group', { name: 'Radios' }).getByLabel(valores.radios).check();
  }

  for (const opcion of ['A', 'B', 'C']) {
    await page.getByRole('group', { name: 'Casillas' }).getByLabel(opcion, { exact: true }).setChecked((valores.casillas ?? []).includes(opcion));
  }

  await page.getByLabel('Acepto las condiciones').setChecked(valores.acepto ?? false);
}

test('cada operador muestra y oculta su campo en el navegador, sobre cada tipo de campo', async ({ lab }) => {
  const { link } = await lab.formPage(lab.definition(operadores()));
  const page = await lab.visitor();

  await page.goto(link);

  for (const escenario of escenarios) {
    await test.step(escenario.nombre, async () => {
      await aplicar(page, escenario.valores);

      for (const id of dependientes) {
        const visible = escenario.visibles.includes(id);

        await expect(field(page, id), `${id} en «${escenario.nombre}»`).toBeVisible({ visible });
        // Lo oculto no viaja con el envío ni lo valida nadie: sus controles quedan desactivados.
        await expect(field(page, id).locator('input'), `${id} activo`).toBeEnabled({ enabled: visible });
      }
    });
  }

  // Un elemento de presentación también puede llevar condición.
  await expect(page.locator('.aviso-importe')).toBeHidden();
  await page.getByLabel('Número', { exact: true }).fill('100,01');
  await expect(page.locator('.aviso-importe')).toBeVisible();
  await page.getByLabel('Número', { exact: true }).fill('100');
  await expect(page.locator('.aviso-importe')).toBeHidden();
});

test('el servidor exige exactamente los campos que el navegador muestra', async ({ lab }) => {
  const formId = await lab.form(lab.definition(operadores(), { actions: [] }));

  for (const escenario of escenarios) {
    const { valores } = escenario;
    const respuesta = await lab.restSubmit(
      formId,
      {
        texto: valores.texto ?? '',
        numero: valores.numero ?? '',
        lista: valores.lista ?? '',
        radios: valores.radios ?? '',
        casillas: valores.casillas ?? [],
        acepto: valores.acepto ? '1' : '',
        fecha: valores.fecha ?? '',
      },
      { waitMs: 3_200 },
    );

    // Todos los dependientes son obligatorios y van vacíos: fallan los que se muestran, y solo esos.
    expect(respuesta.status, escenario.nombre).toBe(422);
    expect(Object.keys(respuesta.body.data.errors).sort(), escenario.nombre).toEqual([...escenario.visibles].sort());
  }
});

test('una cadena de condiciones se resuelve en orden: lo que depende de un campo oculto lo ve vacío', async ({ lab }) => {
  const campos: FormField[] = [
    { id: 'tipo', type: 'radio', settings: { label: 'Tipo', required: true, options: options('Particular', 'Empresa') } },
    { id: 'empresa', type: 'text', settings: { label: 'Razón social', required: true }, condition: when('tipo', 'equals', 'Empresa') },
    { id: 'cif', type: 'text', settings: { label: 'CIF', required: true }, condition: when('empresa', 'is_not_empty') },
    { id: 'sector', type: 'select', settings: { label: 'Sector', options: options('Público', 'Privado') }, condition: when('cif', 'is_not_empty') },
    { id: 'organismo', type: 'text', settings: { label: 'Organismo', required: true }, condition: when('sector', 'equals', 'Público') },
  ];
  const { formId, link } = await lab.formPage(lab.definition(campos));
  const page = await lab.visitor();

  await page.goto(link);

  const visibles = async () => {
    const lista: string[] = [];

    for (const id of ['empresa', 'cif', 'sector', 'organismo']) {
      if (await field(page, id).isVisible()) {
        lista.push(id);
      }
    }

    return lista;
  };

  expect(await visibles()).toEqual([]);

  await page.getByLabel('Empresa', { exact: true }).check();
  expect(await visibles()).toEqual(['empresa']);

  await page.getByLabel('Razón social').fill('ACME');
  await page.getByLabel('CIF').fill('B12345678');
  await page.getByLabel('Sector').selectOption('Público');
  await page.getByLabel('Organismo').fill('Ayuntamiento');
  expect(await visibles()).toEqual(['empresa', 'cif', 'sector', 'organismo']);

  // Al volver a «Particular» desaparece toda la cadena, aunque los campos conserven lo escrito.
  await page.getByLabel('Particular').check();
  expect(await visibles()).toEqual([]);
  await send(page);

  const [mail] = await lab.mail();

  expect(mail.Text.replace(/\r/g, '').trim()).toBe('Tipo: Particular');

  const guardado = await lab.lastSubmission(formId);

  expect(guardado.entries.map((entry) => entry.field_id)).toEqual(['tipo']);

  // Un programa que envíe toda la cadena con «Particular» tampoco cuela nada.
  const colado = await lab.restSubmit(formId, { tipo: 'Particular', empresa: 'ACME', cif: 'B1', sector: 'Público', organismo: 'X' });

  expect(colado.status).toBe(200);
  expect((await lab.lastSubmission(formId, 2)).entries.map((entry) => entry.field_id)).toEqual(['tipo']);

  // Y si se rompe un eslabón intermedio, lo de detrás deja de exigirse: sin CIF no hay sector ni organismo.
  const sinCif = await lab.restSubmit(formId, { tipo: 'Empresa', empresa: 'ACME', sector: 'Público' });

  expect(sinCif.status).toBe(422);
  expect(Object.keys(sinCif.body.data.errors)).toEqual(['cif']);
});

test('sin JavaScript se ven todos los campos y el servidor aplica las condiciones al recibir el envío', async ({ lab }) => {
  const campos: FormField[] = [
    { id: 'tipo', type: 'select', settings: { label: 'Tipo', required: true, options: options('Particular', 'Empresa') } },
    { id: 'empresa', type: 'text', settings: { label: 'Razón social', required: true }, condition: when('tipo', 'equals', 'Empresa') },
    { id: 'dni', type: 'text', settings: { label: 'DNI', required: true }, condition: when('tipo', 'equals', 'Particular') },
  ];
  const { formId, link } = await lab.formPage(lab.definition(campos));
  const page = await lab.visitor({ javaScriptEnabled: false });

  await page.goto(link);
  await expect(page.getByLabel('Razón social')).toBeVisible();
  await expect(page.getByLabel('DNI')).toBeVisible();

  // Rellena los dos; cuenta solo el que le corresponde.
  await page.getByLabel('Tipo').selectOption('Particular');
  await page.getByLabel('Razón social').fill('No debería llegar');
  await page.getByLabel('DNI').fill('12345678Z');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page).toHaveURL(/formulario_mensaje_sent=\d+/);

  const [mail] = await lab.mail();

  expect(mail.Text).toContain('DNI: 12345678Z');
  expect(mail.Text).not.toContain('No debería llegar');
  expect((await lab.lastSubmission(formId)).entries.map((entry) => entry.field_id)).toEqual(['tipo', 'dni']);

  // Y el obligatorio que le corresponde se le exige, con su nombre, en la página de error.
  const error = await lab.postWithoutJs(formId, { tipo: 'Empresa', dni: '12345678Z' });

  expect(error.status).toBe(422);
  expect(error.text).toContain('<strong>Razón social:</strong>');
  expect(error.text).not.toContain('DNI');
});

test('una condición puede depender de un campo oculto de valor fijo, y un número con espacios se compara igual en los dos lados', async ({ lab }) => {
  const { formId, link } = await lab.formPage(
    lab.definition([
      { id: 'plan', type: 'hidden', settings: { label: 'Plan', source: 'fixed', value: 'pro' } },
      { id: 'interno', type: 'hidden', settings: { label: 'Interno', source: 'fixed', value: 'no-va-en-la-pagina' } },
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
      { id: 'empresa', type: 'text', settings: { label: 'Empresa', required: true }, condition: when('plan', 'equals', 'pro') },
      { id: 'dni', type: 'text', settings: { label: 'DNI', required: true }, condition: when('plan', 'equals', 'básico') },
      { id: 'importe', type: 'number', settings: { label: 'Importe' } },
      { id: 'aval', type: 'text', settings: { label: 'Aval', required: true }, condition: when('importe', 'greater_than', '999') },
    ]),
  );
  const page = await lab.visitor();

  await page.goto(link);

  // El navegador conoce el valor fijo del que depende una condición, y solo ese.
  await expect(field(page, 'empresa')).toBeVisible();
  await expect(field(page, 'dni')).toBeHidden();
  expect(await page.content()).not.toContain('no-va-en-la-pagina');

  // «1 000», con su espacio de millares, es mil también para el navegador.
  await expect(field(page, 'aval')).toBeHidden();
  await page.getByLabel('Importe').fill('1 000');
  await expect(field(page, 'aval')).toBeVisible();
  await page.getByLabel('Importe').fill('999');
  await expect(field(page, 'aval')).toBeHidden();
  await page.getByLabel('Importe').fill('1 000,5');

  // El servidor exige lo mismo que el navegador muestra: ni más ni menos.
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('[data-fm-field="empresa"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(page.locator('[data-fm-field="aval"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(page.locator('.fm-error:visible')).toHaveCount(2);

  await page.getByLabel('Empresa').fill('ACME');
  await page.getByLabel('Aval').fill('Banco');
  await send(page);

  const [mail] = await lab.mail();

  expect(mail.Text).toContain('Plan: pro');
  expect(mail.Text).toContain('Importe: 1000.5');
  expect((await lab.lastSubmission(formId)).entries.map((entry) => entry.field_id)).toEqual(['plan', 'interno', 'nombre', 'empresa', 'importe', 'aval']);
});
