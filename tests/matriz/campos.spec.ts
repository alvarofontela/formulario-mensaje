import { FormField } from '../e2e/support/forms';
import { Lab, expect, field, fieldError, options, send, test } from './support/lab';

// Todos los tipos de campo en un mismo formulario: lo que se rellena llega igual al correo y al
// envío guardado, con y sin JavaScript, y lo que no vale se rechaza campo a campo.

const colores = options('Rojo', 'Verde', 'Azul marino');

function todosLosCampos(): FormField[] {
  return [
    { id: 'titulo', type: 'heading', settings: { label: 'Tus datos', level: '2' } },
    { id: 'intro', type: 'paragraph', settings: { content: 'Rellena <strong>todo</strong> lo que puedas.' } },
    { id: 'nombre', type: 'text', width: 50, settings: { label: 'Nombre', required: true, placeholder: 'Tu nombre', help: 'Como quieras que te llamemos.' } },
    { id: 'correo', type: 'email', width: 50, settings: { label: 'Correo', required: true } },
    { id: 'telefono', type: 'tel', width: 33, settings: { label: 'Teléfono' } },
    { id: 'web', type: 'url', width: 33, settings: { label: 'Web' } },
    { id: 'edad', type: 'number', width: 33, settings: { label: 'Edad' } },
    { id: 'fecha', type: 'date', settings: { label: 'Fecha' } },
    { id: 'color', type: 'select', settings: { label: 'Color', placeholder: 'Elige un color', options: colores } },
    { id: 'talla', type: 'radio', settings: { label: 'Talla', required: true, options: options('S', 'M', 'L') } },
    { id: 'extras', type: 'checkboxes', settings: { label: 'Extras', options: colores } },
    { id: 'raya', type: 'divider', settings: {} },
    { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
    { id: 'origen', type: 'hidden', settings: { label: 'Origen', source: 'query', param: 'utm_source' } },
    { id: 'campana', type: 'hidden', settings: { label: 'Campaña', source: 'fixed', value: 'primavera-26' } },
    { id: 'pagina', type: 'hidden', settings: { label: 'Página', source: 'page_title' } },
    { id: 'acepto', type: 'consent', settings: { label: 'Privacidad', required: true, text: 'Acepto la <a href="/privacidad/">política de privacidad</a>' } },
  ];
}

/** Rellena el formulario de todos los campos con valores válidos. */
async function rellenar(page: import('@playwright/test').Page): Promise<void> {
  await page.getByLabel('Nombre').fill('Ana María Ñandú');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Teléfono').fill('+34 600 111 222');
  await page.getByLabel('Web').fill('https://example.org/ruta?a=1&b=2');
  await page.getByLabel('Edad').fill('42,5');
  await page.getByLabel('Fecha').fill('2026-03-15');
  await page.getByLabel('Color').selectOption('Azul marino');
  await page.getByRole('group', { name: 'Talla' }).getByLabel('M', { exact: true }).check();
  await page.getByRole('group', { name: 'Extras' }).getByLabel('Rojo').check();
  await page.getByRole('group', { name: 'Extras' }).getByLabel('Verde').check();
  await page.getByLabel('Mensaje').fill('Primera línea\nSegunda línea');
  await page.getByLabel(/Acepto la política/).check();
}

/** Lo que tiene que haber llegado, campo a campo y en el orden del formulario. */
const esperado = (origen: string, pagina: string): [string, string][] => [
  ['Nombre', 'Ana María Ñandú'],
  ['Correo', 'ana@example.test'],
  ['Teléfono', '+34 600 111 222'],
  ['Web', 'https://example.org/ruta?a=1&b=2'],
  ['Edad', '42.5'],
  // Con el formato de fecha de los ajustes del WordPress de pruebas (`F j, Y`).
  ['Fecha', 'marzo 15, 2026'],
  ['Color', 'Azul marino'],
  ['Talla', 'M'],
  ['Extras', 'Rojo, Verde'],
  ['Mensaje', 'Primera línea\nSegunda línea'],
  ['Origen', origen],
  ['Campaña', 'primavera-26'],
  ['Página', pagina],
  ['Privacidad', 'Aceptado: Acepto la política de privacidad'],
];

async function comprobar(lab: Lab, formId: number, origen: string, pagina: string): Promise<void> {
  const pares = esperado(origen, pagina);
  const [mail] = await lab.mail();

  // El correo no lista los campos que se quedaron vacíos.
  for (const [etiqueta, valor] of pares.filter((par) => par[1] !== '')) {
    expect(mail.Text.replace(/\r/g, ''), etiqueta).toContain(`${etiqueta}: ${valor}`);
  }

  expect(mail.Text).toContain(origen ? 'Origen: ' : 'Campaña: ');

  if (!origen) {
    expect(mail.Text).not.toContain('Origen');
  }

  // Los elementos de presentación no son datos.
  expect(mail.Text).not.toContain('Tus datos');
  expect(mail.Text).not.toContain('Rellena');

  const guardado = await lab.lastSubmission(formId);

  expect(guardado.entries.map((entry) => [entry.label, entry.value])).toEqual(pares);
  expect(guardado.failed_actions).toBe(0);
  expect(guardado.deliveries.map((delivery) => delivery.status)).toEqual(['success']);
}

test('todos los tipos de campo, con JavaScript', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(todosLosCampos()), 'shortcode', 'Todos los campos');
  const page = await lab.visitor();

  await page.goto(`${link}?utm_source=boletin`);

  // Presentación: título del nivel pedido, párrafo con su HTML básico, separador y ayuda.
  await expect(page.getByRole('heading', { level: 2, name: 'Tus datos' })).toBeVisible();
  await expect(page.locator('.fm-paragraph strong')).toHaveText('todo');
  await expect(page.locator('.fm-divider')).toBeVisible();
  await expect(page.getByLabel('Nombre')).toHaveAttribute('placeholder', 'Tu nombre');
  await expect(page.getByLabel('Nombre')).toHaveAccessibleDescription('Como quieras que te llamemos.');
  await expect(page.getByLabel('Color')).toHaveValue('');
  await expect(page.getByLabel(/Acepto la política/)).not.toBeChecked();
  await expect(page.locator('.fm-field--consent a')).toHaveAttribute('href', '/privacidad/');

  await rellenar(page);
  await send(page);

  await comprobar(lab, formId, 'boletin', `Todos los campos ${lab.id}`);
});

