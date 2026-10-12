import { Page } from '@playwright/test';
import { expect, field, send, test } from './support/lab';

// El constructor de punta a punta: cada plantilla de partida da un formulario que funciona tal
// cual, y un formulario montado a mano en la pantalla se comporta en la web como se configuró.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';

/**
 * Rellena un formulario cualquiera como lo haría una persona con prisa: algo válido en cada campo
 * que esté a la vista, según el tipo de control.
 */
async function rellenarLoQueHaya(page: Page): Promise<void> {
  const campos = page.locator('form.fm-form [data-fm-field]:visible');

  for (const campo of await campos.all()) {
    const clase = (await campo.getAttribute('class')) ?? '';
    const tipo = /fm-field--([a-z]+)/.exec(clase)?.[1];

    switch (tipo) {
      case 'email':
        await campo.locator('input').fill('visitante@example.test');
        break;
      case 'tel':
        await campo.locator('input').fill('+34 600 111 222');
        break;
      case 'number':
        await campo.locator('input').fill('5');
        break;
      case 'date':
        await campo.locator('input').fill('2026-03-15');
        break;
      case 'url':
        await campo.locator('input').fill('https://example.org');
        break;
      case 'textarea':
        await campo.locator('textarea').fill('Mensaje de prueba');
        break;
      case 'select':
        await campo.locator('select').selectOption({ index: 1 });
        break;
      case 'radio':
      case 'checkboxes':
      case 'consent':
        await campo.locator('input').first().check();
        break;
      case 'text':
        await campo.locator('input').fill('Texto de prueba');
        break;
      default:
        break;
    }
  }
}

const plantillas = [
  { id: 'contact', nombre: 'contacto' },
  { id: 'quote', nombre: 'solicitud de presupuesto' },
  { id: 'callback', nombre: '«te llamamos»' },
  { id: 'newsletter', nombre: 'alta en el boletín' },
];

for (const plantilla of plantillas) {
  test(`la plantilla de ${plantilla.nombre} da un formulario que funciona sin tocar nada más`, async ({ lab }) => {
    const admin = await lab.admin();
    const titulo = `Plantilla ${plantilla.id} ${lab.id}`;

    await admin.goto(`${formsScreen}&form=new:${plantilla.id}`);
    await expect(admin.locator('.fm-fields__item').first()).toBeVisible();
    await admin.getByLabel('Nombre del formulario').fill(titulo);

    // La vista previa es el formulario de verdad, con los campos de la plantilla.
    const campos = await admin.locator('.fm-fields__item').count();

    await expect(admin.frameLocator('.fm-preview__frame').locator('[data-fm-field]')).toHaveCount(campos, { timeout: 45_000 });

    // Lo único que se cambia: a quién avisa, para reconocer el correo de este test. Las notificaciones
    // son el apartado que ya está abierto al entrar en el paso «Al recibirlo».
    await admin.getByRole('tab', { name: 'Al recibirlo' }).click();
    await expect(admin.getByRole('button', { name: /^Notificaciones por correo/ })).toHaveAttribute('aria-expanded', 'true');
    await admin.getByLabel('Enviar a', { exact: true }).fill(lab.mailTo);
    await admin.getByRole('button', { name: 'Crear formulario' }).click();
    await expect(admin.locator('.components-notice').first()).toContainText('Formulario guardado.');
    await expect(admin).toHaveURL(/form=\d+/);

    const formId = Number(new URL(admin.url()).searchParams.get('form'));
    const link = await lab.page(`<!-- wp:formulario-mensaje/form {"formId":${formId}} /-->`);
    const page = await lab.visitor();

    await page.goto(link);
    await expect(page.locator('form.fm-form [data-fm-field]')).toHaveCount(campos);

    // Vacío no pasa: toda plantilla tiene algún campo obligatorio.
    await page.locator('.fm-submit').click();
    await expect(page.locator('.fm-error:visible').first()).toBeVisible();

    await rellenarLoQueHaya(page);
    await page.locator('.fm-submit').click();
    await expect(page.locator('.fm-success')).toBeVisible({ timeout: 20_000 });

    const [mail] = await lab.mail();

    expect(mail.Subject).toContain(titulo);

    const guardado = await lab.lastSubmission(formId);

    expect(guardado.entries.filter((entry) => entry.value !== '')).not.toHaveLength(0);
    expect(guardado.failed_actions).toBe(0);
  });
}

