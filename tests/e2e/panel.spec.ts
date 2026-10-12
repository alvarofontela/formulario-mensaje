import { expect, Page, test } from '@playwright/test';
import { AdminApi, submitWithoutJs } from './support/forms';

// El panel como lo usa quien lleva la web: entra por sus mensajes, ve dónde está cada formulario y lo
// maneja también desde una tableta y un móvil.

const messagesScreen = '/wp-admin/admin.php?page=formulario-mensaje-submissions';
const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';
const settingsScreen = '/wp-admin/admin.php?page=formulario-mensaje-settings';

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

/** Píxeles que el documento sobresale por la derecha: si es más de uno, la página se puede desplazar de lado. */
const sideways = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

/** Píxeles que el documento sobresale por abajo: si es más de uno, la página entera se puede desplazar. */
const downwards = (page: Page) => page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);

/** Formulario con teléfono, para que el mensaje tenga a quién llamar. */
function clinicDefinition() {
  return {
    fields: [
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
      { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
      { id: 'telefono', type: 'tel', settings: { label: 'Teléfono' } },
      { id: 'motivo', type: 'text', settings: { label: 'Motivo' } },
      { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
    ],
    settings: { submit_label: 'Enviar' },
    actions: [],
  };
}

test('un mensaje llega sin leer, se lee al abrirlo y se puede responder o dejar pendiente otra vez', async ({ page }) => {
  const formId = await api.createForm('Bandeja e2e', clinicDefinition());

  // Lo que hubiera pendiente de otros tests no cuenta aquí.
  await api.call('post', '/formulario-mensaje/v1/submissions/read', { all: true });
  await submitWithoutJs(formId, {
    nombre: 'Marta Ruiz',
    correo: 'marta@example.test',
    telefono: '612 345 078',
    motivo: 'Urgencia',
    mensaje: 'Me duele una muela desde el fin de semana.',
  });

  await page.goto(`${messagesScreen}&form=${formId}`);

  // El menú de WordPress abre por los mensajes y avisa de que hay uno pendiente.
  await expect(page.locator('#adminmenu .wp-submenu a.current')).toContainText('Mensajes');
  await expect(page.locator('#adminmenu .wp-submenu a.current .pending-count')).toHaveText('1');

  const item = page.locator('.fm-message');

  await expect(item).toHaveCount(1);
  await expect(item).toHaveClass(/is-unread/);
  await expect(item.locator('.fm-message__who')).toContainText('Marta Ruiz');
  await expect(item.locator('.fm-message__text')).toHaveText('Me duele una muela desde el fin de semana.');
  await expect(page.getByRole('button', { name: 'Sin leer · 1' })).toBeVisible();

  await item.locator('.fm-message__open').click();

  const reader = page.locator('.fm-reader');

  await expect(page).toHaveURL(/submission=\d+/);
  await expect(reader.locator('.fm-reader__title')).toHaveText('Marta Ruiz');
  await expect(reader.locator('.fm-reader__text')).toHaveText('Me duele una muela desde el fin de semana.');
  // Lo que ya está en la cabecera no se repite entre los demás datos.
  await expect(reader.locator('.fm-reader__facts dt')).toHaveText(['Motivo']);
  await expect(reader.locator('.fm-reader__facts dd')).toHaveText(['Urgencia']);
  await expect(reader.getByRole('link', { name: 'Responder' })).toHaveAttribute('href', /^mailto:marta%40example\.test\?subject=/);
  await expect(reader.getByRole('link', { name: 'Llamar' })).toHaveAttribute('href', 'tel:612345078');
  await expect(reader.locator('.fm-trail li').first()).toContainText('Guardado en Mensajes');

  // Abrirlo es leerlo: deja de estar marcado y de contarse.
  await expect(item).not.toHaveClass(/is-unread/);
  await expect(page.getByRole('button', { name: 'Sin leer', exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Sin leer', exact: true }).click();
  await expect(page.locator('.fm-inbox__empty')).toContainText('Ningún mensaje coincide con estos filtros.');
  await page.getByRole('button', { name: 'Todos' }).click();

  await reader.getByRole('button', { name: 'Marcar como no leído' }).click();

  await expect(page).not.toHaveURL(/submission=/);
  await expect(item).toHaveClass(/is-unread/);
  await expect(page.getByRole('button', { name: 'Sin leer · 1' })).toBeVisible();

  // Con el filtro de pendientes se pueden dar todos por leídos de una vez.
  await page.getByRole('button', { name: 'Sin leer · 1' }).click();
  await page.getByRole('button', { name: 'Marcar todos como leídos' }).click();

  await expect(page.locator('.components-notice__content')).toHaveText('Todos los mensajes están marcados como leídos.');
  await expect(page.locator('.fm-message')).toHaveCount(0);
});

test('el listado dice dónde está cada formulario y lleva a ponerlo en una página', async ({ page }) => {
  // El nombre lleva la hora para que una segunda pasada no se confunda con el formulario de la primera.
  const title = `Sin colocar ${Date.now()}`;
  const formId = await api.createForm(title, clinicDefinition());

  await page.goto(formsScreen);

  const row = page.locator('.fm-form-row', { hasText: title });

  await expect(row.locator('.fm-form-row__where')).toHaveText('No se ha encontrado en ninguna página publicada');
  await expect(row.locator('.fm-form-row__activity')).toHaveText('Sin mensajes todavía');

  await row.getByRole('link', { name: 'Ponerlo en una página' }).click();

  // Lleva directamente al paso de publicar, con el código para pegar a mano.
  await expect(page.getByRole('tab', { name: 'Publicar' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('.fm-publish__lead')).toContainText('Todavía no se ha encontrado en ninguna página publicada');
  await expect(page.locator('.fm-publish .fm-admin__shortcode')).toHaveText(`[formulario_mensaje id="${formId}"]`);

  // Una vez puesto en una página, con el bloque, el listado lo sabe y ofrece ver sus mensajes.
  const placed = await api.createPage('Página con formulario e2e', `<!-- wp:formulario-mensaje/form {"formId":${formId}} /-->`);

  await submitWithoutJs(formId, { nombre: 'Luis', correo: 'luis@example.test' });
  await page.goto(formsScreen);

  await expect(row.locator('.fm-form-row__where')).toContainText('Recibiendo mensajes en:');
  await expect(row.locator('.fm-form-row__where a')).toHaveAttribute('href', placed.link);
  await expect(row.locator('.fm-form-row__activity')).toContainText('1 mensaje este mes');
  await expect(row.locator('.fm-form-row__activity')).toContainText('1 sin leer');
  await expect(row.getByRole('link', { name: 'Ver mensajes' })).toHaveAttribute('href', new RegExp(`formulario-mensaje-submissions&form=${formId}$`));

  await page.goto(`${formsScreen}&form=${formId}&step=publish`);
  await expect(page.locator('.fm-publish__lead')).toContainText('Ahora mismo está en:');
  await expect(page.locator('.fm-publish__lead a')).toHaveText('Página con formulario e2e');
});

test('el constructor lleva de un paso al siguiente y conserva los cambios al guardar', async ({ page }) => {
  const formId = await api.createForm('Pasos del constructor e2e', clinicDefinition());

  await page.goto(`${formsScreen}&form=${formId}`);

  await expect(page.getByRole('tab')).toHaveText(['Preguntas', 'Al recibirlo', 'Aspecto', 'Publicar']);
  await expect(page.getByRole('heading', { name: '¿Qué quieres preguntar?' })).toBeVisible();
  await expect(page.locator('.fm-editor__state')).toHaveText('Sin cambios pendientes');

  await page.getByRole('button', { name: 'Siguiente: Al recibirlo' }).click();

  await expect(page).toHaveURL(/step=received/);
  await expect(page.getByRole('tab', { name: 'Al recibirlo' })).toHaveAttribute('aria-selected', 'true');
  // Cada apartado resume cómo está sin tener que abrirlo.
  await expect(page.getByRole('button', { name: /^Guardar los mensajes/ })).toContainText('Sí: puedes leerlos en Mensajes');
  await expect(page.getByRole('button', { name: /^Notificaciones por correo/ })).toContainText('Ninguna activa');
  await expect(page.getByRole('button', { name: /^Notificaciones por correo/ })).toHaveAttribute('aria-expanded', 'true');

  await page.getByRole('button', { name: /^Guardar los mensajes/ }).click();
  await expect(page.getByRole('button', { name: /^Notificaciones por correo/ })).toHaveAttribute('aria-expanded', 'false');
  await page.getByLabel('Guardar los envíos').uncheck();

  await expect(page.getByRole('button', { name: /^Guardar los mensajes/ })).toContainText('No se guardan');
  await expect(page.locator('.fm-editor__state')).toHaveText('Cambios sin guardar');
  // Sin guardado y sin avisos, lo que se envíe se perdería: el constructor lo dice.
  await expect(page.locator('.fm-editor__warning')).toBeVisible();

  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice.is-success .components-notice__content')).toHaveText('Formulario guardado.');
  await expect(page.locator('.fm-editor__state')).toHaveText('Sin cambios pendientes');
  expect((await api.getForm(formId)).definition.settings.store_enabled).toBe(false);

  // El botón de volver del navegador recorre los pasos hacia atrás.
  await page.goBack();
  await expect(page.getByRole('tab', { name: 'Preguntas' })).toHaveAttribute('aria-selected', 'true');

  await page.getByRole('button', { name: 'Volver a los formularios' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Formularios' })).toBeVisible();
  await expect(page).not.toHaveURL(/step=/);
});

test.describe('en una pantalla ancha y baja', () => {
  test.use({ viewport: { width: 1280, height: 600 } });

  test('la bandeja ocupa la ventana justa: se desplaza la lista, no la página', async ({ page }) => {
    // Más mensajes sin leer de los que caben: dos por formulario, que es el límite de esta vía.
    for (const number of [1, 2, 3]) {
      const formId = await api.createForm(`Alto de la bandeja ${number} e2e`, clinicDefinition());

      for (const name of ['Marta Ruiz', 'Luis Gómez']) {
        await submitWithoutJs(formId, { nombre: name, correo: 'alguien@example.test', mensaje: 'Hola' });
      }
    }

    await page.goto(messagesScreen);
    await expect(page.locator('.fm-message.is-unread').nth(5)).toBeAttached();

    const items = page.locator('.fm-inbox__items');

    // La lista no cabe entera y es ella la que tiene la barra de desplazamiento.
    expect(await items.evaluate((list) => list.scrollHeight - list.clientHeight)).toBeGreaterThan(0);
    expect(await downwards(page), 'la bandeja').toBeLessThanOrEqual(1);

    // Tampoco con un mensaje abierto al lado.
    await page.locator('.fm-message__open').first().click();
    await expect(page.locator('.fm-reader__title')).toBeVisible();

    expect(await downwards(page), 'con un mensaje abierto').toBeLessThanOrEqual(1);
  });
});

for (const device of [
  { name: 'un móvil', width: 390, height: 800, phone: true },
  { name: 'una tableta', width: 820, height: 1100, phone: false },
]) {
  test.describe(`en ${device.name}`, () => {
    test.use({ viewport: { width: device.width, height: device.height } });

    test('las tres secciones caben sin desplazarse de lado', async ({ page }) => {
      const formId = await api.createForm(`Ancho ${device.width} e2e`, clinicDefinition());

      await submitWithoutJs(formId, {
        nombre: 'Unapersonaconunnombrelarguísimoquenosepuedepartirdeningunamanera',
        correo: 'una.direccion.de.correo.verdaderamente.larga@un-dominio-tambien-largo.example.test',
        mensaje: 'https://example.test/una/direccion/sin/espacios/que/no/se/puede/partir/por/ningun/sitio/y/sigue/y/sigue',
      });

      for (const screen of [formsScreen, `${messagesScreen}&form=${formId}`, settingsScreen, `${formsScreen}&form=${formId}`, `${formsScreen}&form=${formId}&step=received`, `${formsScreen}&form=${formId}&step=publish`]) {
        await page.goto(screen);
        await expect(page.locator('.fm-admin .components-spinner')).toHaveCount(0);

        expect(await sideways(page), `${screen} a ${device.width} px`).toBeLessThanOrEqual(1);
      }

      // También con un mensaje abierto, que es donde van los textos largos de un visitante.
      await page.goto(`${messagesScreen}&form=${formId}`);
      await page.locator('.fm-message__open').click();
      await expect(page.locator('.fm-reader__title')).toBeVisible();

      expect(await sideways(page), `mensaje abierto a ${device.width} px`).toBeLessThanOrEqual(1);
    });

    test('la bandeja y el constructor se manejan con el dedo', async ({ page }) => {
      const formId = await api.createForm(`Táctil ${device.width} e2e`, clinicDefinition());

      await submitWithoutJs(formId, { nombre: 'Marta Ruiz', correo: 'marta@example.test', mensaje: 'Hola' });
      await page.goto(`${messagesScreen}&form=${formId}`);

      const nav = page.locator('.fm-sections');
      const list = page.locator('.fm-inbox__list');

      if (device.phone) {
        // En el móvil las secciones van en una barra fija abajo, y se ve la lista o el mensaje, no los dos.
        await expect(nav).toBeVisible();
        await expect(nav.locator('[aria-current="page"]')).toContainText('Mensajes');
        await expect(page.locator('.fm-inbox__reader')).toBeHidden();

        await page.locator('.fm-message__open').click();

        await expect(page.locator('.fm-reader__title')).toHaveText('Marta Ruiz');
        await expect(list).toBeHidden();

        // Los botones de la barra del mensaje tienen sitio para el dedo.
        for (const name of ['Volver a los mensajes', 'Marcar como no leído', 'Borrar']) {
          const box = await page.getByRole('button', { name }).boundingBox();

          expect(box?.height, name).toBeGreaterThanOrEqual(44);
          expect(box?.width, name).toBeGreaterThanOrEqual(44);
        }

        await page.getByRole('button', { name: 'Volver a los mensajes' }).click();
        await expect(list).toBeVisible();

        await nav.getByRole('link', { name: 'Formularios' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Formularios' })).toBeVisible();
      } else {
        // En la tableta cabe todo a la vez y las secciones siguen en el menú de WordPress.
        await expect(nav).toBeHidden();
        await page.locator('.fm-message__open').click();
        await expect(page.locator('.fm-reader__title')).toHaveText('Marta Ruiz');
        await expect(list).toBeVisible();
      }

      await page.goto(`${formsScreen}&form=${formId}`);

      const preview = page.frameLocator('iframe[title="Vista previa del formulario"]');

      if (device.phone) {
        // La vista previa no cabe al lado: se abre con un botón y se cierra con otro.
        await expect(page.locator('.fm-editor__preview')).toBeHidden();
        await page.getByRole('button', { name: 'Vista previa' }).click();
        await expect(preview.getByLabel('Nombre')).toBeVisible();
        await page.getByRole('button', { name: 'Cerrar la vista previa' }).click();
        await expect(page.locator('.fm-editor__preview')).toBeHidden();

        // Mover, duplicar y borrar un campo se hace con botones grandes, sin arrastrar.
        const move = await page.getByRole('button', { name: 'Bajar «Nombre»' }).boundingBox();

        expect(move?.height).toBeGreaterThanOrEqual(44);
        expect(move?.width).toBeGreaterThanOrEqual(44);
      } else {
        await expect(preview.getByLabel('Nombre')).toBeVisible();
      }

      await page.getByRole('button', { name: 'Bajar «Nombre»' }).click();
      await expect(page.locator('.fm-fields__name').first()).toHaveText('Correo');
    });
  });
}
