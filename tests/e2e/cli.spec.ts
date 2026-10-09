import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { env } from './support/env';
import { AdminApi, contactDefinition, submitWithoutJs } from './support/forms';
import { resetSink, sinkUrl, waitForSink } from './support/sink';

// Comandos de WP-CLI, ejecutados con el WP-CLI de verdad contra el WordPress del entorno.

// Carpeta de trabajo de los comandos, dentro del proyecto: las rutas se pasan relativas a su raíz.
const workDir = '.runtime/tmp/cli';
const absolute = (file: string) => path.join(env.paths.root, file);

let api: AdminApi;

test.beforeAll(async () => {
  api = await AdminApi.create();
  rmSync(absolute(workDir), { recursive: true, force: true });
  mkdirSync(absolute(workDir), { recursive: true });
});

test.afterAll(async () => {
  await api.dispose();
  rmSync(absolute(workDir), { recursive: true, force: true });
});

/** Ejecuta `wp formulario-mensaje …` y devuelve su salida y su código de salida. */
function wp(...args: string[]): { code: number; stdout: string; stderr: string } {
  const result = spawnSync(
    'pwsh',
    ['-NoLogo', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', absolute('scripts/rt.ps1'), 'wp', 'formulario-mensaje', ...args],
    { cwd: env.paths.root, encoding: 'utf8' },
  );

  return { code: result.status ?? -1, stdout: result.stdout, stderr: result.stderr };
}

test('los formularios se listan, se exportan sin secretos y se importan en pausa', async () => {
  const definition = contactDefinition();
  definition.actions.push({
    id: 'wh',
    type: 'webhook',
    enabled: true,
    name: 'CRM',
    settings: { url: sinkUrl, secret: 'secreto-compartido', headers: [{ name: 'X-Api-Key', value: 'clave-del-crm' }] },
  });

  const title = `CLI e2e ${Date.now()}`;
  const formId = await api.createForm(title, definition);

  // Listar.
  const list = wp('form', 'list', '--format=json');
  expect(list.code).toBe(0);

  const listed = (JSON.parse(list.stdout) as { id: number; title: string; fields: number; actions: number }[]).find((form) => form.id === formId);
  expect(listed).toMatchObject({ title, fields: 3, actions: 2 });

  expect(wp('form', 'list', '--format=ids').stdout.trim().split(' ')).toContain(String(formId));
  expect(wp('form', 'list', '--status=trash', '--format=ids').stdout.trim().split(' ')).not.toContain(String(formId));

  // Exportar a un fichero: el mismo documento que la administración, sin secretos.
  const file = `${workDir}/form.json`;
  const exported = wp('form', 'export', String(formId), `--file=${file}`);

  expect(exported.code).toBe(0);
  expect(exported.stdout).toContain(`Formulario exportado a ${file}.`);

  const json = readFileSync(absolute(file), 'utf8');
  const document = JSON.parse(json);

  expect(document.format).toBe('formulario-mensaje-form');
  expect(document.title).toBe(title);
  expect(json).toContain('X-Api-Key');
  expect(json).not.toContain('clave-del-crm');
  expect(json).not.toContain('secreto-compartido');
  expect(json).not.toContain('fm1:');

  // Sin --file, el JSON va a la salida.
  expect(JSON.parse(wp('form', 'export', String(formId)).stdout).title).toBe(title);

  // Importar: siempre un formulario nuevo, con sus acciones en pausa.
  const imported = wp('form', 'import', file, '--porcelain');
  expect(imported.code).toBe(0);

  const newId = Number(imported.stdout.trim());
  expect(newId).toBeGreaterThan(formId);

  const copy = await api.getForm(newId);
  expect(copy.title).toBe(title);
  expect(copy.definition.fields.map((field) => field.id)).toEqual(['nombre', 'correo', 'mensaje']);
  expect(copy.definition.actions.map((action) => action.enabled)).toEqual([false, false]);

  expect(wp('form', 'import', file).stdout).toMatch(/Formulario importado con el identificador \d+\. Sus acciones quedan en pausa/);
});

test('los errores de los comandos se explican y devuelven un código de salida distinto de cero', async () => {
  const missing = wp('form', 'export', '99999999');
  expect(missing.code).not.toBe(0);
  expect(missing.stderr).toContain('El formulario no existe.');

  const notFound = wp('form', 'import', `${workDir}/no-existe.json`);
  expect(notFound.code).not.toBe(0);
  expect(notFound.stderr).toContain('No se ha podido leer el fichero');

  // Un fichero que no es una exportación del plugin: lo rechaza el mismo servicio que en la administración.
  writeFileSync(absolute(`${workDir}/otro.json`), JSON.stringify({ title: 'Colado', definition: { fields: [] } }));
  const invalid = wp('form', 'import', `${workDir}/otro.json`);
  expect(invalid.code).not.toBe(0);
  expect(invalid.stderr).toContain('El fichero no es un formulario exportado desde Formulario Mensaje.');

  writeFileSync(absolute(`${workDir}/roto.json`), '{ esto no es JSON');
  expect(wp('form', 'import', `${workDir}/roto.json`).code).not.toBe(0);

  // Una dirección no es un fichero local.
  const remote = wp('form', 'import', 'https://example.org/form.json');
  expect(remote.code).not.toBe(0);
  expect(remote.stderr).toContain('No se ha podido leer el fichero');

  const badDate = wp('submission', 'export', '1', '--from=ayer');
  expect(badDate.code).not.toBe(0);
});

test('los envíos de un formulario se exportan a CSV con las fórmulas neutralizadas', async () => {
  const formId = await api.createForm('CLI envíos e2e');

  await submitWithoutJs(formId, { nombre: '=1+1', correo: 'ana@example.test', mensaje: 'Quiero un presupuesto' });

  const file = `${workDir}/envios.csv`;
  const exported = wp('submission', 'export', String(formId), `--file=${file}`);

  expect(exported.code).toBe(0);
  expect(exported.stdout).toContain(`1 envío exportado a ${file}.`);

  const csv = readFileSync(absolute(file), 'utf8');

  expect(csv.charCodeAt(0)).toBe(0xfeff);
  expect(csv.split('\n')[0]).toContain('Nombre,Correo,Mensaje');
  expect(csv).toContain("'=1+1");
  expect(csv).toContain('ana@example.test');

  // Sin --file, el CSV va a la salida, sin nada más.
  const printed = wp('submission', 'export', String(formId));
  expect(printed.stdout).toContain('ana@example.test');
  expect(printed.stdout).not.toContain('Success');

  // Con un intervalo de fechas que no incluye hoy, solo la cabecera.
  const old = `${workDir}/antiguos.csv`;
  expect(wp('submission', 'export', String(formId), '--to=2020-01-01', `--file=${old}`).stdout).toContain('0 envíos exportados');
  expect(readFileSync(absolute(old), 'utf8')).not.toContain('ana@example.test');
  expect(existsSync(absolute(old))).toBe(true);
});

test('la cola se procesa a mano y el webhook pendiente llega a su destino', async () => {
  await resetSink();

  const formId = await api.createForm(
    'CLI cola e2e',
    contactDefinition({ actions: [{ id: 'wh', type: 'webhook', enabled: true, name: 'CRM', settings: { url: sinkUrl } }] }),
  );

  await submitWithoutJs(formId, { nombre: 'Ana', correo: 'ana@example.test' });

  // El WP-Cron del sitio puede haberse adelantado: el comando informa de las que le han tocado a él.
  const processed = wp('queue', 'process');
  expect(processed.code).toBe(0);
  expect(processed.stdout).toMatch(/Success: \d+ entregas? procesadas?\./);

  const requests = await waitForSink(1);
  expect(JSON.parse(requests[0].body).fields.nombre).toBe('Ana');

  // Repetirlo no hace daño ni reenvía nada.
  expect(wp('queue', 'process').stdout).toContain('0 entregas procesadas.');
  expect(await waitForSink(1)).toHaveLength(1);
});
