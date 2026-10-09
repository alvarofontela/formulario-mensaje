import { env } from './env';

interface Address {
  Name: string;
  Address: string;
}

export interface MailSummary {
  ID: string;
  Subject: string;
  From: Address;
  To: Address[];
  Snippet: string;
}

export interface MailDetail {
  Subject: string;
  To: Address[];
  ReplyTo: Address[];
  Text: string;
  HTML: string;
}

const api = `${env.mailpit.url}/api/v1`;

export async function clearMail(): Promise<void> {
  await fetch(`${api}/messages`, { method: 'DELETE' });
}

export async function listMail(): Promise<MailSummary[]> {
  const response = await fetch(`${api}/messages`);
  return (await response.json()).messages;
}

/** Mensaje completo: destinatarios, dirección de respuesta y cuerpo en texto y en HTML. */
export async function getMail(id: string): Promise<MailDetail> {
  return (await fetch(`${api}/message/${id}`)).json();
}

/** Espera a que Mailpit capture un correo que cumpla la condición. */
export async function waitForMail(
  matches: (mail: MailSummary) => boolean,
  timeoutMs = 10_000,
): Promise<MailSummary> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    const found = (await listMail()).find(matches);
    if (found) return found;
    if (Date.now() > deadline) throw new Error('No ha llegado ningún correo que cumpla la condición.');
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
}

/** Correos capturados que cumplen la condición, tras dar un margen para que lleguen. */
export async function mailMatching(matches: (mail: MailSummary) => boolean, settleMs = 1_000): Promise<MailSummary[]> {
  await new Promise((resolve) => setTimeout(resolve, settleMs));
  return (await listMail()).filter(matches);
}