test('un formulario montado a mano desde la plantilla en blanco se comporta como se configuró', async ({ lab }) => {
  const admin = await lab.admin();
  const titulo = `A mano ${lab.id}`;

  await admin.goto(`${formsScreen}&form=new:blank`);
  await admin.getByLabel('Nombre del formulario').fill(titulo);

  const anadir = async (tipo: string, etiqueta: string) => {
    await admin.getByRole('button', { name: 'Añadir campo' }).click();
    await admin.getByRole('menuitem', { name: tipo, exact: true }).click();
    await admin.getByLabel('Etiqueta', { exact: true }).fill(etiqueta);
  };

  // Nombre y correo, obligatorios y a media fila.
  await anadir('Texto', 'Nombre');
  await admin.getByLabel('Obligatorio').check();
  await admin.getByLabel('Ancho', { exact: true }).selectOption({ label: 'La mitad' });

  await anadir('Correo electrónico', 'Correo');
  await admin.getByLabel('Obligatorio').check();
  await admin.getByLabel('Ancho', { exact: true }).selectOption({ label: 'La mitad' });

  // Un desplegable con sus opciones.
  await anadir('Desplegable', 'Motivo');
  await admin.getByLabel('Obligatorio').check();
  await admin.getByLabel('Opción 1', { exact: true }).fill('Consulta');
  await admin.getByLabel('Opción 2', { exact: true }).fill('Queja');
  await admin.getByRole('button', { name: 'Añadir opción' }).click();
  await admin.getByLabel('Opción 3', { exact: true }).fill('Otro <motivo>');

  // Un área de texto que solo se pide si el motivo es una queja.
  await anadir('Área de texto', 'Cuéntanos qué ha pasado');
  await admin.getByLabel('Obligatorio').check();
  await admin.getByRole('button', { name: 'Añadir regla' }).click();
  await admin.getByLabel('Campo', { exact: true }).selectOption({ label: 'Motivo' });
  await admin.getByLabel('Valor', { exact: true }).selectOption({ label: 'Queja' });

  await anadir('Número', 'Número de pedido');
  await anadir('Consentimiento', 'Privacidad');

  const nombres = admin.locator('.fm-fields__name');

  await expect(nombres).toHaveText(['Nombre', 'Correo', 'Motivo', 'Cuéntanos qué ha pasado', 'Número de pedido', 'Privacidad']);

  // El número de pedido, antes que el motivo.
  await admin.getByRole('button', { name: 'Subir «Número de pedido»' }).click();
  await admin.getByRole('button', { name: 'Subir «Número de pedido»' }).click();
  await expect(nombres).toHaveText(['Nombre', 'Correo', 'Número de pedido', 'Motivo', 'Cuéntanos qué ha pasado', 'Privacidad']);

  await admin.getByRole('button', { name: 'Crear formulario' }).click();
  await expect(admin.locator('.components-notice').first()).toContainText('Formulario guardado.');
  await expect(admin).toHaveURL(/form=\d+/);

  // Cada campo recibe su identificador al crearlo: es el que se usa en las etiquetas de los textos.
  const formId = Number(new URL(admin.url()).searchParams.get('form'));
  const ids = (await lab.api.getForm(formId)).definition.fields.map((campo) => campo.id);

  expect(new Set(ids).size).toBe(6);

  // Aviso por correo con asunto propio, y mensaje de éxito con el nombre del visitante.
  // Las dos cosas están en el paso «Al recibirlo»: las notificaciones, que es el apartado abierto al
  // entrar, y lo que ve quien lo envía, que se despliega (y cierra el otro).
  await admin.getByRole('tab', { name: 'Al recibirlo' }).click();
  await admin.getByLabel('Enviar a', { exact: true }).fill(lab.mailTo);
  await admin.getByLabel('Asunto', { exact: true }).fill(`{field:${ids[3]}} de {field:${ids[0]}}`);

  await admin.getByRole('button', { name: /^Qué ve quien lo envía/ }).click();
  await admin.getByLabel('Texto del botón').fill('Enviar consulta');
  await admin.getByLabel('Mensaje de éxito').fill(`Gracias, <strong>{field:${ids[0]}}</strong>. Te escribiremos a {field:${ids[1]}}.`);
  await admin.getByLabel('Vaciarlo para permitir otro envío').check();

  await admin.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(admin.locator('.components-notice').first()).toContainText('Formulario guardado.');
  await expect(admin.getByRole('button', { name: 'Guardar', exact: true })).toBeDisabled();

  // Lo guardado es lo que se configuró.
  const { definition } = await lab.api.getForm(formId);

  expect(definition.fields.map((campo) => [campo.type, campo.settings.label, campo.width ?? 100])).toEqual([
    ['text', 'Nombre', 50],
    ['email', 'Correo', 50],
    ['number', 'Número de pedido', 100],
    ['select', 'Motivo', 100],
    ['textarea', 'Cuéntanos qué ha pasado', 100],
    ['consent', 'Privacidad', 100],
  ]);
  expect((definition.fields[3].settings.options as { label: string }[]).map((opcion) => opcion.label)).toEqual(['Consulta', 'Queja', 'Otro']);
  expect(definition.fields[4].condition).toEqual({ match: 'all', rules: [{ field: definition.fields[3].id, operator: 'equals', value: 'Queja' }] });
  expect(definition.settings.after_submit).toBe('reset');

  // Al recargar el constructor todo sigue ahí. El paso va en la dirección, así que se vuelve al de
  // «Al recibirlo»: los campos están en «Preguntas».
  await admin.reload();
  await expect(admin.getByRole('tab', { name: 'Al recibirlo' })).toHaveAttribute('aria-selected', 'true');
  await admin.getByRole('button', { name: /^Qué ve quien lo envía/ }).click();
  await expect(admin.getByLabel('Texto del botón')).toHaveValue('Enviar consulta');
  await admin.getByRole('tab', { name: 'Preguntas' }).click();
  await expect(admin.locator('.fm-fields__name')).toHaveText(['Nombre', 'Correo', 'Número de pedido', 'Motivo', 'Cuéntanos qué ha pasado', 'Privacidad']);
  await expect(admin.locator('.fm-fields__item', { hasText: 'Cuéntanos qué ha pasado' })).toContainText('Condicional');
  await expect(admin.locator('.fm-fields__item', { hasText: 'Nombre' }).first()).toContainText('50 %');

  // Y en la web.
  const link = await lab.page(`<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`);
  const page = await lab.visitor({ viewport: { width: 1280, height: 900 } });
  const detalle = definition.fields[4].id;

  await page.goto(link);

  const cajaNombre = (await page.locator(`[data-fm-field="${definition.fields[0].id}"]`).boundingBox())!;
  const cajaCorreo = (await page.locator(`[data-fm-field="${definition.fields[1].id}"]`).boundingBox())!;

  expect(Math.abs(cajaNombre.y - cajaCorreo.y)).toBeLessThan(2);
  await expect(field(page, detalle)).toBeHidden();

  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Motivo').selectOption('Queja');
  await expect(field(page, detalle)).toBeVisible();
  await page.getByLabel(/Acepto|privacidad/i).last().check();
  await page.getByRole('button', { name: 'Enviar consulta' }).click();
  await expect(page.locator(`[data-fm-field="${detalle}"] .fm-error`)).toHaveText('Este campo es obligatorio.');

  await page.getByLabel('Cuéntanos qué ha pasado').fill('El pedido llegó roto.');
  await send(page, 'Enviar consulta');
  await expect(page.locator('.fm-success')).toHaveText('Gracias, Ana. Te escribiremos a ana@example.test.');
  await expect(page.locator('.fm-success strong')).toHaveText('Ana');
  // Vaciado para otro envío.
  await expect(page.getByLabel('Nombre')).toHaveValue('');

  const [mail] = await lab.mail();

  expect(mail.Subject).toBe('Queja de Ana');
});

