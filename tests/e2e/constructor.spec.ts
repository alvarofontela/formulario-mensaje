import { expect, test } from '@playwright/test';
import { AdminApi, contactDefinition } from './support/forms';

// Pantallas de administración: constructor de formularios, papelera, ajustes y bloque.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test('se crea un formulario desde el constructor y aparece en el listado', async ({ page }) => {
  const title = `Presupuesto ${Date.now()}`;

  await page.goto(formsScreen);
  await page.getByRole('button', { name: 'Añadir formulario' }).first().click();
  await page.getByRole('dialog', { name: 'Elige una plantilla' }).getByRole('button', { name: /^Contacto/ }).click();

  // La plantilla de contacto trae nombre, correo y mensaje.
  const fields = page.locator('.fm-fields__item');
  await expect(fields).toHaveCount(3);
  await expect(fields.nth(0)).toContainText('Nombre');

  await page.getByLabel('Nombre del formulario').fill(title);

  // Añadir un campo de teléfono y cambiar su etiqueta.
  await page.getByRole('button', { name: 'Añadir campo' }).click();
  await page.getByRole('menuitem', { name: 'Teléfono' }).click();
  await expect(fields).toHaveCount(4);
  await page.getByLabel('Etiqueta', { exact: true }).fill('Teléfono de contacto');
  await page.getByLabel('Obligatorio').check();

  // La vista previa la genera el servidor con el mismo código que la web.
  const preview = page.frameLocator('.fm-preview__frame');
  await expect(preview.getByLabel('Teléfono de contacto')).toBeVisible();

  await page.getByRole('button', { name: 'Crear formulario' }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario guardado.');
  await expect(page).toHaveURL(/form=\d+/);
  await expect(page.getByRole('button', { name: 'Guardar', exact: true })).toBeDisabled();

  // El código para pegarlo en una página está en el paso «Publicar».
  await page.getByRole('tab', { name: 'Publicar' }).click();
  await expect(page.locator('code.fm-admin__shortcode')).toHaveText(/\[formulario_mensaje id="\d+"\]/);

  await page.getByRole('button', { name: 'Volver a los formularios' }).click();

  // En el listado aparece como una fila más; su código está en el menú de acciones.
  const row = page.locator('li.fm-form-row', { hasText: title });
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: `Más acciones de «${title}»` }).click();
  await expect(page.getByRole('menuitem', { name: 'Copiar el código para pegar' })).toContainText(/\[formulario_mensaje id="\d+"\]/);
  await page.keyboard.press('Escape');

  // El listado ya no dice cuántos campos tiene: se comprueba abriéndolo otra vez, con lo que guardó el servidor.
  await row.getByRole('button', { name: title, exact: true }).click();
  await expect(page).toHaveURL(/form=\d+/);
  await expect(fields).toHaveCount(4);
  await expect(fields.nth(3)).toContainText('Teléfono de contacto');
});

test('los campos se reordenan, se duplican y se borran, y el cambio se conserva al guardar', async ({ page }) => {
  const formId = await api.createForm('Ordenar e2e');

  await page.goto(`${formsScreen}&form=${formId}`);

  const names = page.locator('.fm-fields__name');
  await expect(names).toHaveText(['Nombre', 'Correo', 'Mensaje']);

  await page.getByRole('button', { name: 'Subir «Mensaje»' }).click();
  await page.getByRole('button', { name: 'Bajar «Nombre»' }).click();
  await expect(names).toHaveText(['Mensaje', 'Nombre', 'Correo']);

  await page.getByRole('button', { name: 'Duplicar «Correo»' }).click();
  await expect(names).toHaveText(['Mensaje', 'Nombre', 'Correo', 'Correo']);
  await page.getByRole('button', { name: 'Borrar «Nombre»' }).click();
  await expect(names).toHaveText(['Mensaje', 'Correo', 'Correo']);

  const preview = page.frameLocator('.fm-preview__frame');
  await expect(preview.locator('.fm-label')).toHaveText([/Mensaje/, /Correo/, /Correo/]);

  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario guardado.');

  await page.reload();
  await expect(names).toHaveText(['Mensaje', 'Correo', 'Correo']);
});

test('un campo se puede arrastrar a otra posición', async ({ page }) => {
  const formId = await api.createForm('Arrastrar e2e');

  await page.goto(`${formsScreen}&form=${formId}`);

  const names = page.locator('.fm-fields__name');
  await expect(names).toHaveText(['Nombre', 'Correo', 'Mensaje']);

  await page.locator('.fm-fields__handle').nth(2).dragTo(page.locator('.fm-fields__item').nth(0));

  await expect(names).toHaveText(['Mensaje', 'Nombre', 'Correo']);
});

