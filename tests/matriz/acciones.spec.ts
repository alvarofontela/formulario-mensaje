import { createHmac } from 'node:crypto';
import { FormField } from '../e2e/support/forms';
import { expect, options, send, test, when } from './support/lab';

// Qué hace cada formulario con un envío: combinaciones de notificaciones, webhooks y guardado.

const contacto: FormField[] = [
  { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
  { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
  { id: 'departamento', type: 'select', settings: { label: 'Departamento', options: options('Ventas', 'Soporte', 'Otro') } },
  { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
];

const referencia = /[A-Z2-9]{4}-[A-Z2-9]{4}/;

test('varias notificaciones a la vez: formato, destinatarios, etiquetas en el asunto y dirección de respuesta', async ({ lab }) => {
  const { formId, link } = await lab.formPage(
    lab.definition(contacto, {
      actions: [
        lab.email(
          {
            to: [lab.address('uno'), lab.address('dos')],
            format: 'html',
            subject: 'Aviso {reference} de {field:nombre} en {site_name}',
            body: 'Nuevo mensaje del formulario {form_title}:\n\n{all_fields}\n\nEnviado desde {page_url} el {date}.',
            reply_to_field: 'correo',
          },
          { id: 'equipo', name: 'Al equipo' },
        ),
        lab.email({ to: [lab.address('texto')], format: 'text', subject: 'Copia en texto', body: 'Dice {field:nombre}: {field:mensaje}' }, { id: 'copia' }),
        lab.email({ to: [lab.address('apagada')] }, { id: 'apagada', enabled: false }),
      ],
    }),
    'shortcode',
    'Avisos',
  );
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Mensaje').fill('Hola\nequipo');
  await send(page);

  // Un solo correo para los dos destinatarios, con la dirección del visitante para responder.
  const [equipo] = await lab.mail(lab.address('uno'));

  expect(equipo.To.map((to) => to.Address).sort()).toEqual([lab.address('dos'), lab.address('uno')].sort());
  expect(equipo.ReplyTo.map((to) => to.Address)).toEqual(['ana@example.test']);
  expect(equipo.Subject).toMatch(new RegExp(`^Aviso ${referencia.source} de Ana en Formulario Mensaje \\(dev\\)$`));
  expect(equipo.HTML).toContain(`Nuevo mensaje del formulario Avisos ${lab.id}:`);
  expect(equipo.HTML).toMatch(/<th[^>]*>Nombre<\/th><td[^>]*>Ana<\/td>/);
  expect(equipo.HTML).toMatch(/Hola<br ?\/?>\s*equipo/);
  expect(equipo.HTML).toContain(link);
  // El correo HTML lleva también su versión en texto, para los clientes que no muestran HTML.
  expect(equipo.Text).toContain('Nombre: Ana');
  expect(await lab.mailsTo(lab.address('dos'))).toHaveLength(1);

  const [copia] = await lab.mail(lab.address('texto'));

  expect(copia.Subject).toBe('Copia en texto');
  expect(copia.Text.replace(/\r/g, '').trim()).toBe('Dice Ana: Hola\nequipo');
  expect(copia.HTML).toBe('');
  expect(copia.ReplyTo).toEqual([]);

  // La notificación apagada ni se envía ni cuenta como entrega.
  await lab.expectNoMail(lab.address('apagada'), 500);

  const guardado = await lab.lastSubmission(formId);

  expect(guardado.deliveries.map((delivery) => [delivery.action_id, delivery.status]).sort()).toEqual([
    ['copia', 'success'],
    ['equipo', 'success'],
  ]);
});

test('las notificaciones condicionales salen solo cuando se cumple su condición', async ({ lab }) => {
  const formId = await lab.form(
    lab.definition(contacto, {
      actions: [
        lab.email({ to: [lab.address('ventas')] }, { id: 'ventas', condition: when('departamento', 'equals', 'Ventas') }),
        lab.email({ to: [lab.address('soporte')] }, { id: 'soporte', condition: when('departamento', 'equals', 'Soporte') }),
        lab.email({ to: [lab.address('urgente')] }, { id: 'urgente', condition: when('mensaje', 'contains', 'urgente') }),
        lab.email(
          { to: [lab.address('cualquiera')] },
          {
            id: 'cualquiera',
            condition: { match: 'any', rules: [{ field: 'departamento', operator: 'equals', value: 'Otro' }, { field: 'mensaje', operator: 'is_empty', value: '' }] },
          },
        ),
        lab.email({ to: [lab.address('siempre')] }, { id: 'siempre' }),
      ],
    }),
  );

  const enviar = (departamento: string, mensaje: string) =>
    lab.restSubmit(formId, { nombre: 'Ana', correo: 'ana@example.test', departamento, mensaje });

  expect((await enviar('Ventas', 'Quiero un presupuesto URGENTE')).status).toBe(200);
  expect((await enviar('Soporte', '')).status).toBe(200);
  expect((await enviar('Otro', 'Sin prisa')).status).toBe(200);

  await lab.mail(lab.address('siempre'), 3);

  // Ventas: el primero. Soporte: el segundo. «Urgente»: el primero, sin distinguir mayúsculas.
  // «Cualquiera» (una de dos reglas): el segundo, por el mensaje vacío, y el tercero, por el departamento.
  const recibidos = async (nombre: string) => (await lab.mailsTo(lab.address(nombre))).length;

  expect(await recibidos('ventas')).toBe(1);
  expect(await recibidos('soporte')).toBe(1);
  expect(await recibidos('urgente')).toBe(1);
  expect(await recibidos('cualquiera')).toBe(2);
  expect(await recibidos('siempre')).toBe(3);

  // El registro de cada envío solo tiene las acciones que le tocaban.
  const envios = await lab.submissions(formId);
  const acciones = await Promise.all(envios.map(async (envio) => (await lab.submission(envio.id)).deliveries.map((delivery) => delivery.action_id).sort()));

  expect(acciones.reverse()).toEqual([
    ['siempre', 'urgente', 'ventas'],
    ['cualquiera', 'siempre', 'soporte'],
    ['cualquiera', 'siempre'],
  ]);
});

test('guardar y notificar son independientes: solo correo, solo guardado', async ({ lab }) => {
  const soloCorreo = await lab.form(lab.definition(contacto, { settings: { store_enabled: false } }), 'Solo correo');
  const soloGuardado = await lab.form(lab.definition(contacto, { actions: [] }), 'Solo guardado');

  expect((await lab.restSubmit(soloCorreo, { nombre: 'Ana', correo: 'ana@example.test' })).status).toBe(200);
  expect((await lab.restSubmit(soloGuardado, { nombre: 'Luis', correo: 'luis@example.test' })).status).toBe(200);

  // El primero llega por correo y no deja rastro; el segundo queda guardado y no escribe a nadie.
  const [mail] = await lab.mail();

  expect(mail.Text).toContain('Nombre: Ana');
  expect(await lab.mailsTo()).toHaveLength(1);
  expect(await lab.submissions(soloCorreo)).toHaveLength(0);

  const guardado = await lab.lastSubmission(soloGuardado);

  expect(guardado.entries[0].value).toBe('Luis');
  expect(guardado.deliveries).toEqual([]);
  // Sin pedirlo, ni IP ni navegador.
  expect(guardado.ip).toBe('');
  expect(guardado.user_agent).toBe('');
});

test('la IP y el navegador se guardan solo si el formulario lo pide, y la IP puede ir anonimizada', async ({ lab }) => {
  const completa = await lab.form(lab.definition(contacto, { settings: { store_ip: 'full', store_user_agent: true }, actions: [] }), 'IP completa');
  const anonima = await lab.form(lab.definition(contacto, { settings: { store_ip: 'anonymized' }, actions: [] }), 'IP anónima');

  await lab.restSubmit(completa, { nombre: 'Ana', correo: 'ana@example.test' });
  await lab.restSubmit(anonima, { nombre: 'Ana', correo: 'ana@example.test' });

  const conTodo = await lab.lastSubmission(completa);
  const sinUltimoByte = await lab.lastSubmission(anonima);

  expect(conTodo.ip).toMatch(/^(127\.0\.0\.1|::1)$/);
  expect(conTodo.user_agent).toContain('Mozilla/5.0');
  expect(sinUltimoByte.ip).toMatch(/^(127\.0\.0\.0|::)$/);
  expect(sinUltimoByte.user_agent).toBe('');
});

test('un webhook con los datos estándar, cabeceras propias y firma comprobable', async ({ lab }) => {
  const secreto = 'secreto-de-la-matriz';
  const { formId, link } = await lab.formPage(
    lab.definition(
      [...contacto, { id: 'extras', type: 'checkboxes', settings: { label: 'Extras', options: options('A', 'B', 'C') } }],
      {
        actions: [
          lab.webhook({
            secret: secreto,
            headers: [
              { name: 'X-Api-Key', value: 'clave a<b &c "d"' },
              { name: 'X-Origen', value: 'formulario' },
            ],
          }),
        ],
      },
    ),
    'shortcode',
    'Webhook',
  );
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await page.getByLabel('Departamento').selectOption('Soporte');
  await page.getByRole('group', { name: 'Extras' }).getByLabel('A', { exact: true }).check();
  await page.getByRole('group', { name: 'Extras' }).getByLabel('C', { exact: true }).check();
  await page.getByLabel('Mensaje').fill('Línea 1\nLínea 2');
  // El visitante no espera al destino: el webhook sale después, en segundo plano.
  await send(page);

  const [peticion] = await lab.waitForSink();
  const cuerpo = JSON.parse(peticion.body);

  expect(peticion.method).toBe('POST');
  expect(peticion.headers.content_type).toContain('application/json');
  expect(peticion.headers.x_api_key).toBe('clave a<b &c "d"');
  expect(peticion.headers.x_origen).toBe('formulario');
  expect(cuerpo.form).toEqual({ id: formId, title: `Webhook ${lab.id}` });
  expect(cuerpo.reference).toMatch(referencia);
  expect(cuerpo.page_url).toBe(link);
  expect(Number.isNaN(Date.parse(cuerpo.date))).toBe(false);
  // Los valores, como datos: las casillas como lista, los saltos de línea como \n.
  expect(cuerpo.fields).toEqual({
    nombre: 'Ana',
    correo: 'ana@example.test',
    departamento: 'Soporte',
    mensaje: 'Línea 1\nLínea 2',
    extras: ['A', 'C'],
  });
  expect(cuerpo.labels).toEqual({ nombre: 'Nombre', correo: 'Correo', departamento: 'Departamento', mensaje: 'Mensaje', extras: 'Extras' });

  // Quien recibe puede comprobar que el envío viene de este sitio y no se ha tocado.
  const firma = createHmac('sha256', secreto).update(`${peticion.headers.x_formulario_mensaje_timestamp}.${peticion.body}`).digest('hex');

  expect(peticion.headers.x_formulario_mensaje_signature).toBe(`sha256=${firma}`);
  expect(peticion.headers.x_formulario_mensaje_reference).toBe(cuerpo.reference);

  const guardado = await lab.lastSubmission(formId);

  await expect.poll(async () => (await lab.submission(guardado.id)).deliveries.map((delivery) => delivery.status)).toEqual(['success']);
  // El secreto no se devuelve nunca en claro.
  expect(JSON.stringify(await lab.api.getForm(formId))).not.toContain(secreto);
});

test('webhooks a medida: método, datos compuestos con etiquetas, varios destinos y uno condicional', async ({ lab }) => {
  const formId = await lab.form(
    lab.definition(contacto, {
      actions: [
        lab.webhook(
          {
            url: lab.sinkUrl(undefined, 'crm'),
            method: 'PUT',
            payload_mode: 'custom',
            payload: [
              { key: 'email', value: '{field:correo}' },
              { key: 'full_name', value: 'Sr./Sra. {field:nombre}' },
              { key: 'source', value: 'web/{form_title}' },
              { key: 'status', value: 'nuevo' },
              { key: 'note', value: '{field:mensaje}\n--\nRef. {reference}' },
            ],
          },
          { id: 'crm', name: 'CRM' },
        ),
        lab.webhook({ url: lab.sinkUrl(undefined, 'chat'), method: 'PATCH' }, { id: 'chat', name: 'Chat', condition: when('departamento', 'equals', 'Soporte') }),
        lab.webhook({ url: lab.sinkUrl(undefined, 'apagado') }, { id: 'apagado', enabled: false }),
      ],
    }),
    'A medida',
  );

  expect((await lab.restSubmit(formId, { nombre: 'Ana "la jefa"', correo: 'ana@example.test', departamento: 'Ventas', mensaje: 'Hola\n{reference}' })).status).toBe(200);
  expect((await lab.restSubmit(formId, { nombre: 'Luis', correo: 'luis@example.test', departamento: 'Soporte', mensaje: '' })).status).toBe(200);

  const crm = await lab.waitForSink(2, 'crm');
  const primero = crm.map((peticion) => JSON.parse(peticion.body)).find((cuerpo) => cuerpo.email === 'ana@example.test');

  expect(crm.every((peticion) => peticion.method === 'PUT')).toBe(true);
  expect(Object.keys(primero)).toEqual(['email', 'full_name', 'source', 'status', 'note']);
  expect(primero.full_name).toBe('Sr./Sra. Ana "la jefa"');
  expect(primero.source).toBe(`web/A medida ${lab.id}`);
  expect(primero.status).toBe('nuevo');
  // Lo que escribió el visitante es texto: su «{reference}» no se sustituye; la de la plantilla, sí.
  expect(primero.note).toMatch(new RegExp(`^Hola\\n\\{reference\\}\\n--\\nRef\\. ${referencia.source}$`));
  // Sin secreto no hay firma.
  expect(crm[0].headers.x_formulario_mensaje_signature).toBeUndefined();

  // El condicional solo recibe el envío de soporte, con su método.
  const chat = await lab.waitForSink(1, 'chat');

  expect(chat).toHaveLength(1);
  expect(chat[0].method).toBe('PATCH');
  expect(JSON.parse(chat[0].body).fields.nombre).toBe('Luis');
  expect(await lab.sink('apagado')).toHaveLength(0);
});

test('si el destino de un webhook falla, el visitante no lo nota y la entrega queda pendiente de reintento', async ({ lab }) => {
  const { formId, link } = await lab.formPage(
    lab.definition(contacto, { actions: [lab.email(), lab.webhook({ url: lab.sinkUrl(503) })] }),
    'shortcode',
    'Destino caído',
  );
  const page = await lab.visitor();

  await page.goto(link);
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
  await send(page);

  // El correo sale igual.
  await lab.mail();
  await lab.waitForSink();

  const guardado = await lab.lastSubmission(formId);

  await expect
    .poll(async () => (await lab.submission(guardado.id)).deliveries.find((delivery) => delivery.action_id === 'wh')?.attempts, { timeout: 30_000 })
    .toBeGreaterThanOrEqual(1);

  const entregas = (await lab.submission(guardado.id)).deliveries;
  const webhook = entregas.find((delivery) => delivery.action_id === 'wh')!;

  expect(entregas.find((delivery) => delivery.action_id === 'aviso')!.status).toBe('success');
  expect(webhook.status).toBe('pending');
  expect(webhook.error).toContain('503');
  expect(Date.parse(webhook.next_attempt!)).toBeGreaterThan(Date.now());
  // Mientras hay reintentos por delante no cuenta como fallida.
  expect((await lab.submission(guardado.id)).failed_actions).toBe(0);
});

test('correo, webhook y guardado a la vez dejan fuera el campo que su condición oculta', async ({ lab }) => {
  const campos: FormField[] = [
    ...contacto.slice(0, 3),
    { id: 'pedido', type: 'text', settings: { label: 'Número de pedido', required: true }, condition: when('departamento', 'equals', 'Soporte') },
    { id: 'presupuesto', type: 'number', settings: { label: 'Presupuesto', required: true }, condition: when('departamento', 'equals', 'Ventas') },
  ];
  const formId = await lab.form(lab.definition(campos, { actions: [lab.email(), lab.webhook()] }));

  // Llega también un valor para el campo oculto: el servidor lo descarta.
  const respuesta = await lab.restSubmit(formId, { nombre: 'Ana', correo: 'ana@example.test', departamento: 'Ventas', presupuesto: '1500', pedido: 'COLADO-1' });

  expect(respuesta.status).toBe(200);

  const [mail] = await lab.mail();
  const [peticion] = await lab.waitForSink();
  const guardado = await lab.lastSubmission(formId);

  expect(mail.Text).toContain('Presupuesto: 1500');

  for (const texto of [mail.Text, peticion.body, JSON.stringify(guardado.entries)]) {
    expect(texto).not.toContain('COLADO-1');
    expect(texto).not.toContain('pedido');
  }

  expect(Object.keys(JSON.parse(peticion.body).fields)).toEqual(['nombre', 'correo', 'departamento', 'presupuesto']);

  // Y el obligatorio que sí se muestra se exige.
  const sinPresupuesto = await lab.restSubmit(formId, { nombre: 'Ana', correo: 'ana@example.test', departamento: 'Ventas' });

  expect(sinPresupuesto.status).toBe(422);
  expect(Object.keys(sinPresupuesto.body.data.errors)).toEqual(['presupuesto']);
});

test('una notificación sin destinatarios falla sin estropear el envío ni las demás acciones', async ({ lab }) => {
  const formId = await lab.form(
    lab.definition(contacto, { actions: [lab.email({ to: [] }, { id: 'rota', name: 'Sin destino' }), lab.email({}, { id: 'buena' }), lab.webhook()] }),
  );
  const respuesta = await lab.restSubmit(formId, { nombre: 'Ana', correo: 'ana@example.test' });

  // El visitante recibe su confirmación: el envío está guardado y el resto ha salido.
  expect(respuesta.status).toBe(200);
  await lab.mail();
  await lab.waitForSink();

  const guardado = await lab.lastSubmission(formId);
  const porAccion = Object.fromEntries(guardado.deliveries.map((delivery) => [delivery.action_id, delivery]));

  expect(porAccion.rota.status).toBe('failed');
  expect(porAccion.rota.error).not.toContain('ana@example.test');
  expect(porAccion.buena.status).toBe('success');
  expect(guardado.failed_actions).toBe(1);

  // En la administración, el mensaje aparece marcado en la bandeja (filtrada por su formulario, para que esté
  // en la lista aunque otros tests envíen a la vez) y, abierto, la entrega fallida se puede reintentar.
  const admin = await lab.admin();

  await admin.goto(`/wp-admin/admin.php?page=formulario-mensaje-submissions&form=${formId}&submission=${guardado.id}`);
  await expect(admin.locator('li.fm-message.is-open .fm-badge')).toHaveText('Error');

  const fallida = admin.locator('.fm-reader .fm-trail li', { hasText: 'Sin destino' });

  await expect(fallida).toHaveClass(/is-failed/);
  await expect(fallida.locator('.fm-badge')).toHaveText('Fallida');
  await expect(fallida.getByRole('button', { name: 'Reintentar' })).toBeVisible();
});
