import { FormField } from '../e2e/support/forms';
import { expect, send, test } from './support/lab';

// Lo que ve el visitante al enviar: mensaje, vaciado, redirección y medición; y cómo conviven
// varios formularios en una misma página.

const contacto: FormField[] = [
  { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
  { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
];

test('el mensaje de éxito admite formato básico y datos del envío, y sustituye al formulario', async ({ lab }) => {
  const { link } = await lab.formPage(
    lab.definition(contacto, {
      settings: {
        success_message:
          '<strong>¡Gracias, {field:nombre}!</strong> Tu referencia es {reference}. <a href="/aviso-legal/">Aviso legal</a><script>window.colado = 1;</script><img src=x onerror="window.colado = 2">',
      },
    }),
  );
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await send(page);

  const exito = page.locator('.fm-success');

  await expect(exito).toHaveText(/^¡Gracias, Ana! Tu referencia es [A-Z2-9]{4}-[A-Z2-9]{4}\. Aviso legal/);
  await expect(exito.locator('strong')).toHaveText('¡Gracias, Ana!');
  await expect(exito.locator('a')).toHaveAttribute('href', '/aviso-legal/');
  // Solo el formato básico: lo demás que escribiese quien configuró el formulario se quitó al guardarlo.
  await expect(exito.locator('script, img')).toHaveCount(0);
  expect(await page.evaluate(() => (window as unknown as { colado?: number }).colado)).toBeUndefined();

  await expect(page.locator('form.fm-form')).toBeHidden();
  await expect(exito).toBeFocused();
  await expect(exito).toHaveAttribute('role', 'status');
});

test('con «vaciar el formulario» se puede enviar otra vez sin recargar', async ({ lab }) => {
  const { formId, link } = await lab.formPage(
    lab.definition(
      [
        ...contacto,
        { id: 'origen', type: 'hidden', settings: { label: 'Origen', source: 'query', param: 'ref' } },
        { id: 'tema', type: 'text', settings: { label: 'Tema', prefill_source: 'fixed', prefill_value: 'Consulta' } },
      ],
      { settings: { after_submit: 'reset', success_message: 'Recibido: {reference}' } },
    ),
  );
  const page = await lab.visitor();

  await page.goto(`${link}?ref=campaña`);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await send(page);

  // El formulario sigue ahí, vacío, con sus valores de partida y su contexto.
  await expect(page.locator('form.fm-form')).toBeVisible();
  await expect(page.getByLabel('Nombre')).toHaveValue('');
  await expect(page.getByLabel('Tema')).toHaveValue('Consulta');

  const primera = await page.locator('.fm-success').textContent();

  await page.getByLabel('Nombre').fill('Luis');
  await page.getByLabel('Correo').fill('luis@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).not.toHaveText(primera!, { timeout: 20_000 });

  const correos = await lab.mail(lab.mailTo, 2);

  expect(correos[0].Text).toContain('Nombre: Ana');
  expect(correos[1].Text).toContain('Nombre: Luis');
  // El contexto de la página viaja también en el segundo envío.
  expect(correos[1].Text).toContain('Origen: campaña');
  expect(await lab.submissions(formId)).toHaveLength(2);
});

test('la redirección lleva a la página de gracias con los campos elegidos, con y sin JavaScript', async ({ lab }) => {
  const destino = await lab.page('<!-- wp:paragraph --><p>Gracias por escribir.</p><!-- /wp:paragraph -->', 'Gracias');
  const ruta = new URL(destino).pathname;
  const { formId, link } = await lab.formPage(
    lab.definition([...contacto, { id: 'plan', type: 'text', settings: { label: 'Plan' } }], {
      settings: {
        redirect_enabled: true,
        redirect_url: ruta,
        redirect_fields: [
          { field: 'nombre', param: 'cliente' },
          { field: 'plan', param: 'plan' },
        ],
      },
    }),
  );

  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana María & Cía');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Plan').fill('pro/anual');
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page).toHaveURL(new RegExp(`${ruta}\\?`), { timeout: 20_000 });
  await expect(page.getByText('Gracias por escribir.')).toBeVisible();

  const params = new URL(page.url()).searchParams;

  expect(params.get('cliente')).toBe('Ana María & Cía');
  expect(params.get('plan')).toBe('pro/anual');
  // El correo no va en la dirección: no se pidió.
  expect(page.url()).not.toContain('example.test');

  // Sin JavaScript, la misma redirección la hace el servidor.
  const sinJs = await lab.postWithoutJs(formId, { nombre: 'Luis', correo: 'luis@example.test', plan: '' });

  expect(sinJs.status).toBe(303);

  const sinJsUrl = new URL(sinJs.location);

  expect(sinJsUrl.pathname).toBe(ruta);
  expect(sinJsUrl.searchParams.get('cliente')).toBe('Luis');
  await lab.mail(lab.mailTo, 2);
});

test('una redirección a otro dominio no se puede guardar, y a una dirección que no es web tampoco', async ({ lab }) => {
  for (const destino of ['https://evil.example/gracias', '//evil.example/gracias', 'javascript:alert(1)']) {
    const respuesta = await lab.api.attempt('post', '/formulario-mensaje/v1/forms', {
      title: `Redirección ${lab.id}`,
      definition: lab.definition(contacto, { settings: { redirect_enabled: true, redirect_url: destino } }),
    });

    expect(respuesta.status, destino).toBe(400);
    expect(respuesta.body.code, destino).toBe('formulario_mensaje_redirect_not_allowed');
  }

  // Con la redirección apagada, la dirección da igual: no se usa.
  const apagada = await lab.api.attempt('post', '/formulario-mensaje/v1/forms', {
    title: `Redirección apagada ${lab.id}`,
    definition: lab.definition(contacto, { settings: { redirect_enabled: false, redirect_url: 'https://evil.example/gracias' } }),
  });

  expect(apagada.status).toBe(201);
});

test('la medición avisa en dataLayer solo si está activada, y el evento del formulario sale siempre', async ({ lab }) => {
  const medido = await lab.formPage(lab.definition(contacto), 'shortcode', 'Con medición');
  const sinMedir = await lab.formPage(lab.definition(contacto, { settings: { tracking_enabled: false } }), 'shortcode', 'Sin medición');

  const enviar = async (link: string) => {
    const page = await lab.visitor();

    await page.goto(link);
    await page.evaluate(() => {
      document.addEventListener('formulario-mensaje:submitted', (event) => {
        (window as unknown as { fmEvento: unknown }).fmEvento = (event as CustomEvent).detail;
      });
    });
    await page.getByLabel('Nombre').fill('Ana');
    await page.getByLabel('Correo').fill('ana@example.test');
    await send(page);

    return page.evaluate(() => {
      const scope = window as unknown as { dataLayer?: unknown[]; fmEvento?: { formId: number; reference: string } };

      return { dataLayer: scope.dataLayer ?? [], evento: scope.fmEvento };
    });
  };

  const [conMedicion, sinMedicion] = await Promise.all([enviar(medido.link), enviar(sinMedir.link)]);

  expect(conMedicion.dataLayer).toEqual([{ event: 'formulario_mensaje_submit', form_id: medido.formId, form_name: `Con medición ${lab.id}` }]);
  expect(conMedicion.evento).toMatchObject({ formId: medido.formId });
  expect(conMedicion.evento!.reference).toMatch(/^[A-Z2-9]{4}-[A-Z2-9]{4}$/);
  // Ningún dato del visitante sale hacia las herramientas de medición.
  expect(JSON.stringify(conMedicion)).not.toContain('ana@example.test');

  expect(sinMedicion.dataLayer).toEqual([]);
  expect(sinMedicion.evento).toMatchObject({ formId: sinMedir.formId });
});

test('varios formularios en una página, y el mismo dos veces, no se mezclan', async ({ lab }) => {
  const uno = await lab.form(lab.definition(contacto, { actions: [lab.email({ to: [lab.address('uno')] })] }), 'Uno');
  const dos = await lab.form(
    lab.definition([{ id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } }], { actions: [lab.email({ to: [lab.address('dos')] })] }),
    'Dos',
  );
  const link = await lab.page(
    [
      `<!-- wp:shortcode -->[formulario_mensaje id="${uno}"]<!-- /wp:shortcode -->`,
      `<!-- wp:formulario-mensaje/form {"formId":${dos}} /-->`,
      `<!-- wp:shortcode -->[formulario_mensaje id="${uno}"]<!-- /wp:shortcode -->`,
    ].join('\n\n'),
  );
  const page = await lab.visitor();

  await page.goto(link);

  const formularios = page.locator('.formulario-mensaje');

  await expect(formularios).toHaveCount(3);

  // Cada inserción tiene sus propios identificadores: ninguno se repite en la página.
  const ids = await page.locator('.formulario-mensaje [id]').evaluateAll((nodes) => nodes.map((node) => node.id));

  expect(new Set(ids).size).toBe(ids.length);

  // Un error en el primero no toca a los demás.
  await formularios.nth(0).getByRole('button', { name: 'Enviar' }).click();
  await expect(formularios.nth(0).locator('[data-fm-field="nombre"] .fm-error')).toHaveText('Este campo es obligatorio.');
  await expect(formularios.nth(1).locator('.fm-error:visible')).toHaveCount(0);
  await expect(formularios.nth(2).locator('.fm-error:visible')).toHaveCount(0);

  // Se envía el segundo: solo él da las gracias y solo su destinatario recibe correo.
  await formularios.nth(1).getByLabel('Nombre').fill('Para el dos');
  await formularios.nth(1).getByRole('button', { name: 'Enviar' }).click();
  await expect(formularios.nth(1).locator('.fm-success')).toBeVisible({ timeout: 20_000 });
  await expect(formularios.nth(0).locator('.fm-success')).toBeHidden();
  await expect(formularios.nth(2).locator('.fm-success')).toBeHidden();

  // Y la segunda copia del primero, con sus propios datos.
  await formularios.nth(2).getByLabel('Nombre').fill('Para el uno');
  await formularios.nth(2).getByLabel('Correo').fill('uno@example.test');
  await formularios.nth(2).getByRole('button', { name: 'Enviar' }).click();
  await expect(formularios.nth(2).locator('.fm-success')).toBeVisible({ timeout: 20_000 });
  await expect(formularios.nth(0).locator('form.fm-form')).toBeVisible();

  expect((await lab.mail(lab.address('dos')))[0].Text).toContain('Nombre: Para el dos');
  expect((await lab.mail(lab.address('uno')))[0].Text).toContain('Nombre: Para el uno');
  expect(await lab.mailsTo(lab.address('uno'))).toHaveLength(1);
});

