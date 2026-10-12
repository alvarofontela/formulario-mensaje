import { env } from '../e2e/support/env';
import { FormField } from '../e2e/support/forms';
import { expect, fieldError, files, options, send, test, when } from './support/lab';

// Combinaciones que dependen de ajustes de todo el sitio: captcha, envío sin JavaScript, transporte
// de correo y Elementor. Corren de una en una, después de los tests en paralelo, y dejan los ajustes
// como estaban.

const contacto: FormField[] = [
  { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
  { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
];

/** Doble de Turnstile: una casilla que entrega el testigo que se le pida al pulsar su botón. */
const fakeTurnstile = `
window.turnstile = {
	render( slot, options ) {
		slot.innerHTML = '<button type="button" data-token="rt-valido">Soy una persona</button>'
			+ '<button type="button" data-token="rt-falso">Testigo falso</button>';
		slot.addEventListener( 'click', ( event ) => {
			if ( event.target.dataset.token ) {
				options.callback( event.target.dataset.token );
			}
		} );
		options[ 'before-interactive-callback' ]();
		return 'rt-widget';
	},
	reset() {},
};`;

test.describe('con captcha en el sitio', () => {
  test.beforeEach(async ({ lab }) => {
    await lab.api.saveCaptchaSettings({ provider: 'turnstile', site_key: 'clave-publica-matriz' }, 'clave-secreta');
  });

  test.afterEach(async ({ lab }) => {
    await lab.api.saveCaptchaSettings({ provider: '' });
  });

  test('el captcha convive con pasos, condiciones y ficheros, y cada formulario decide si lo lleva', async ({ lab }) => {
    const protegido = await lab.formPage(
      lab.definition([
        { id: 'p1', type: 'step', settings: { label: 'Datos' } },
        ...contacto,
        { id: 'tipo', type: 'radio', settings: { label: 'Tipo', required: true, options: options('Particular', 'Empresa') } },
        { id: 'p2', type: 'step', settings: { label: 'Empresa' }, condition: when('tipo', 'equals', 'Empresa') },
        { id: 'razon', type: 'text', settings: { label: 'Razón social', required: true } },
        { id: 'p3', type: 'step', settings: { label: 'Documentos' } },
        { id: 'doc', type: 'file', settings: { label: 'Documento', required: true } },
      ]),
      'shortcode',
      'Protegido',
    );
    const libre = await lab.formPage(lab.definition(contacto, { settings: { captcha_enabled: false } }), 'shortcode', 'Sin captcha');
    const page = await lab.visitor();
    const terceros: string[] = [];

    await page.context().route('https://challenges.cloudflare.com/**', (route) => {
      terceros.push(route.request().url());

      return route.fulfill({ contentType: 'application/javascript', body: fakeTurnstile });
    });

    // El formulario que renuncia al captcha ni lo carga ni lo exige.
    await page.goto(libre.link);
    await expect(page.locator('form.fm-form')).not.toHaveAttribute('data-fm-captcha', /.+/);
    await page.getByLabel('Nombre').fill('Ana');
    await page.getByLabel('Correo').fill('ana@example.test');
    await send(page);
    expect(terceros).toHaveLength(0);

    // El protegido: tres pasos, con el captcha junto al botón de enviar.
    await page.goto(protegido.link);
    await page.getByLabel('Nombre').fill('Luis');
    await page.getByLabel('Correo').fill('luis@example.test');
    await page.getByLabel('Empresa', { exact: true }).check();
    await page.getByRole('button', { name: 'Siguiente' }).click();
    await page.getByLabel('Razón social').fill('ACME');
    await page.getByRole('button', { name: 'Siguiente' }).click();
    await page.getByLabel('Documento').setInputFiles([files.pdf()]);

    // Sin resolverlo no se envía, y se dice por qué.
    await page.getByRole('button', { name: 'Enviar' }).click();
    await expect(page.locator('.fm-status')).toHaveText('Completa la comprobación de seguridad antes de enviar el formulario.', { timeout: 20_000 });
    expect(await lab.submissions(protegido.formId)).toHaveLength(0);

    // Con un testigo que el servicio rechaza, tampoco.
    await page.locator('.fm-captcha').getByRole('button', { name: 'Testigo falso' }).click();
    await page.getByRole('button', { name: 'Enviar' }).click();
    await expect(page.locator('.fm-status')).toHaveText('La comprobación de seguridad ha fallado. Inténtalo de nuevo.', { timeout: 20_000 });

    // Resuelto, sale con todo: pasos, condición y fichero.
    await page.locator('.fm-captcha').getByRole('button', { name: 'Soy una persona' }).click();
    await send(page);

    const guardado = await lab.lastSubmission(protegido.formId);

    expect(guardado.entries.map((entry) => entry.field_id)).toEqual(['nombre', 'correo', 'tipo', 'razon', 'doc']);
    expect(guardado.files.map((file) => file.name)).toEqual(['informe.pdf']);

    // Sin JavaScript no hay forma de resolver un captcha: el protegido lo dice y el libre sigue aceptando.
    const sinJs = await lab.postWithoutJs(protegido.formId, { nombre: 'Bot', correo: 'bot@example.test', tipo: 'Particular' });

    expect(sinJs.status).toBe(400);
    expect(sinJs.text).toContain('Este formulario necesita JavaScript para enviarse.');
    expect((await lab.postWithoutJs(libre.formId, { nombre: 'Eva', correo: 'eva@example.test' })).status).toBe(303);

    // Un programa que salta el navegador tampoco entra sin testigo.
    const directo = await lab.restSubmit(protegido.formId, { nombre: 'Bot', correo: 'bot@example.test', tipo: 'Particular' });

    expect(directo.status).toBe(400);
    expect(directo.body.code).toBe('formulario_mensaje_captcha_missing');
    expect(await lab.submissions(protegido.formId)).toHaveLength(1);
  });
});

test.describe('con el envío sin JavaScript desactivado', () => {
  test.beforeEach(async ({ lab }) => {
    await lab.api.saveSettings({ accept_no_js: false });
  });

  test.afterEach(async ({ lab }) => {
    await lab.api.saveSettings({ accept_no_js: true });
  });

  test('el formulario sin JavaScript explica que lo necesita, y con JavaScript funciona igual', async ({ lab }) => {
    const { formId, link } = await lab.formPage(lab.definition(contacto));
    const sinJs = await lab.visitor({ javaScriptEnabled: false });

    await sinJs.goto(link);
    await sinJs.getByLabel('Nombre').fill('Ana');
    await sinJs.getByLabel('Correo').fill('ana@example.test');
    await sinJs.getByRole('button', { name: 'Enviar' }).click();
    await expect(sinJs.locator('body')).toContainText('Este formulario necesita JavaScript para enviarse. Actívalo en tu navegador e inténtalo de nuevo.');
    expect(await lab.submissions(formId)).toHaveLength(0);

    const conJs = await lab.visitor();

    await conJs.goto(link);
    await conJs.getByLabel('Nombre').fill('Ana');
    await conJs.getByLabel('Correo').fill('ana@example.test');
    await send(conJs);
    expect(await lab.submissions(formId)).toHaveLength(1);
  });
});

test.describe('con SMTP como transporte de correo', () => {
  test.beforeEach(async ({ lab }) => {
    await lab.api.saveMailSettings({
      transport: 'smtp',
      smtp_host: '127.0.0.1',
      smtp_port: env.mailpit.smtpPort,
      smtp_encryption: 'none',
      smtp_auth: false,
      from_email: 'formularios@example.test',
      from_name: 'Matriz <de> pruebas',
      brand_color: '#ff6600',
      brand_footer: 'Empresa S.L. · a<b',
    });
  });

  test.afterEach(async ({ lab }) => {
    await lab.api.saveMailSettings({});
  });

  test('las notificaciones salen por SMTP con su remitente, su marca, sus adjuntos y varias a la vez', async ({ lab }) => {
    const formId = await lab.form(
      lab.definition(
        [...contacto, { id: 'doc', type: 'file', settings: { label: 'Documento', max_files: '2' } }],
        {
          actions: [
            lab.email({ format: 'html', subject: 'HTML de {field:nombre}', reply_to_field: 'correo' }, { id: 'html' }),
            lab.email({ to: [lab.address('texto')], format: 'text', subject: 'Texto de {field:nombre}' }, { id: 'texto' }),
          ],
        },
      ),
    );
    const respuesta = await lab.restSubmitFiles(formId, { nombre: 'Ana <3', correo: 'ana@example.test' }, { doc: [files.pdf('uno.pdf'), files.png('dos.png')] });

    expect(respuesta.status, JSON.stringify(respuesta.body)).toBe(200);

    const [html] = await lab.mail();
    const [texto] = await lab.mail(lab.address('texto'));

    for (const mail of [html, texto]) {
      expect(mail.Attachments.map((attachment) => attachment.FileName).sort()).toEqual(['dos.png', 'uno.pdf']);
    }

    expect(html.Subject).toBe('HTML de Ana <3');
    expect(html.ReplyTo.map((to) => to.Address)).toEqual(['ana@example.test']);
    expect(html.HTML).toContain('#ff6600');
    expect(html.HTML).toContain('Empresa S.L. · a&lt;b');
    expect(html.HTML).toContain('Ana &lt;3');
    expect(texto.Text).toContain('Nombre: Ana <3');

    // El remitente es el de los ajustes, nunca el visitante.
    const resumen = (await lab.mailsTo())[0];

    expect(resumen.From.Address).toBe('formularios@example.test');

    const guardado = await lab.lastSubmission(formId);

    expect(guardado.deliveries.map((delivery) => delivery.status)).toEqual(['success', 'success']);
  });
});

test.describe('con Elementor activo', () => {
  test.beforeAll(async ({ browser }, info) => {
    const { Lab } = await import('./support/lab');
    const lab = await Lab.create(browser, info);

    await lab.api.setPluginStatus('elementor/elementor', 'active');
    await lab.dispose();
  });

  test.afterAll(async ({ browser }, info) => {
    const { Lab } = await import('./support/lab');
    const lab = await Lab.create(browser, info);

    await lab.api.setPluginStatus('elementor/elementor', 'inactive');
    await lab.dispose();
  });

  test('el widget muestra un formulario con pasos, condición y fichero, y el resto del sitio sigue igual', async ({ lab }) => {
    test.setTimeout(180_000);

    const formId = await lab.form(
      lab.definition([
        { id: 'p1', type: 'step', settings: { label: 'Datos' } },
        ...contacto,
        { id: 'tipo', type: 'select', settings: { label: 'Tipo', required: true, options: options('Particular', 'Empresa') } },
        { id: 'razon', type: 'text', settings: { label: 'Razón social', required: true }, condition: when('tipo', 'equals', 'Empresa') },
        { id: 'p2', type: 'step', settings: { label: 'Documentos' } },
        { id: 'doc', type: 'file', settings: { label: 'Documento', required: true } },
      ]),
      'En Elementor',
    );
    const pagina = await lab.api.createPage(`Elementor ${lab.id}`, '');
    const widget = (id: string) => ({
      id,
      elType: 'widget',
      widgetType: 'formulario-mensaje',
      settings: { form_id: String(formId), label_color: '#aa0000', gap: { unit: 'px', size: 14 } },
      elements: [],
    });
    const guardada = await (
      await fetch(`${env.baseUrl}/wp-json/rt/v1/elementor-page`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          post_id: pagina.id,
          elements: [
            {
              id: 'fm5ec0a1',
              elType: 'section',
              settings: {},
              isInner: false,
              elements: [{ id: 'fmc010a1', elType: 'column', settings: { _column_size: 100 }, isInner: false, elements: [widget('fma1d0a1')] }],
            },
          ],
        }),
      })
    ).json();

    expect(guardada.elementor, 'Elementor está activo en el entorno.').toBeTruthy();

    const page = await lab.visitor();

    await page.goto(pagina.link);

    const formulario = page.locator('.elementor-widget-formulario-mensaje');

    await expect(formulario.locator('form.fm-form')).toBeVisible();
    await expect(formulario.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
    await expect(formulario.locator('.fm-step').first()).toHaveCSS('row-gap', '14px');
    await expect(formulario.locator('.fm-progress__text')).toHaveText('Paso 1 de 2: Datos');

    await formulario.getByLabel('Nombre').fill('Ana');
    await formulario.getByLabel('Correo').fill('ana@example.test');
    await formulario.getByRole('button', { name: 'Siguiente' }).click();
    await expect(fieldError(page, 'tipo')).toHaveText('Este campo es obligatorio.');
    await formulario.getByLabel('Tipo').selectOption('Empresa');
    await expect(formulario.getByLabel('Razón social')).toBeVisible();
    await formulario.getByLabel('Razón social').fill('ACME');
    await formulario.getByRole('button', { name: 'Siguiente' }).click();
    await formulario.getByLabel('Documento').setInputFiles([files.pdf()]);
    await formulario.getByRole('button', { name: 'Enviar' }).click();
    await expect(formulario.locator('.fm-success')).toBeVisible({ timeout: 20_000 });

    const guardado = await lab.lastSubmission(formId);

    expect(guardado.entries.map((entry) => entry.field_id)).toEqual(['nombre', 'correo', 'tipo', 'razon', 'doc']);
    expect((await lab.mail())[0].Attachments.map((attachment) => attachment.FileName)).toEqual(['informe.pdf']);

    // Con Elementor activo, el mismo formulario sigue funcionando por shortcode en una página normal.
    const normal = await lab.page(`<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`, 'Normal');

    await page.goto(normal);
    await expect(page.locator('form.fm-form')).toBeVisible();
    await expect(page.locator('.fm-progress__text')).toHaveText('Paso 1 de 2: Datos');
  });
});
