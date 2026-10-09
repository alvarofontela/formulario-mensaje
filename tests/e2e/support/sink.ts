import { env } from './env';

/**
 * Receptor de webhooks del entorno local (ver scripts/templates/rt-environment.php): guarda lo que
 * recibe y responde con el código que se le pida.
 */

export interface SinkRequest {
  method: string;
  /** Cabeceras con el nombre en minúsculas y guiones bajos: `x_formulario_mensaje_signature`. */
  headers: Record<string, string>;
  body: string;
}

/** Dirección del receptor, la que se pone como destino de un webhook. */
export const sinkUrl = `${env.baseUrl}/wp-json/rt/v1/sink`;

/** Vacía el receptor y fija el código con el que responderá a partir de ahora. */
export async function resetSink(status = 200): Promise<void> {
  await fetch(`${sinkUrl}?status=${status}`, { method: 'DELETE' });
}

export async function sinkRequests(): Promise<SinkRequest[]> {
  return (await fetch(sinkUrl)).json();
}

/** Espera a que el receptor tenga al menos las peticiones indicadas. La cola las envía en segundo plano. */
export async function waitForSink(count = 1, timeoutMs = 30_000): Promise<SinkRequest[]> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    const requests = await sinkRequests();
    if (requests.length >= count) return requests;
    if (Date.now() > deadline) throw new Error(`El receptor tiene ${requests.length} peticiones; se esperaban ${count}.`);
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
}