test('un formulario en la papelera o que no existe deja de mostrarse y de aceptar envíos', async ({ lab }) => {
  const { formId, link } = await lab.formPage(lab.definition(contacto));
  const inexistente = await lab.page('<!-- wp:shortcode -->[formulario_mensaje id="99999999"]<!-- /wp:shortcode -->', 'Sin formulario');
  const page = await lab.visitor();

  await page.goto(link);
  await expect(page.locator('form.fm-form')).toBeVisible();

  await lab.api.call('delete', `/formulario-mensaje/v1/forms/${formId}`);

  // La página que el visitante tenía abierta ya no puede enviar.
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-status')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('.fm-success')).toBeHidden();

  // Al recargar, el visitante no ve nada; quien gestiona los formularios, un aviso.
  await page.reload();
  await expect(page.locator('.formulario-mensaje, .fm-unavailable')).toHaveCount(0);
  await page.goto(inexistente);
  await expect(page.locator('.formulario-mensaje, .fm-unavailable')).toHaveCount(0);

  const admin = await lab.admin();

  await admin.goto(link);
  await expect(admin.locator('.fm-unavailable')).toHaveText('Este formulario no existe o está en la papelera.');

  expect((await lab.restSubmit(formId, { nombre: 'Ana', correo: 'ana@example.test' })).status).toBe(404);
  expect((await lab.postWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' })).status).toBe(404);
  await lab.expectNoMail(lab.mailTo, 1_000);

  // Restaurado, vuelve a funcionar.
  await lab.api.call('post', `/formulario-mensaje/v1/forms/${formId}/restore`);
  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await send(page);
  await lab.mail();
});
