import { BrowserContext, Page, expect, request as playwrightRequest, test } from '@playwright/test';
import { env } from './support/env';
import { AdminApi, contactDefinition, notifyTo, visitorContext } from './support/forms';
import { clearMail, mailMatching, waitForMail } from './support/mailpit';

// Captcha: se configura una vez en los ajustes y protege los formularios del sitio.
//
// El script del servicio se sustituye en el navegador por un doble, y su verificación en el servidor
// la responde el entorno (ver scripts/templates/rt-environment.php): «rt-valido» es un testigo bueno.

const settingsScreen = '/wp-admin/admin.php?page=formulario-mensaje-settings';

/** Doble de Turnstile en modo interactivo: una casilla que entrega el testigo que se le pida al pulsar su botón. */
const fakeTurnstile = `
window.rtCaptcha = { renders: 0, resets: 0, sitekey: '' };
window.turnstile = {
	render( slot, options ) {
		window.rtCaptcha.renders++;
		window.rtCaptcha.sitekey = options.sitekey;
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
	reset() {
		window.rtCaptcha.resets++;
	},
};`;

/** Doble de reCAPTCHA v3: sin casilla, entrega un testigo cada vez que se le pide. */
const fakeRecaptcha = `
window.rtCaptcha = { executions: [] };
window.grecaptcha = {
	ready( callback ) {
		callback();
	},
	execute( sitekey, options ) {
		window.rtCaptcha.executions.push( { sitekey, action: options.action } );
		return Promise.resolve( 'rt-valido' );
	},
};`;

let api: AdminApi;
let context: BrowserContext;
let page: Page;
let thirdParty: string[];

test.beforeAll(async () => {
  api = await AdminApi.create();
});

test.afterAll(async () => {
  await api.dispose();
});

test.beforeEach(async ({ browser }) => {
  await clearMail();

  thirdParty = [];
  context = await visitorContext(browser);

  await context.route('https://challenges.cloudflare.com/**', (route) => {
    thirdParty.push(route.request().url());
    return route.fulfill({ contentType: 'application/javascript', body: fakeTurnstile });
  });
  await context.route('https://www.google.com/recaptcha/**', (route) => {
    thirdParty.push(route.request().url());
    return route.fulfill({ contentType: 'application/javascript', body: fakeRecaptcha });
  });

  page = await context.newPage();
});

test.afterEach(async () => {
  await context.close();
  // Sin captcha para el resto de los tests.
  await api.saveCaptchaSettings({ provider: '' });
});

const toSales = (mail: { To: { Address: string }[] }) => mail.To.some((to) => to.Address === notifyTo);

async function fill(): Promise<void> {
  await page.getByLabel('Nombre').fill('Ana');
  await page.getByLabel('Correo').fill('ana@example.test');
}

test('el captcha se configura en los ajustes sin que la clave secreta vuelva al navegador', async ({ page: admin }) => {
  const secret = 'clave-secreta-e2e-41b';

  await admin.goto(settingsScreen);
  await admin.getByRole('tab', { name: 'Captcha' }).click();

  await expect(admin.getByLabel('Ninguno')).toBeChecked();
  await admin.getByLabel('Google reCAPTCHA v3').check();
  await expect(admin.getByLabel('Puntuación mínima').first()).toBeVisible();

  await admin.getByLabel('Cloudflare Turnstile').check();
  await expect(admin.getByLabel('Puntuación mínima')).toHaveCount(0);
  await admin.getByLabel('Clave del sitio').fill('clave-publica-e2e');
  await admin.getByLabel('Clave secreta').fill(secret);
  await admin.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(admin.locator('.components-notice').first()).toContainText('Ajustes guardados.');

  await admin.reload();
  await admin.getByRole('tab', { name: 'Captcha' }).click();

  await expect(admin.getByLabel('Cloudflare Turnstile')).toBeChecked();
  await expect(admin.getByLabel('Clave del sitio')).toHaveValue('clave-publica-e2e');
  await expect(admin.getByLabel('Clave secreta')).toHaveValue('');
  await expect(admin.getByLabel('Clave secreta')).toHaveAttribute('placeholder', /valor guardado/);
  expect(await admin.content()).not.toContain(secret);

  // Cambiar de servicio sin escribir su clave descarta la guardada y lo avisa.
  await admin.getByLabel('hCaptcha').check();
  await admin.getByRole('button', { name: 'Guardar ajustes' }).click();
  await expect(admin.locator('.components-notice').first()).toContainText('El servicio de captcha ha cambiado');
});