test('todos los tipos de campo, sin JavaScript', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(todosLosCampos()), 'shortcode', 'Todos los campos');
  const page = await lab.visitor({ javaScriptEnabled: false });

  await page.goto(`${link}?utm_source=boletin`);
  await rellenar(page);
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page).toHaveURL(/formulario_mensaje_sent=\d+/);
  await expect(page.locator('.fm-success')).toBeVisible();

  // Sin JavaScript el servidor resuelve por su cuenta lo que puede: el parámetro de la URL viaja en la página,
  // y el título de la página es el mismo que con JavaScript.
  await comprobar(lab, formId, 'boletin', `Todos los campos ${lab.id}`);
});

test('todos los tipos de campo, insertados con el bloque', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(todosLosCampos()), 'block', 'Todos los campos');
  const page = await lab.visitor();

  await page.goto(link);
  await rellenar(page);
  await send(page);

  // Sin parámetro en la URL, el campo oculto queda vacío: se guarda vacío y el correo no lo lista.
  await comprobar(lab, formId, '', `Todos los campos ${lab.id}`);
});

test('cada tipo de campo rechaza lo que no vale y dice por qué, con JavaScript', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(todosLosCampos()));
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Correo').fill('esto no es un correo');
  await page.getByLabel('Teléfono').fill('llámame');
  await page.getByLabel('Web').fill('no es una dirección');
  await page.getByLabel('Edad').fill('doce');
  await page.getByRole('button', { name: 'Enviar' }).click();

  const errores: Record<string, string> = {
    nombre: 'Este campo es obligatorio.',
    correo: 'Escribe una dirección de correo válida.',
    telefono: 'Escribe un número de teléfono válido.',
    web: 'Escribe una dirección web válida.',
    edad: 'Escribe un número válido.',
    talla: 'Este campo es obligatorio.',
    acepto: 'Este campo es obligatorio.',
  };

  for (const [id, mensaje] of Object.entries(errores)) {
    await expect(fieldError(page, id), id).toHaveText(mensaje);
  }

  // Los que estaban bien o vacíos sin ser obligatorios no llevan error.
  for (const id of ['fecha', 'color', 'extras', 'mensaje']) {
    await expect(fieldError(page, id), id).toBeHidden();
  }

  await expect(page.locator('.fm-status')).toHaveText('Revisa los campos marcados.');
  // El foco va al primer campo con error, y el campo queda marcado para los lectores de pantalla.
  await expect(page.getByLabel('Nombre')).toBeFocused();
  await expect(page.getByLabel('Nombre')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByLabel('Correo')).toHaveAccessibleDescription('Escribe una dirección de correo válida.');

  // Al corregir un campo se retira su error, sin esperar al siguiente envío.
  await page.getByLabel('Correo').fill('ana@example.test');
  await expect(fieldError(page, 'correo')).toBeHidden();
  await expect(page.getByLabel('Correo')).not.toHaveAttribute('aria-invalid', 'true');

  await lab.expectNoMail(lab.mailTo, 1_000);
  expect(await lab.submissions(formId)).toHaveLength(0);

  // Corregido todo, sale.
  await rellenar(page);
  await send(page);
  await lab.mail();
});