test('duplicar, exportar e importar conservan el formulario entero, y los cambios en la copia no tocan el original', async ({ lab }) => {
  const formId = await lab.form(
    lab.definition(
      [
        { id: 'nombre', type: 'text', width: 50, settings: { label: 'Nombre', required: true } },
        { id: 'motivo', type: 'radio', width: 50, settings: { label: 'Motivo', options: [{ label: 'Alta', value: 'Alta' }, { label: 'Baja', value: 'Baja' }] } },
        { id: 'detalle', type: 'textarea', settings: { label: 'Detalle' }, condition: { match: 'all', rules: [{ field: 'motivo', operator: 'equals', value: 'Baja' }] } },
        { id: 'doc', type: 'file', settings: { label: 'Documento', file_types: ['pdf'], max_files: '2' } },
      ],
      {
        style: { label_color: '#aa0000', gap: 24 },
        settings: { success_message: 'Hecho: {reference}', after_submit: 'reset' },
        actions: [lab.email({ subject: 'Original' }), lab.webhook({ secret: 'secreto-original', headers: [{ name: 'X-Key', value: 'clave' }] })],
      },
    ),
    'Original',
  );
  const original = await lab.api.getForm(formId);

  // Duplicar: todo igual, también el secreto del webhook.
  const copia = await lab.api.call<{ id: number }>('post', `/formulario-mensaje/v1/forms/${formId}/duplicate`);
  const duplicado = await lab.api.getForm(copia.id);

  expect(copia.id).not.toBe(formId);
  expect(duplicado.definition).toEqual(original.definition);
  expect(duplicado.title).not.toBe(original.title);

  // Exportar e importar: todo menos los secretos, y con las acciones en pausa.
  const exportado = await lab.api.call<{ format: string; definition: typeof original.definition }>('get', `/formulario-mensaje/v1/forms/${formId}/export`);
  const importado = await lab.api.call<{ id: number }>('post', '/formulario-mensaje/v1/forms/import', { document: exportado });
  const traido = await lab.api.getForm(importado.id);

  expect(JSON.stringify(exportado)).not.toContain('secreto-original');
  expect(JSON.stringify(exportado)).not.toContain('"clave"');
  expect(traido.definition.fields).toEqual(original.definition.fields);
  expect(traido.definition.style).toEqual(original.definition.style);
  expect(traido.definition.settings).toEqual(original.definition.settings);
  expect(traido.definition.actions.map((accion) => accion.enabled)).toEqual([false, false]);

  // Cambiar la copia no cambia el original.
  duplicado.definition.fields[0].settings.label = 'Nombre completo';
  await lab.api.updateForm(copia.id, 'Copia cambiada', duplicado.definition);
  expect((await lab.api.getForm(formId)).definition.fields[0].settings.label).toBe('Nombre');

  // Las dos copias aceptan envíos por su cuenta; la importada, con sus avisos en pausa, solo guarda.
  expect((await lab.restSubmit(copia.id, { nombre: 'Ana', motivo: 'Alta' })).status).toBe(200);
  expect((await lab.restSubmit(importado.id, { nombre: 'Luis', motivo: 'Alta' })).status).toBe(200);

  const correos = await lab.mail();

  expect(correos).toHaveLength(1);
  expect(correos[0].Text).toContain('Nombre completo: Ana');
  expect((await lab.lastSubmission(importado.id)).deliveries).toEqual([]);
  expect(await lab.submissions(formId)).toHaveLength(0);
});

