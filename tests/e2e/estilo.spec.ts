import { expect, test } from '@playwright/test';
import { env } from './support/env';
import { AdminApi, contactDefinition, visitorContext } from './support/forms';

// Estilo: el diseñador del formulario, los controles del bloque y el widget de Elementor.

const formsScreen = '/wp-admin/admin.php?page=formulario-mensaje';

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test('el diseñador cambia colores y medidas del formulario y la web los aplica', async ({ page, browser }) => {
  const formId = await api.createForm('Estilo e2e');

  await page.goto(`${formsScreen}&form=${formId}`);
  await page.getByRole('tab', { name: 'Estilo' }).click();

  await page.getByLabel('Color de las etiquetas').fill('#aa0000');
  await page.getByLabel('Fondo del botón').fill('#0055aa');
  await page.getByLabel('Color del texto del botón').fill('#ffffff');
  await page.getByRole('spinbutton', { name: 'Separación entre campos' }).fill('40');
  await page.getByRole('spinbutton', { name: 'Radio del borde' }).fill('12');

  // La vista previa lo enseña al momento, antes de guardar.
  const preview = page.frameLocator('.fm-preview__frame');
  await expect(preview.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
  await expect(preview.locator('.fm-submit')).toHaveCSS('background-color', 'rgb(0, 85, 170)');

  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await expect(page.locator('.components-notice').first()).toContainText('Formulario guardado.');

  const created = await api.createPage('Estilo e2e', `<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`);
  const visitor = await visitorContext(browser);
  const front = await visitor.newPage();

  await front.goto(created.link);

  await expect(front.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
  await expect(front.locator('.fm-submit')).toHaveCSS('background-color', 'rgb(0, 85, 170)');
  await expect(front.locator('.fm-submit')).toHaveCSS('color', 'rgb(255, 255, 255)');
  await expect(front.locator('.fm-form')).toHaveCSS('row-gap', '40px');
  await expect(front.locator('.fm-control').first()).toHaveCSS('border-top-left-radius', '12px');

  await visitor.close();

  // Quitar un color lo devuelve al del tema.
  await page.getByRole('button', { name: 'Usar el del tema' }).first().click();
  await expect(preview.locator('.fm-label').first()).not.toHaveCSS('color', 'rgb(170, 0, 0)');
});

test('los controles de estilo del bloque tienen prioridad sobre el estilo del formulario', async ({ page, browser }) => {
  const definition = { ...contactDefinition(), style: { gap: 40, label_color: '#aa0000' } };
  const formId = await api.createForm('Bloque con estilo e2e', definition);
  const created = await api.createPage(
    'Bloque con estilo e2e',
    `<!-- wp:formulario-mensaje/form {"formId":${formId},"formStyle":{"gap":8,"button_background":"#008800"}} /-->`,
  );

  const visitor = await visitorContext(browser);
  const front = await visitor.newPage();

  await front.goto(created.link);
  // El bloque cambia la separación y el botón; el color de las etiquetas sigue siendo el del formulario.
  await expect(front.locator('.fm-form')).toHaveCSS('row-gap', '8px');
  await expect(front.locator('.fm-submit')).toHaveCSS('background-color', 'rgb(0, 136, 0)');
  await expect(front.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
  await visitor.close();

  // En el editor, el bloque ofrece esos controles en su pestaña de estilos.
  await page.goto(`/wp-admin/post.php?post=${created.id}&action=edit`);

  const guide = page.getByRole('dialog').getByRole('button', { name: 'Cerrar' });
  if (await guide.isVisible().catch(() => false)) {
    await guide.click();
  }

  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const block = canvas.locator('[data-type="formulario-mensaje/form"]');

  await expect(block.locator('.fm-form')).toBeVisible();
  await expect(block.locator('.fm-form')).toHaveCSS('row-gap', '8px');
  await block.click({ position: { x: 5, y: 5 } });

  const sidebar = page.getByRole('region', { name: 'Ajustes del editor' });
  await sidebar.getByRole('tab', { name: 'Estilos' }).click();
  await expect(sidebar.getByRole('button', { name: 'Separación y bordes del formulario' })).toBeVisible();
  await expect(sidebar.getByText('Colores del formulario')).toBeVisible();
});

test.describe('con Elementor', () => {
  test.beforeAll(async () => {
    await api.setPluginStatus('elementor/elementor', 'active');
  });

  test.afterAll(async () => {
    await api.setPluginStatus('elementor/elementor', 'inactive');
  });

  test('el widget de Elementor muestra el formulario, aplica sus controles de estilo y se puede enviar', async ({ page, browser }) => {
    test.setTimeout(150_000);

    const formId = await api.createForm('Elementor e2e', { ...contactDefinition(), style: { gap: 40 } });
    const created = await api.createPage('Elementor e2e', '');

    const saved = await (
      await fetch(`${env.baseUrl}/wp-json/rt/v1/elementor-page`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          post_id: created.id,
          elements: [
            {
              id: 'fm5ec001',
              elType: 'section',
              settings: {},
              isInner: false,
              elements: [
                {
                  id: 'fmc01001',
                  elType: 'column',
                  settings: { _column_size: 100 },
                  isInner: false,
                  elements: [
                    {
                      id: 'fma1d001',
                      elType: 'widget',
                      widgetType: 'formulario-mensaje',
                      settings: {
                        form_id: String(formId),
                        gap: { unit: 'px', size: 12 },
                        label_color: '#aa0000',
                        button_background: '#0055aa',
                        button_color: '#ffffff',
                        border_radius: { unit: 'px', size: 10 },
                      },
                      elements: [],
                    },
                  ],
                },
              ],
            },
          ],
        }),
      })
    ).json();

    expect(saved.elementor, 'Elementor está activo en el entorno.').toBeTruthy();
    console.log(`Elementor ${saved.elementor}`);

    const visitor = await visitorContext(browser);
    const front = await visitor.newPage();

    await front.goto(created.link);

    const widget = front.locator('.elementor-widget-formulario-mensaje');
    await expect(widget.locator('.fm-form')).toBeVisible();

    // Los controles del widget mandan sobre el estilo del formulario (separación de 40 px).
    await expect(widget.locator('.fm-form')).toHaveCSS('row-gap', '12px');
    await expect(widget.locator('.fm-label').first()).toHaveCSS('color', 'rgb(170, 0, 0)');
    await expect(widget.locator('.fm-submit')).toHaveCSS('background-color', 'rgb(0, 85, 170)');
    await expect(widget.locator('.fm-submit')).toHaveCSS('color', 'rgb(255, 255, 255)');
    await expect(widget.locator('.fm-control').first()).toHaveCSS('border-top-left-radius', '10px');

    // Es el mismo formulario que en el bloque o el shortcode: se envía igual.
    await widget.getByLabel('Nombre').fill('Ana');
    await widget.getByLabel('Correo').fill('ana@example.test');
    await widget.getByRole('button', { name: 'Enviar' }).click();
    await expect(widget.locator('.fm-success')).toBeVisible();
    await visitor.close();

    // Recién activado, Elementor redirige la primera pantalla de administración a su bienvenida.
    await page.goto('/wp-admin/');

    // En el editor de Elementor el widget se pinta dentro de la vista previa.
    await page.goto(`/wp-admin/post.php?post=${created.id}&action=elementor`);

    const editorPreview = page.frameLocator('#elementor-preview-iframe');
    await expect(editorPreview.locator('.elementor-widget-formulario-mensaje .fm-form')).toBeVisible({ timeout: 90_000 });
  });
});
