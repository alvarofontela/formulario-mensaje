import { env } from './env';

export interface MailSummary {
  ID: string;
  Subject: string;
  From: { Name: string; Address: string };
  To: { Name: string; Address: string }[];
  Snippet: string;
}

const api = `${env.mailpit.url}/api/v1`;

export async function clearMail(): Promise<void> {
  await fetch(`${api}/messages`, { method: 'DELETE' });
}

export async function listMail(): Promise<MailSummary[]> {
  const response = await fetch(`${api}/messages`);
  return (await response.json()).messages;
}

/** Cuerpo completo del mensaje (campos Text y HTML, entre otros). */
export async function getMail(id: string): Promise<{ Text: string; HTML: string }> {
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
