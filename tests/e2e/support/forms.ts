import { APIRequestContext, Browser, BrowserContext, request as playwrightRequest } from '@playwright/test';
import { adminStorage, env } from './env';

export interface FormField {
  id: string;
  type: string;
  settings: Record<string, unknown>;
  width?: number;
  condition?: { match: 'all' | 'any'; rules: { field: string; operator: string; value: string }[] };
}

export interface FormDefinition {
  fields: FormField[];
  settings: Record<string, unknown>;
  style?: Record<string, unknown>;
  actions: {
    id: string;
    type: string;
    enabled: boolean;
    name?: string;
    condition?: { match: 'all' | 'any'; rules: { field: string; operator: string; value: string }[] };
    settings: Record<string, unknown>;
  }[];
}

/** Dirección a la que llegan las notificaciones de los formularios de prueba. */
export const notifyTo = 'ventas@example.test';

/** Definición de un formulario de contacto con una notificación; cada test cambia lo que necesita. */
export function contactDefinition(overrides: Partial<FormDefinition> = {}): FormDefinition {
  return {
    fields: [
      { id: 'nombre', type: 'text', settings: { label: 'Nombre', required: true } },
      { id: 'correo', type: 'email', settings: { label: 'Correo', required: true } },
      { id: 'mensaje', type: 'textarea', settings: { label: 'Mensaje' } },
    ],
    settings: { submit_label: 'Enviar' },
    actions: [
      {
        id: 'aviso',
        type: 'email',
        enabled: true,
        // En texto plano: el correo en HTML tiene sus propios tests.
        settings: { to: [notifyTo], subject: 'Nuevo envío: {form_title}', body: '{all_fields}', format: 'text', reply_to_field: 'correo' },
      },
    ],
    ...overrides,
  };
}

/** Cliente de la REST API con la sesión de administrador y su nonce. */
export class AdminApi {
  private constructor(
    private readonly context: APIRequestContext,
    private readonly nonce: string,
  ) {}

  static async create(): Promise<AdminApi> {
    const context = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: adminStorage });
    const nonce = await (await context.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text();

    return new AdminApi(context, nonce);
  }

  private async send<T>(method: 'get' | 'post' | 'put' | 'delete', path: string, data?: unknown): Promise<T> {
    const response = await this.context[method](`/wp-json${path}`, { headers: { 'X-WP-Nonce': this.nonce }, data });

    if (!response.ok()) {
      throw new Error(`${method.toUpperCase()} ${path} -> ${response.status()} ${await response.text()}`);
    }

    return response.json();
  }

  /** Crea un formulario y devuelve su identificador. */
  async createForm(title: string, definition: FormDefinition = contactDefinition()): Promise<number> {
    return (await this.send<{ id: number }>('post', '/formulario-mensaje/v1/forms', { title, definition })).id;
  }

  /** Sustituye el nombre y la definición de un formulario. */
  async updateForm(id: number, title: string, definition: FormDefinition): Promise<void> {
    await this.send('put', `/formulario-mensaje/v1/forms/${id}`, { title, definition });
  }

  /** Crea una página publicada con el contenido indicado y devuelve su dirección. */
  async createPage(title: string, content: string): Promise<{ id: number; link: string }> {
    return this.send('post', '/wp/v2/pages', { title, content, status: 'publish' });
  }

  /** Crea un formulario y una página que lo muestra con el shortcode. */
  async createFormPage(title: string, definition: FormDefinition = contactDefinition()): Promise<{ formId: number; link: string }> {
    const formId = await this.createForm(title, definition);
    const page = await this.createPage(title, `<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`);

    return { formId, link: page.link };
  }

  async saveSettings(settings: Record<string, unknown>): Promise<void> {
    await this.send('put', '/formulario-mensaje/v1/settings', settings);
  }

  /** Guarda los ajustes de correo. Las credenciales que no se indican se borran, para no arrastrarlas entre tests. */
  async saveMailSettings(settings: Record<string, unknown>, secrets: Record<string, string> = {}): Promise<void> {
    const clear = ['smtp_password', 'resend_api_key'].filter((name) => !secrets[name]);

    await this.send('put', '/formulario-mensaje/v1/settings/mail', { settings, secrets, clear });
  }

  /** Guarda los ajustes del captcha. Sin clave secreta, se borra la guardada. */
  async saveCaptchaSettings(settings: Record<string, unknown>, secret = ''): Promise<void> {
    await this.send('put', '/formulario-mensaje/v1/settings/captcha', { settings, secret, clear_secret: secret === '' });
  }

  /** Activa o desactiva un plugin del entorno, por su fichero sin la extensión (`elementor/elementor`). */
  async setPluginStatus(plugin: string, status: 'active' | 'inactive'): Promise<void> {
    await this.send('post', `/wp/v2/plugins/${plugin}`, { status });
  }

  async dispose(): Promise<void> {
    await this.context.dispose();
  }
}

/**
 * Envía un formulario como lo haría un navegador sin JavaScript, sin sesión. Es la forma rápida de
 * crear envíos para los tests de administración: no espera el tiempo mínimo de relleno.
 * Esta vía admite dos envíos por formulario e IP cada diez minutos.
 */
export async function submitWithoutJs(formId: number, values: Record<string, string>): Promise<void> {
  // Con el estado vacío: dentro de un test, un contexto nuevo hereda la sesión de la configuración.
  const context = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: { cookies: [], origins: [] } });
  const form: Record<string, string> = { action: 'formulario_mensaje_submit', fm_form_id: String(formId) };

  for (const [field, value] of Object.entries(values)) {
    form[`fm[${field}]`] = value;
  }

  const response = await context.post('/wp-admin/admin-post.php', { form, maxRedirects: 0 });
  await context.dispose();

  if (response.status() !== 303) {
    throw new Error(`El envío sin JavaScript devolvió ${response.status()}: ${await response.text()}`);
  }
}

/** Contexto de navegador sin sesión: un visitante anónimo. */
export function visitorContext(browser: Browser, options: { javaScriptEnabled?: boolean } = {}): Promise<BrowserContext> {
  return browser.newContext({ baseURL: env.baseUrl, locale: 'es-ES', storageState: { cookies: [], origins: [] }, ...options });
}