test('la notificación y el comportamiento tras el envío se configuran y se guardan', async ({ page }) => {
  const formId = await api.createForm('Ajustes e2e');

  await page.goto(`${formsScreen}&form=${formId}`);

  // Las notificaciones y lo que ve el visitante son apartados del paso «Al recibirlo». El de las
  // notificaciones ya está abierto al entrar; abrir otro lo cierra.
  const afterSending = page.getByRole('button', { name: /^Qué ve quien lo envía/ });

  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  await expect(page.getByRole('button', { name: /^Notificaciones por correo/ })).toHaveAttribute('aria-expanded', 'true');
  await page.getByLabel('Enviar a', { exact: true }).fill('uno@example.test, no-es-correo, dos@example.test');
  await page.getByLabel('Asunto', { exact: true }).fill('Aviso de {form_title}');

  await afterSending.click();
  await page.getByLabel('Texto del botón').fill('Pedir presupuesto');
  await page.getByLabel('Vaciarlo para permitir otro envío').check();
  await page.getByLabel('Redirigir a otra página').check();
  await page.getByLabel('Dirección', { exact: true }).fill('https://no-permitido.example/gracias');

  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice')).toContainText('La dirección de redirección no está permitida');

  await page.getByLabel('Dirección', { exact: true }).fill('/gracias/');
  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario guardado.');

  await page.reload();
  await page.getByRole('tab', { name: 'Al recibirlo' }).click();
  // El servidor se queda solo con las direcciones válidas.
  await expect(page.getByLabel('Enviar a', { exact: true })).toHaveValue('uno@example.test, dos@example.test');
  await expect(page.getByLabel('Asunto', { exact: true })).toHaveValue('Aviso de {form_title}');

  await afterSending.click();
  await expect(page.getByLabel('Texto del botón')).toHaveValue('Pedir presupuesto');
  await expect(page.getByLabel('Vaciarlo para permitir otro envío')).toBeChecked();
  await expect(page.getByLabel('Dirección', { exact: true })).toHaveValue('/gracias/');
});

test('un formulario va a la papelera, se restaura y se borra definitivamente', async ({ page }) => {
  const title = `Temporal ${Date.now()}`;
  await api.createForm(title);

  await page.goto(formsScreen);

  const row = page.locator('li.fm-form-row', { hasText: title });
  const trashTab = page.getByRole('button', { name: 'Papelera', exact: true });

  // Mover a la papelera es una de las acciones del menú de la fila.
  const moveToTrash = async () => {
    await row.getByRole('button', { name: `Más acciones de «${title}»` }).click();
    await page.getByRole('menuitem', { name: 'Mover a la papelera' }).click();
  };

  await moveToTrash();
  await expect(page.locator('.components-notice')).toContainText('Formulario enviado a la papelera.');
  await expect(row).toHaveCount(0);

  await trashTab.click();
  await expect(trashTab).toHaveAttribute('aria-pressed', 'true');
  await expect(row).toBeVisible();
  await row.getByRole('button', { name: `Restaurar «${title}»` }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario restaurado.');

  await page.getByRole('button', { name: 'Publicados', exact: true }).click();
  await moveToTrash();
  await expect(row).toHaveCount(0);

  await trashTab.click();
  await row.getByRole('button', { name: `Borrar «${title}» definitivamente` }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Borrar definitivamente' }).click();
  await expect(page.locator('.components-notice')).toContainText('Formulario borrado.');
  await expect(row).toHaveCount(0);
});

test('los ajustes guardan el proxy de confianza y los dominios de redirección', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=formulario-mensaje-settings');

  // El servidor local escucha en IPv4 y en IPv6: vale cualquiera de las dos direcciones locales.
  await expect(page.locator('.fm-settings-page__ip')).toContainText(/127\.0\.0\.1|::1/);

  await page.getByLabel('Mi propio proxy o balanceador de carga').check();
  await page.getByLabel('Direcciones IP del proxy').fill('10.0.0.0/8\nno-es-una-ip\n192.0.2.7');
  await page.getByLabel('Dominios', { exact: true }).fill('Pagos.Example.com\nhttps://otro.example.org/ruta');
  await page.getByRole('button', { name: 'Guardar ajustes' }).click();

  await expect(page.locator('.components-notice')).toContainText('Ajustes guardados.');
  // El servidor descarta lo que no es válido y normaliza el resto.
  await expect(page.getByLabel('Direcciones IP del proxy')).toHaveValue('10.0.0.0/8\n192.0.2.7');
  await expect(page.getByLabel('Dominios', { exact: true })).toHaveValue('pagos.example.com\notro.example.org');

  await page.reload();
  await expect(page.getByLabel('Mi propio proxy o balanceador de carga')).toBeChecked();

  // Se dejan los ajustes como estaban para no afectar a otros tests.
  await api.saveSettings({ trusted_proxy_mode: 'none', trusted_proxies: [], allowed_redirect_hosts: [] });
});

test('el bloque muestra el formulario en el editor y en la web', async ({ page }) => {
  const formId = await api.createForm('Bloque e2e', contactDefinition());
  const created = await api.createPage('Bloque e2e', `<!-- wp:formulario-mensaje/form {"formId":${formId}} /-->`);

  await page.goto(created.link);
  await expect(page.locator('.wp-block-formulario-mensaje-form .fm-form')).toBeVisible();
  await expect(page.getByLabel('Nombre')).toBeVisible();

  await page.goto(`/wp-admin/post.php?post=${created.id}&action=edit`);

  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const block = canvas.locator('[data-type="formulario-mensaje/form"]');

  await expect(block).toBeVisible();
  await expect(block.locator('.fm-form')).toBeVisible();
  await expect(canvas.locator('.block-editor-warning')).toHaveCount(0);

  // La guía de bienvenida del editor tapa la pantalla la primera vez.
  const guide = page.getByRole('dialog').getByRole('button', { name: 'Cerrar' });
  if (await guide.isVisible().catch(() => false)) {
    await guide.click();
  }

  // El selector del panel lateral lista los formularios y marca el elegido.
  await block.click({ position: { x: 5, y: 5 } });
  await expect(page.getByRole('region', { name: 'Ajustes del editor' }).getByLabel('Formulario', { exact: true })).toHaveValue(String(formId));
});