test('el script del captcha se carga al usar el formulario y el envío exige resolverlo', async () => {
  await api.saveCaptchaSettings({ provider: 'turnstile', site_key: 'clave-publica-e2e' }, 'clave-secreta');

  const { link } = await api.createFormPage('Captcha e2e');

  await page.goto(link);
  await expect(page.locator('form.fm-form')).toHaveAttribute('data-fm-captcha', 'turnstile');
  expect(thirdParty, 'Al abrir la página no se pide nada a terceros.').toHaveLength(0);

  await fill();

  const slot = page.locator('.fm-captcha');
  await expect(slot.getByRole('button', { name: 'Soy una persona' })).toBeVisible();
  expect(thirdParty).toHaveLength(1);
  expect(thirdParty[0]).toContain('render=explicit');
  expect(await page.evaluate(() => (window as any).rtCaptcha.sitekey)).toBe('clave-publica-e2e');

  // Sin resolver el captcha el formulario no llega a enviarse.
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-status')).toContainText('Completa la comprobación de seguridad');
  expect(await mailMatching(toSales, 500)).toHaveLength(0);

  // Con un testigo que el servicio no reconoce, el servidor lo rechaza y se pide otro.
  await slot.getByRole('button', { name: 'Testigo falso' }).click();
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-status')).toContainText('La comprobación de seguridad ha fallado');
  await expect.poll(() => page.evaluate(() => (window as any).rtCaptcha.resets)).toBeGreaterThan(0);
  expect(await mailMatching(toSales, 500)).toHaveLength(0);

  await slot.getByRole('button', { name: 'Soy una persona' }).click();
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.locator('.fm-success')).toBeVisible();
  await waitForMail(toSales);
});

test('con reCAPTCHA v3 el formulario se envía sin que el visitante haga nada más', async () => {
  await api.saveCaptchaSettings({ provider: 'recaptcha', site_key: 'clave-publica-e2e', score_threshold: 0.5 }, 'clave-secreta');

  const { link } = await api.createFormPage('reCAPTCHA e2e');

  await page.goto(link);
  await fill();
  await page.getByRole('button', { name: 'Enviar' }).click();

  await expect(page.locator('.fm-success')).toBeVisible();
  expect(thirdParty[0]).toContain('render=clave-publica-e2e');
  expect(await page.evaluate(() => (window as any).rtCaptcha.executions)).toEqual([{ sitekey: 'clave-publica-e2e', action: 'formulario_mensaje_submit' }]);
  await waitForMail(toSales);
});

test('un formulario con captcha no se puede enviar sin JavaScript, y uno que renuncia a él sí', async () => {
  await api.saveCaptchaSettings({ provider: 'turnstile', site_key: 'clave-publica-e2e' }, 'clave-secreta');

  const withCaptcha = await api.createForm('Con captcha e2e');
  const without = await api.createForm('Sin captcha e2e', contactDefinition({ settings: { submit_label: 'Enviar', captcha_enabled: false } }));
  const anonymous = await playwrightRequest.newContext({ baseURL: env.baseUrl });

  const post = (formId: number) =>
    anonymous.post('/wp-admin/admin-post.php', {
      form: { action: 'formulario_mensaje_submit', fm_form_id: String(formId), 'fm[nombre]': 'Ana', 'fm[correo]': 'ana@example.test' },
      maxRedirects: 0,
    });

  const rejected = await post(withCaptcha);
  expect(rejected.status()).toBe(400);
  expect(await rejected.text()).toContain('necesita JavaScript');

  expect((await post(without)).status()).toBe(303);

  await anonymous.dispose();

  // En la página, el formulario que renuncia no lleva nada del captcha.
  const pageWithout = await api.createPage('Sin captcha e2e', `<!-- wp:shortcode -->[formulario_mensaje id="${without}"]<!-- /wp:shortcode -->`);
  await page.goto(pageWithout.link);
  await expect(page.locator('form.fm-form')).not.toHaveAttribute('data-fm-captcha', /.+/);
  await expect(page.locator('.fm-captcha')).toHaveCount(0);
});
