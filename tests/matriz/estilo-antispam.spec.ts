import { FormField } from '../e2e/support/forms';
import { expect, send, test } from './support/lab';

// Estilo del formulario en sus dos niveles (formulario e inserción) y defensas contra el abuso
// del envío público.

const contacto: FormField[] = [
  { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
  { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
  { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
];

test('cada ajuste del diseñador de estilo llega a su sitio en la página', async ({ lab }) => {
  const { link } = await lab.formPage(
    lab.definition(contacto, {
      style: {
        label_color: '#112233',
        field_color: '#223344',
        field_background: '#f0f0f0',
        border_color: '#445566',
        focus_color: '#ff00aa',
        button_background: '#0055aa',
        button_color: '#fefefe',
        gap: 30,
        border_width: 3,
        border_radius: 12,
        field_padding: 20,
      },
    }),
  );
  const page = await lab.visitor();

  await page.goto(link);

  const control = page.getByLabel('Nombre');
  const boton = page.getByRole('button', { name: 'Enviar' });

  await expect(page.locator('.fm-label').first()).toHaveCSS('color', 'rgb(17, 34, 51)');
  await expect(control).toHaveCSS('color', 'rgb(34, 51, 68)');
  await expect(control).toHaveCSS('background-color', 'rgb(240, 240, 240)');
  await expect(control).toHaveCSS('border-top-color', 'rgb(68, 85, 102)');
  await expect(control).toHaveCSS('border-top-width', '3px');
  await expect(control).toHaveCSS('border-top-left-radius', '12px');
  // El relleno es algo mayor a los lados que arriba y abajo.
  await expect(control).toHaveCSS('padding-top', '20px');
  await expect(control).toHaveCSS('padding-left', '25px');
  await expect(page.getByLabel('Mensaje')).toHaveCSS('border-top-width', '3px');
  await expect(page.locator('form.fm-form')).toHaveCSS('row-gap', '30px');
  await expect(boton).toHaveCSS('background-color', 'rgb(0, 85, 170)');
  await expect(boton).toHaveCSS('color', 'rgb(254, 254, 254)');
  await expect(page.locator('.formulario-mensaje')).toHaveClass(/fm-button-styled/);

  // El color de foco, al llegar con el teclado.
  await page.keyboard.press('Tab');
  await control.focus();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Correo')).toBeFocused();
  await expect(page.getByLabel('Correo')).toHaveCSS('outline-color', 'rgb(255, 0, 170)');

  // El estilo no estorba al envío.
  await control.fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await send(page);
});

test('sin estilo propio el formulario hereda del tema, y el bloque puede cambiarlo solo en su página', async ({ lab }) => {
  const sinEstilo = await lab.formPage(lab.definition(contacto), 'shortcode', 'Sin estilo');
  const formId = await lab.form(lab.definition(contacto, { style: { label_color: '#aa0000', gap: 40, button_background: '#0055aa' } }), 'Con estilo');
  const conShortcode = await lab.page(`<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`, 'Shortcode');
  const conBloque = await lab.page(
    `<!-- wp:formulario-mensaje/form {"formId":${formId},"formStyle":{"gap":8,"button_background":"#008800","label_color":"#000;} body{display:none"}} /-->`,
    'Bloque',
  );
  const page = await lab.visitor();

  // Sin estilo: ni atributo de estilo ni clase de botón, y la letra es la del tema.
  await page.goto(sinEstilo.link);
  await expect(page.locator('.formulario-mensaje')).not.toHaveClass(/fm-button-styled/);
  expect(await page.locator('.formulario-mensaje').getAttribute('style')).toBeNull();

  const letra = await page.evaluate(() => ({
    cuerpo: getComputedStyle(document.body).fontFamily,
    etiqueta: getComputedStyle(document.querySelector('.fm-label')!).fontFamily,
    control: getComputedStyle(document.querySelector('.fm-control')!).fontFamily,
  }));

  expect(letra.etiqueta).toBe(letra.cuerpo);
  expect(letra.control).toBe(letra.cuerpo);

  // Con el shortcode manda el estilo del formulario.
  await page.goto(conShortcode);
  await expect(page.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
  await expect(page.locator('form.fm-form')).toHaveCSS('row-gap', '40px');
  await expect(page.getByRole('button', { name: 'Enviar' })).toHaveCSS('background-color', 'rgb(0, 85, 170)');

  // En el bloque mandan sus ajustes; lo que no ajusta sigue siendo del formulario, y un valor que no es un color se ignora.
  await page.goto(conBloque);
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('form.fm-form')).toHaveCSS('row-gap', '8px');
  await expect(page.getByRole('button', { name: 'Enviar' })).toHaveCSS('background-color', 'rgb(0, 136, 0)');
  await expect(page.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
  expect(await page.content()).not.toContain('display:none"');
});

test('el estilo solo admite colores y medidas válidos, y las clases CSS solo nombres de clase', async ({ lab }) => {
  const definicion = lab.definition(
    [
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', css_class: 'destacado  campo-2 "><script>alert(1)</script> a{b}' } },
      { id: 'raya', type: 'divider', settings: { css_class: 'separador-fino' } },
    ],
    {
      style: {
        label_color: 'red',
        field_color: '#12',
        border_color: '#abcdef; background:url(//evil.example/x)',
        field_background: '#ABCDEF',
        focus_color: 'rgb(0,0,0)',
        button_background: 'javascript:alert(1)',
        gap: 9999,
        border_width: -5,
        border_radius: 'grande',
        field_padding: '12',
        hacker: 'expression(alert(1))',
      } as Record<string, unknown>,
    },
  );
  const formId = await lab.form(definicion);
  const guardada = await lab.api.getForm(formId);

  // Lo válido, normalizado y dentro de sus límites; lo demás, fuera.
  expect(guardada.definition.style).toEqual({ field_background: '#abcdef', gap: 64, border_width: 0, field_padding: 12 });

  const link = await lab.page(`<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`);
  const page = await lab.visitor();
  const dialogos: string[] = [];

  page.on('dialog', (dialog) => {
    dialogos.push(dialog.message());
    void dialog.dismiss();
  });

  await page.goto(link);

  const html = await page.locator('.formulario-mensaje').evaluate((node) => node.outerHTML);

  expect(html).not.toContain('evil.example');
  expect(html).not.toContain('expression');
  expect(html).not.toContain('<script');
  await expect(page.locator('[data-fm-field="nombre"]')).toHaveClass(/(^| )destacado( |$)/);
  await expect(page.locator('[data-fm-field="nombre"]')).toHaveClass(/(^| )campo-2( |$)/);
  await expect(page.locator('.fm-field--divider')).toHaveClass(/separador-fino/);
  await expect(page.locator('form.fm-form')).toHaveCSS('row-gap', '64px');
  await expect(page.getByLabel('Nombre')).toHaveCSS('border-top-width', '0px');
  expect(dialogos).toEqual([]);
});

// --- Antispam -----------------------------------------------------------------------------------

test('el campo trampa y el token cierran el paso a quien no usa el formulario como una persona', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(contacto));
  const otro = await lab.form(lab.definition(contacto), 'Otro');
  const datos = { nombre: 'Bot', correo: 'bot@example.test' };

  // Sin token, con uno inventado, con el de otro formulario y enviando en cuanto se obtiene.
  const sinToken = await lab.restSubmit(formId, datos, { token: '' });
  const inventado = await lab.restSubmit(formId, datos, { token: 'abc.def.ghi' });
  const ajeno = await lab.restSubmit(formId, datos, { token: await lab.token(otro) });
  const deprisa = await lab.restSubmit(formId, datos, { waitMs: 0 });

  for (const [caso, respuesta] of Object.entries({ sinToken, inventado, ajeno, deprisa })) {
    expect(respuesta.status, caso).toBe(400);
    expect(['formulario_mensaje_token_invalid', 'formulario_mensaje_token_expired', 'formulario_mensaje_too_fast'], caso).toContain(respuesta.body.code);
  }

  // El campo trampa relleno, aunque todo lo demás esté bien.
  const trampa = await lab.restSubmit(formId, datos, { honeypot: 'https://spam.example' });

  expect(trampa.status).toBe(400);

  expect(await lab.submissions(formId)).toHaveLength(0);
  await lab.expectNoMail(lab.mailTo, 1_000);

  // El campo trampa no lo ve ni lo alcanza una persona, ni con el ratón ni con el teclado ni con un lector de pantalla.
  const page = await lab.visitor();

  await page.goto(link);

  const cebo = page.locator('input[name="fm_website"]');

  await expect(cebo).not.toBeInViewport();
  await expect(cebo).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.fm-hp')).toHaveAttribute('aria-hidden', 'true');

  // Un programa que rellena todos los campos que encuentra cae en él, y el visitante real ve un error, no un falso éxito.
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await cebo.evaluate((input: HTMLInputElement) => {
    input.value = 'relleno por un bot';
  });
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-status')).toHaveText('No se ha podido enviar tu mensaje. Recarga la página e inténtalo de nuevo.', { timeout: 20_000 });
  await expect(page.locator('.fm-success')).toBeHidden();
  await lab.expectNoMail(lab.mailTo, 1_000);
});

test('el límite de envíos por visitante corta el exceso con un aviso claro, formulario a formulario', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(contacto, { actions: [] }), 'shortcode', 'Con límite');
  const otro = await lab.form(lab.definition(contacto, { actions: [] }), 'Otro');
  const datos = { nombre: 'Ana', correo: 'ana@example.test' };

  // Cinco envíos en diez minutos, y ni uno más.
  for (let intento = 1; intento <= 5; intento++) {
    expect((await lab.restSubmit(formId, datos)).status, `envío ${intento}`).toBe(200);
  }

  const sexto = await lab.restSubmit(formId, datos);

  expect(sexto.status).toBe(429);
  expect(sexto.body.message).toBe('Has enviado demasiados mensajes. Inténtalo de nuevo dentro de unos minutos.');
  expect(await lab.submissions(formId)).toHaveLength(5);

  // El visitante lo ve en el formulario, y sus datos siguen ahí para cuando pueda enviar.
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-status')).toHaveText('Has enviado demasiados mensajes. Inténtalo de nuevo dentro de unos minutos.', { timeout: 20_000 });
  await expect(page.getByLabel('Nombre')).toHaveValue('Ana');

  // El límite es de cada formulario: otro sigue aceptando.
  expect((await lab.restSubmit(otro, datos)).status).toBe(200);
});

test('el envío sin JavaScript tiene su propio límite, más estricto', async ({ lab }) => {
  const formId = await lab.form(lab.definition(contacto, { actions: [] }));
  const datos = { nombre: 'Ana', correo: 'ana@example.test' };

  expect((await lab.postWithoutJs(formId, datos)).status).toBe(303);
  expect((await lab.postWithoutJs(formId, datos)).status).toBe(303);

  const tercero = await lab.postWithoutJs(formId, datos);

  expect(tercero.status).toBe(429);
  expect(tercero.text).toContain('Has enviado demasiados mensajes.');
  expect(await lab.submissions(formId)).toHaveLength(2);

  // Con JavaScript, ese mismo visitante todavía puede enviar: son límites distintos.
  expect((await lab.restSubmit(formId, datos)).status).toBe(200);
});
