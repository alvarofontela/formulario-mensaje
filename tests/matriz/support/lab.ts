import { Browser, BrowserContext, Page, TestInfo, test as base, expect, request as playwrightRequest } from '@playwright/test';
import { adminStorage, env } from '../../e2e/support/env';
import { AdminApi, FormDefinition, FormField } from '../../e2e/support/forms';
import { MailDetail, MailSummary } from '../../e2e/support/mailpit';
import { SinkRequest } from '../../e2e/support/sink';

// Banco de pruebas de un test de la matriz. Todo lo que crea lleva un identificador propio, para
// que el test pueda correr a la vez que otros: sus formularios y páginas, la dirección a la que
// llegan sus correos y el canal de su receptor de webhooks.

type Action = FormDefinition['actions'][number];
type Condition = NonNullable<FormField['condition']>;

export interface Submitted {
  id: number;
  form_id: number;
  reference: string;
  summary: string;
  failed_actions: number;
  page_url: string;
}

export interface SubmittedDetail extends Submitted {
  ip: string;
  user_agent: string;
  entries: { field_id: string; type: string; label: string; value: string }[];
  files: { field_id: string; name: string; size: string; url: string }[];
  deliveries: { id: number; action_id: string; action_type: string; status: string; attempts: number; error: string; next_attempt: string | null }[];
}

/** Fichero que se sube en un test, con la forma que espera `setInputFiles()` de Playwright. */
export interface UploadFile {
  name: string;
  mimeType: string;
  buffer: Buffer;
}

const pdfBytes = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
const pngBytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
// Un zip vacío: es lo que hay dentro de un .docx, un .xlsx o un .odt a ojos del servidor.
const zipBytes = Buffer.concat([Buffer.from([0x50, 0x4b, 0x05, 0x06]), Buffer.alloc(18)]);

/** Ficheros de prueba de cada tipo. El nombre y el tipo declarado se pueden falsear; el contenido es el que es. */
export const files = {
  pdf: (name = 'informe.pdf', padding = 0): UploadFile => ({ name, mimeType: 'application/pdf', buffer: Buffer.concat([pdfBytes, Buffer.alloc(padding, 32)]) }),
  png: (name = 'foto.png'): UploadFile => ({ name, mimeType: 'image/png', buffer: pngBytes }),
  txt: (name = 'notas.txt'): UploadFile => ({ name, mimeType: 'text/plain', buffer: Buffer.from('Notas de la reunión.\nSegunda línea.\n') }),
  csv: (name = 'datos.csv'): UploadFile => ({ name, mimeType: 'text/csv', buffer: Buffer.from('nombre,importe\nAna,10\nLuis,20\n') }),
  docx: (name = 'contrato.docx'): UploadFile => ({
    name,
    mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    buffer: zipBytes,
  }),
  /** Con el nombre y el tipo declarado que se quiera, pero con contenido de texto. */
  fake: (name: string, mimeType: string): UploadFile => ({ name, mimeType, buffer: Buffer.from('Esto no es lo que dice su nombre.') }),
  pdfBytes,
  pngBytes,
};

const mailApi = `${env.mailpit.url}/api/v1`;

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/** Condición de una sola regla. */
export function when(field: string, operator: string, value = '', match: 'all' | 'any' = 'all'): Condition {
  return { match, rules: [{ field, operator, value }] };
}

/** Opciones de un desplegable, un grupo de radios o de casillas: el valor es el propio texto. */
export function options(...labels: string[]): { label: string; value: string }[] {
  return labels.map((label) => ({ label, value: label }));
}

export class Lab {
  /** Identificador de este test, único entre todos los que corren a la vez y entre ejecuciones. */
  readonly id: string;

  /** Dirección a la que llegan las notificaciones de los formularios de este test. */
  readonly mailTo: string;

  private contexts: BrowserContext[] = [];