test('el servidor valida igual sin JavaScript, también lo que un navegador no dejaría escribir', async ({ lab }) => {
  const formId = await lab.form(lab.definition(todosLosCampos()));

  const respuesta = await lab.postWithoutJs(formId, {
    nombre: '   ',
    correo: 'ana@',
    telefono: '<b>600</b>',
    web: 'javascript:alert(1)',
    edad: '1e5',
    fecha: '2026-02-30',
    color: 'Morado',
    talla: 'XXL',
    extras: ['Rojo', 'Inventado'],
    acepto: 'quizá',
  });

  expect(respuesta.status).toBe(422);

  for (const etiqueta of ['Nombre', 'Correo', 'Teléfono', 'Web', 'Edad', 'Fecha', 'Color', 'Talla', 'Extras', 'Privacidad']) {
    expect(respuesta.text, etiqueta).toContain(`<strong>${etiqueta}:</strong>`);
  }

  expect(respuesta.text).toContain('Escribe una fecha válida.');
  expect(respuesta.text).toContain('Elige una de las opciones disponibles.');
  expect(respuesta.text).not.toContain('<b>600</b>');
  expect(await lab.submissions(formId)).toHaveLength(0);
  await lab.expectNoMail(lab.mailTo, 1_000);
});

test('lo que escribe el visitante llega como texto: sin etiquetas, con sus signos y sin interpretarse', async ({ lab }) => {
  const peligros = {
    nombre: 'Ana <b>negrita</b><script>alert("xss")</script> & <img src=x onerror=alert(1)> y a<b',
    asunto: '{reference} {field:correo} {all_fields} =1+1 @SUM(A1)',
    idiomas: 'Ñandú 日本語 العربية 😀 – « » “ ” <3',
    mensaje: 'Línea 1\r\nBcc: victima@example.test\n\n<a href="https://evil.example">pulsa</a>\nSi x < 10 y a<b',
  };
  // Las etiquetas HTML se quitan al recibir el envío; un «<» que no es HTML se conserva.
  const nombreLimpio = 'Ana negrita & y a<b';
  const { formId, link } = await lab.formPage(
    lab.definition(
      [
        { id: 'nombre', type: 'text', settings: { label: 'Nombre' } },
        { id: 'asunto', type: 'text', settings: { label: 'Asunto' } },
        { id: 'idiomas', type: 'text', settings: { label: 'Idiomas' } },
        { id: 'correo', type: 'email', settings: { label: 'Correo' } },
        { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
      ],
      {
        settings: { success_message: 'Gracias, {field:nombre}.' },
        actions: [
          lab.email({ format: 'html', subject: 'De {field:nombre} / {field:asunto}', body: 'Hola:\n{all_fields}\nSuelto: {field:mensaje}', reply_to_field: 'correo' }),
          lab.email({ to: [lab.address('texto')], format: 'text', subject: 'Texto {field:idiomas}', body: '{all_fields}' }, { id: 'texto' }),
        ],
      },
    ),
  );
  const page = await lab.visitor();
  const dialogos: string[] = [];

  page.on('dialog', (dialog) => {
    dialogos.push(dialog.message());
    void dialog.dismiss();
  });

  await page.goto(link);
  await page.getByLabel('Nombre').fill(peligros.nombre);
  await page.getByLabel('Asunto').fill(peligros.asunto);
  await page.getByLabel('Idiomas').fill(peligros.idiomas);
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Mensaje').fill(peligros.mensaje);
  await send(page);

  // En el mensaje de éxito, como texto.
  await expect(page.locator('.fm-success')).toHaveText(`Gracias, ${nombreLimpio}.`);
  await expect(page.locator('.fm-success script, .fm-success img, .fm-success b')).toHaveCount(0);

  // En el correo HTML, escapado una sola vez; en el asunto, sin saltos ni cabeceras añadidas.
  const [html] = await lab.mail();

  expect(html.HTML).toContain('Ana negrita &amp; y a&lt;b');
  expect(html.HTML).toContain('Si x &lt; 10 y a&lt;b');
  expect(html.HTML).not.toContain('&amp;lt;');
  expect(html.HTML).not.toContain('<script>alert');
  expect(html.HTML).not.toContain('<img src=x');
  expect(html.HTML).not.toContain('<a href="https://evil.example"');
  expect(html.Subject).toContain(`De ${nombreLimpio} / `);
  expect(html.Subject).toContain('{reference} {field:correo} {all_fields} =1+1 @SUM(A1)');
  expect(html.To.map((to) => to.Address)).toEqual([lab.mailTo]);
  expect(html.ReplyTo.map((to) => to.Address)).toEqual(['ana@example.test']);

  // Las etiquetas que escribe el visitante no se sustituyen: son texto.
  const [texto] = await lab.mail(lab.address('texto'));

  expect(texto.Subject).toBe(`Texto ${peligros.idiomas}`);
  expect(texto.Text).toContain(`Asunto: ${peligros.asunto}`);
  expect(texto.Text).toContain(`Idiomas: ${peligros.idiomas}`);
  expect(texto.Text).toContain(`Nombre: ${nombreLimpio}`);
  expect(texto.Text).toContain('Si x < 10 y a<b');
  expect(texto.Text).toContain('pulsa');
  expect(texto.Text).not.toContain('<a href');
  expect(texto.To.map((to) => to.Address)).toEqual([lab.address('texto')]);

  // Nadie más recibió nada: la línea «Bcc:» del mensaje es solo texto.
  expect(await lab.mailsTo('victima@example.test')).toHaveLength(0);

  // En la administración, también como texto.
  const guardado = await lab.lastSubmission(formId);
  const admin = await lab.admin();

  admin.on('dialog', (dialog) => {
    dialogos.push(dialog.message());
    void dialog.dismiss();
  });

  await admin.goto(`/wp-admin/admin.php?page=formulario-mensaje-submissions&submission=${guardado.id}`);

  // El mensaje abierto reparte los datos: el primer texto encabeza, el correo va debajo, el área de texto
  // se lee como el mensaje en sí y lo demás queda en la lista de datos.
  const lector = admin.locator('.fm-reader');
  const datos = lector.locator('.fm-reader__facts dd');

  await expect(lector.locator('.fm-reader__title')).toHaveText(nombreLimpio);
  await expect(lector.locator('.fm-reader__contact')).toHaveText('ana@example.test');
  await expect(lector.locator('.fm-reader__facts dt')).toHaveText(['Asunto', 'Idiomas']);
  await expect(datos.nth(0)).toHaveText(peligros.asunto);
  // WordPress cambia el emoji por una imagen cuando el navegador no tiene con qué pintarlo: se comprueba lo demás aquí
  // y el valor exacto, emoji incluido, en lo que devuelve la API.
  await expect(datos.nth(1)).toContainText('Ñandú 日本語 العربية');
  await expect(datos.nth(1)).toContainText('– « » “ ” <3');
  await expect(lector.locator('.fm-reader__text')).toContainText('Bcc: victima@example.test');
  await expect(lector.locator('.fm-reader__text')).toContainText('Si x < 10 y a<b');
  expect(guardado.entries.map((entry) => entry.value)).toEqual([
    nombreLimpio,
    peligros.asunto,
    peligros.idiomas,
    'ana@example.test',
    'Línea 1\nBcc: victima@example.test\n\npulsa\nSi x < 10 y a<b',
  ]);
  // Ni en el encabezado, ni en el texto, ni en los datos hay nada que no sea texto. (Los enlaces de la barra,
  // como «Responder», son del panel y quedan fuera de estas zonas.)
  await expect(
    lector.locator('.fm-reader__title, .fm-reader__contact, .fm-reader__text, .fm-reader__facts').locator('script, img:not(.emoji), a'),
  ).toHaveCount(0);

  // Y en la lista de la bandeja, filtrada por su formulario.
  await admin.goto(`/wp-admin/admin.php?page=formulario-mensaje-submissions&form=${formId}`);
  await expect(admin.locator('li.fm-message').first()).toContainText(nombreLimpio);
  await expect(admin.locator('li.fm-message script, li.fm-message img:not(.emoji), li.fm-message a')).toHaveCount(0);

  expect(dialogos).toEqual([]);
});

test('las opciones con caracteres especiales se eligen y llegan con su texto exacto', async ({ lab }) => {
  const raras = options('Sí, claro', 'Tom & Jerry', '"Entre comillas"', "L'Hospitalet", '50% o más', 'a < b > c');
  const { link } = await lab.formPage(
    lab.definition([
      { id: 'uno', type: 'select', settings: { label: 'Desplegable', options: raras } },
      { id: 'dos', type: 'radio', settings: { label: 'Radios', options: raras } },
      { id: 'tres', type: 'checkboxes', settings: { label: 'Casillas', options: raras } },
    ]),
  );
  const page = await lab.visitor();

  await page.goto(link);
  await expect(page.getByLabel('Desplegable').locator('option')).toHaveText(['Elige una opción', ...raras.map((option) => option.label)]);

  await page.getByLabel('Desplegable').selectOption({ label: 'Tom & Jerry' });
  await page.getByRole('group', { name: 'Radios' }).getByLabel('"Entre comillas"').check();

  for (const option of raras) {
    await page.getByRole('group', { name: 'Casillas' }).getByLabel(option.label, { exact: true }).check();
  }

  await send(page);

  const [mail] = await lab.mail();

  expect(mail.Text).toContain('Desplegable: Tom & Jerry');
  expect(mail.Text).toContain('Radios: "Entre comillas"');
  expect(mail.Text).toContain(`Casillas: ${raras.map((option) => option.label).join(', ')}`);
});

test('el prellenado toma el valor fijo, el parámetro de la URL o los datos del usuario', async ({ lab }) => {
  const { link } = await lab.formPage(
    lab.definition([
      { id: 'fijo', type: 'text', settings: { label: 'Fijo', prefill_source: 'fixed', prefill_value: 'Valor de partida' } },
      { id: 'ref', type: 'text', settings: { label: 'Referencia', prefill_source: 'query', prefill_value: 'ref' } },
      { id: 'quien', type: 'text', settings: { label: 'Usuario', prefill_source: 'user', prefill_user_field: 'display_name' } },
      { id: 'correo', type: 'email', settings: { label: 'Correo', prefill_source: 'user', prefill_user_field: 'user_email' } },
      { id: 'nota', type: 'textarea', settings: { label: 'Nota', prefill_source: 'query', prefill_value: 'nota' } },
    ]),
  );

  // Un visitante: lo fijo y lo de la URL; de usuario, nada.
  const page = await lab.visitor();

  await page.goto(`${link}?ref=ABC-123&nota=${encodeURIComponent('Vengo del boletín')}`);
  await expect(page.getByLabel('Fijo')).toHaveValue('Valor de partida');
  await expect(page.getByLabel('Referencia')).toHaveValue('ABC-123');
  await expect(page.getByLabel('Nota')).toHaveValue('Vengo del boletín');
  await expect(page.getByLabel('Usuario')).toHaveValue('');
  await expect(page.getByLabel('Correo')).toHaveValue('');

  // El visitante puede cambiarlo, y lo que llega es lo que deja escrito.
  await page.getByLabel('Referencia').fill('OTRA');
  await send(page);
  expect((await lab.mail())[0].Text).toContain('Referencia: OTRA');

  // Con sesión, sus datos.
  const admin = await lab.admin();

  await admin.goto(link);
  await expect(admin.getByLabel('Usuario')).toHaveValue('admin');
  await expect(admin.getByLabel('Correo')).toHaveValue(/@/);
  await expect(admin.getByLabel('Referencia')).toHaveValue('');
});

test('una petición con formas inesperadas se rechaza con orden, nunca con un error del servidor', async ({ lab }) => {
  const formId = await lab.form(lab.definition(todosLosCampos()));
  const validos = { nombre: 'Ana', correo: 'ana@example.test', talla: 'M', acepto: '1' };

  const casos: Record<string, Record<string, unknown>> = {
    'listas donde va un texto': { ...validos, nombre: ['Ana', 'Luis'], correo: { a: 'b' } },
    'texto donde va una lista': { ...validos, extras: 'Rojo' },
    'listas anidadas': { ...validos, extras: [['Rojo'], { x: 'Verde' }] },
    'campos que no existen': { ...validos, admin: '1', '__proto__': 'x', 'nombre[]': 'y' },
    'valores enormes': { ...validos, mensaje: 'x'.repeat(200_000), nombre: 'n'.repeat(5_000) },
    'tipos que no son texto': { ...validos, edad: 12, fecha: true, color: null },
  };

  for (const [caso, campos] of Object.entries(casos)) {
    const respuesta = await lab.restSubmit(formId, campos as Record<string, string>, { waitMs: 3_200 });

    expect([200, 422], `${caso}: ${JSON.stringify(respuesta.body).slice(0, 300)}`).toContain(respuesta.status);
  }

  // Los que se aceptaron guardaron solo campos del formulario, recortados a su longitud máxima.
  for (const enviado of await lab.submissions(formId)) {
    const detalle = await lab.submission(enviado.id);

    expect(detalle.entries.every((entry) => todosLosCampos().some((campo) => campo.id === entry.field_id))).toBe(true);
    expect(detalle.entries.every((entry) => entry.value.length <= 10_000)).toBe(true);
  }
});

test('los anchos reparten los campos en filas y en pantallas estrechas cada uno ocupa la suya', async ({ lab }) => {
  const { link } = await lab.formPage(lab.definition(todosLosCampos()));
  const escritorio = await lab.visitor({ viewport: { width: 1280, height: 900 } });

  await escritorio.goto(link);

  const caja = async (page: import('@playwright/test').Page, id: string) => (await field(page, id).boundingBox())!;

  const [nombre, correo, telefono, web, edad, fecha] = await Promise.all(
    ['nombre', 'correo', 'telefono', 'web', 'edad', 'fecha'].map((id) => caja(escritorio, id)),
  );

  // Dos mitades en una fila, tres tercios en la siguiente y el resto a todo el ancho.
  expect(Math.abs(nombre.y - correo.y)).toBeLessThan(2);
  expect(correo.x).toBeGreaterThan(nombre.x + nombre.width - 1);
  expect(Math.abs(nombre.width - correo.width)).toBeLessThan(2);
  expect(Math.abs(telefono.y - web.y)).toBeLessThan(2);
  expect(Math.abs(web.y - edad.y)).toBeLessThan(2);
  expect(telefono.y).toBeGreaterThan(nombre.y);
  expect(fecha.width).toBeGreaterThan(nombre.width * 1.9);

  const movil = await lab.visitor({ viewport: { width: 390, height: 800 } });

  await movil.goto(link);

  const [nombreMovil, correoMovil, telefonoMovil] = await Promise.all(['nombre', 'correo', 'telefono'].map((id) => caja(movil, id)));

  expect(correoMovil.y).toBeGreaterThan(nombreMovil.y + nombreMovil.height - 1);
  expect(telefonoMovil.y).toBeGreaterThan(correoMovil.y + correoMovil.height - 1);
  expect(Math.abs(nombreMovil.width - correoMovil.width)).toBeLessThan(2);

  // Nada se sale de la pantalla.
  expect(await movil.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
