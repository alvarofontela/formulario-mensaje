import { env } from './env';

/**
 * Doble de GitHub del entorno local (ver scripts/templates/rt-environment.php): mientras haya una
 * versión simulada, el plugin la recibe como «la última publicada» sin salir del equipo.
 */

const releaseUrl = `${env.baseUrl}/wp-json/rt/v1/release`;

/** Contenido de release.json, como lo genera scripts/release.py al publicar una versión. */
export interface ReleaseManifest {
  version: string;
  requires?: string;
  requires_php?: string;
  tested?: string;
  released?: string;
  changelog?: string;
}

/** Simula que esa es la última versión publicada y hace que WordPress vuelva a buscar actualizaciones. */
export async function simulateRelease(manifest: ReleaseManifest): Promise<void> {
  await fetch(releaseUrl, { method: 'POST', body: JSON.stringify(manifest) });
}

/** Deja de simular: la consulta vuelve a ir a GitHub. */
export async function clearSimulatedRelease(): Promise<void> {
  await fetch(releaseUrl, { method: 'DELETE' });
}