  private constructor(
    readonly api: AdminApi,
    private readonly browser: Browser,
    info: TestInfo,
  ) {
    this.id = `m${Date.now().toString(36)}${info.parallelIndex}${Math.random().toString(36).slice(2, 6)}`;
    this.mailTo = `${this.id}@matriz.test`;
  }

  static async create(browser: Browser, info: TestInfo): Promise<Lab> {
    return new Lab(await AdminApi.create(), browser, info);
  }

  async dispose(): Promise<void> {
    await Promise.all(this.contexts.map((context) => context.close()));
    await this.api.dispose();
  }

  // --- Definiciones ---------------------------------------------------------------------------

  /** Otra dirección de correo de este test, para los formularios con varios destinatarios. */
  address(name: string): string {
    return `${this.id}-${name}@matriz.test`;
  }

  /** Notificación por correo a la dirección de este test, en texto plano. */
  email(settings: Record<string, unknown> = {}, extra: Partial<Action> = {}): Action {
    return {
      id: 'aviso',
      type: 'email',
      enabled: true,
      settings: { to: [this.mailTo], subject: 'Envío de {form_title}', body: '{all_fields}', format: 'text', ...settings },
      ...extra,
    };
  }

  /** Webhook al canal de este test del receptor del entorno. */
  webhook(settings: Record<string, unknown> = {}, extra: Partial<Action> = {}): Action {
    return { id: 'wh', type: 'webhook', enabled: true, name: 'Destino', settings: { url: this.sinkUrl(), ...settings }, ...extra };
  }

  /** Definición de un formulario: los campos indicados y, si no se dice otra cosa, una notificación. */
  definition(fields: FormField[], rest: Partial<Omit<FormDefinition, 'fields'>> = {}): FormDefinition {
    return {
      fields,
      settings: { submit_label: 'Enviar', ...(rest.settings ?? {}) },
      actions: rest.actions ?? [this.email()],
      ...(rest.style ? { style: rest.style } : {}),
    };
  }

  // --- Formularios y páginas ------------------------------------------------------------------

  async form(definition: FormDefinition, title = 'Formulario'): Promise<number> {
    return this.api.createForm(`${title} ${this.id}`, definition);
  }

  async page(content: string, title = 'Página'): Promise<string> {
    return (await this.api.createPage(`${title} ${this.id}`, content)).link;
  }

  /** Crea un formulario y una página que lo muestra, con el shortcode o con el bloque. */
  async formPage(
    definition: FormDefinition,
    embed: 'shortcode' | 'block' = 'shortcode',
    title = 'Formulario',
  ): Promise<{ formId: number; link: string }> {
    const formId = await this.form(definition, title);
    const content =
      embed === 'block'
        ? `<!-- wp:formulario-mensaje/form {"formId":${formId}} /-->`
        : `<!-- wp:shortcode -->[formulario_mensaje id="${formId}"]<!-- /wp:shortcode -->`;

    return { formId, link: await this.page(content, title) };
  }

  // --- Navegadores ----------------------------------------------------------------------------

  /** Página de un visitante anónimo, en un contexto propio. */
  async visitor(options: { javaScriptEnabled?: boolean; viewport?: { width: number; height: number } } = {}): Promise<Page> {
    const context = await this.browser.newContext({
      baseURL: env.baseUrl,
      locale: 'es-ES',
      storageState: { cookies: [], origins: [] },
      ...options,
    });

    this.contexts.push(context);

    return context.newPage();
  }

  /** Página con la sesión de administrador, en un contexto propio. */
  async admin(): Promise<Page> {
    const context = await this.browser.newContext({ baseURL: env.baseUrl, locale: 'es-ES', storageState: adminStorage });

    this.contexts.push(context);

    return context.newPage();
  }

  // --- Correo ---------------------------------------------------------------------------------

  /** Correos que han llegado a una dirección, sin esperar. */
  async mailsTo(address = this.mailTo): Promise<MailSummary[]> {
    const response = await fetch(`${mailApi}/search?query=${encodeURIComponent(`to:${address}`)}&limit=50`);

    return (await response.json()).messages;
  }