test('el constructor avisa de un formulario que ni guarda los envíos ni avisa a nadie', async ({ lab }) => {
  const campos = [
    { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
    { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
  ];
  const mudo = await lab.form(lab.definition(campos, { settings: { store_enabled: false }, actions: [lab.email({}, { enabled: false })] }), 'Mudo');
  const soloCorreo = await lab.form(lab.definition(campos, { settings: { store_enabled: false } }), 'Solo correo');
  const admin = await lab.admin();
  const aviso = admin.locator('.fm-editor__warning');

  await admin.goto(`${formsScreen}&form=${mudo}`);
  await expect(aviso).toContainText('lo que envíen los visitantes se perderá');

  // En cuanto guarda los envíos, deja de avisar; y si se vuelve a quitar, avisa otra vez. El interruptor
  // está en el apartado «Guardar los mensajes» del paso «Al recibirlo», y el aviso se ve en todos los pasos.
  await admin.getByRole('tab', { name: 'Al recibirlo' }).click();
  await expect(aviso).toBeVisible();
  await admin.getByRole('button', { name: /^Guardar los mensajes/ }).click();
  await admin.getByLabel('Guardar los envíos').check();
  await expect(aviso).toBeHidden();
  await admin.getByLabel('Guardar los envíos').uncheck();
  await expect(aviso).toBeVisible();

  // Con una notificación activa basta: no avisa.
  await admin.goto(`${formsScreen}&form=${soloCorreo}`);
  await expect(admin.locator('.fm-fields__item').first()).toBeVisible();
  await expect(aviso).toHaveCount(0);

  // El aviso dice la verdad: ese formulario acepta el envío y no queda nada.
  expect((await lab.restSubmit(mudo, { nombre: 'Ana', correo: 'ana@example.test' })).status).toBe(200);
  expect(await lab.submissions(mudo)).toHaveLength(0);
  await lab.expectNoMail(lab.mailTo, 1_500);
});