  /** Espera a que lleguen a una dirección los correos indicados y los devuelve completos, del más antiguo al más reciente. */
  async mail(address = this.mailTo, count = 1, timeoutMs = 20_000): Promise<MailDetail[]> {
    const deadline = Date.now() + timeoutMs;

    for (;;) {
      const found = await this.mailsTo(address);

      if (found.length >= count) {
        const details = await Promise.all(found.map(async (mail) => (await fetch(`${mailApi}/message/${mail.ID}`)).json() as Promise<MailDetail>));

        return details.reverse();
      }

      if (Date.now() > deadline) {
        throw new Error(`Han llegado ${found.length} correos a ${address}; se esperaban ${count}.`);
      }

      await sleep(300);
    }
  }

  /** Comprueba que a una dirección no llega nada, tras dar un margen para que llegase. */
  async expectNoMail(address = this.mailTo, settleMs = 2_500): Promise<void> {
    await sleep(settleMs);
    expect(await this.mailsTo(address), `No debía llegar ningún correo a ${address}`).toHaveLength(0);
  }

  // --- Webhooks -------------------------------------------------------------------------------

  /** Dirección del receptor de este test; con `status`, responde siempre con ese código. */
  sinkUrl(status?: number, channel = ''): string {
    return `${env.baseUrl}/wp-json/rt/v1/sink/${this.id}${channel ? `-${channel}` : ''}${status ? `?status=${status}` : ''}`;
  }

  async sink(channel = ''): Promise<SinkRequest[]> {
    return (await fetch(this.sinkUrl(undefined, channel))).json();
  }

  /** Espera a que el receptor de este test tenga al menos las peticiones indicadas. La cola las envía en segundo plano. */
  async waitForSink(count = 1, channel = '', timeoutMs = 45_000): Promise<SinkRequest[]> {
    const deadline = Date.now() + timeoutMs;

    for (;;) {
      const requests = await this.sink(channel);

      if (requests.length >= count) {
        return requests;
      }

      if (Date.now() > deadline) {
        throw new Error(`El receptor tiene ${requests.length} peticiones; se esperaban ${count}.`);
      }

      // La cola la mueve el WP-Cron del sitio, que se dispara con las visitas: se le da una.
      await fetch(`${env.baseUrl}/wp-cron.php?doing_wp_cron`).catch(() => undefined);
      await sleep(700);
    }
  }

  // --- Envíos guardados -----------------------------------------------------------------------

  /** Envíos guardados de un formulario, del más reciente al más antiguo. */
  async submissions(formId: number): Promise<Submitted[]> {
    return this.api.call('get', `/formulario-mensaje/v1/submissions?form=${formId}&per_page=100`);
  }

  async submission(id: number): Promise<SubmittedDetail> {
    return this.api.call('get', `/formulario-mensaje/v1/submissions/${id}`);
  }

  /** Espera a que un formulario tenga los envíos guardados indicados y devuelve el más reciente, con su detalle. */
  async lastSubmission(formId: number, count = 1): Promise<SubmittedDetail> {
    await expect.poll(async () => (await this.submissions(formId)).length, { timeout: 15_000 }).toBeGreaterThanOrEqual(count);

    return this.submission((await this.submissions(formId))[0].id);
  }

  // --- Envíos sin navegador -------------------------------------------------------------------

  /** Pide el token antispam de un formulario, como hace su script al empezar a usarlo. */
  async token(formId: number): Promise<string> {
    const anonymous = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: { cookies: [], origins: [] } });
    const token = (await (await anonymous.post(`/wp-json/formulario-mensaje/v1/forms/${formId}/token`)).json()).token;

    await anonymous.dispose();

    return token;
  }

  /**
   * Envía un formulario como un programa que habla con la REST API: pide el token y envía.
   * `waitMs` es lo que espera entre una cosa y otra; el servidor exige un mínimo.
   */
  async restSubmit(
    formId: number,
    fields: Record<string, string | string[]>,
    extra: { waitMs?: number; token?: string; honeypot?: string } = {},
  ): Promise<{ status: number; body: any; token: string }> {
    const anonymous = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: { cookies: [], origins: [] } });
    const base = `/wp-json/formulario-mensaje/v1/forms/${formId}`;
    const token = extra.token ?? (await (await anonymous.post(`${base}/token`)).json()).token;

    await sleep(extra.waitMs ?? 3_200);

    const response = await anonymous.post(`${base}/submissions`, {
      data: { fm: fields, token, fm_website: extra.honeypot ?? '' },
    });
    const result = { status: response.status(), body: await response.json().catch(() => null), token };

    await anonymous.dispose();

    return result;
  }

  /** Como `restSubmit`, pero con ficheros: una petición `multipart`, como la que hace el script del formulario. */
  async restSubmitFiles(
    formId: number,
    fields: Record<string, string>,
    files: Record<string, UploadFile[]>,
  ): Promise<{ status: number; body: any }> {
    const anonymous = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: { cookies: [], origins: [] } });
    const base = `/wp-json/formulario-mensaje/v1/forms/${formId}`;
    const data = new FormData();

    data.append('token', (await (await anonymous.post(`${base}/token`)).json()).token);

    for (const [field, value] of Object.entries(fields)) {
      data.append(`fm[${field}]`, value);
    }

    for (const [field, list] of Object.entries(files)) {
      for (const file of list) {
        data.append(`fm[${field}][]`, new Blob([file.buffer], { type: file.mimeType }), file.name);
      }
    }

    await sleep(3_200);

    const response = await anonymous.post(`${base}/submissions`, { multipart: data });
    const result = { status: response.status(), body: await response.json().catch(() => null) };

    await anonymous.dispose();

    return result;
  }

  /** Envía un formulario como un navegador sin JavaScript y devuelve el código, la redirección y la página de error, si la hay. */
  async postWithoutJs(
    formId: number,
    fields: Record<string, string | string[]>,
    extra: Record<string, string> = {},
  ): Promise<{ status: number; location: string; text: string }> {
    const anonymous = await playwrightRequest.newContext({ baseURL: env.baseUrl, storageState: { cookies: [], origins: [] } });
    const body = new URLSearchParams({ action: 'formulario_mensaje_submit', fm_form_id: String(formId), ...extra });

    for (const [field, value] of Object.entries(fields)) {
      if (Array.isArray(value)) {
        value.forEach((item) => body.append(`fm[${field}][]`, item));
      } else {
        body.append(`fm[${field}]`, value);
      }
    }

    const response = await anonymous.post('/wp-admin/admin-post.php', {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      data: body.toString(),
      maxRedirects: 0,
    });
    const result = { status: response.status(), location: response.headers().location ?? '', text: await response.text() };

    await anonymous.dispose();

    return result;
  }
}

/** `test` con el banco de pruebas de cada test ya montado y recogido al terminar. */
export const test = base.extend<{ lab: Lab }>({
  lab: async ({ browser }, use, testInfo) => {
    const lab = await Lab.create(browser, testInfo);

    await use(lab);
    await lab.dispose();
  },
});

export { expect };

/** Pulsa el botón de enviar y espera a que el formulario dé el envío por bueno. */
export async function send(page: Page, label = 'Enviar'): Promise<void> {
  await page.getByRole('button', { name: label, exact: true }).click();
  await expect(page.locator('.fm-success')).toBeVisible({ timeout: 20_000 });
}

/** Error que se muestra junto a un campo. */
export function fieldError(page: Page, fieldId: string) {
  return page.locator(`[data-fm-field="${fieldId}"] .fm-error`);
}

/** Envoltorio de un campo. */
export function field(page: Page, fieldId: string) {
  return page.locator(`[data-fm-field="${fieldId}"]`);
}
