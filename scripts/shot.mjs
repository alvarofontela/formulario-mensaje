// Captura de pantalla de una página del WordPress local, para revisar el aspecto mientras se desarrolla.
//
//   node scripts/shot.mjs <ruta-o-url> <salida.png> [--admin] [--full] [--width=1280] [--height=900]
//                         [--no-js] [--wait=<selector>] [--click=<selector>]... [--delay=<ms>]
//
// --admin inicia sesión como administrador. --click admite varios, que se pulsan en orden.
// Imprime los errores de consola y de página que haya visto, para detectarlos sin abrir el navegador.
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const env = JSON.parse( readFileSync( path.join( root, '.runtime/conf/env.json' ), 'utf8' ) );

process.env.PLAYWRIGHT_BROWSERS_PATH ??= path.join( root, '.runtime/ms-playwright' );

const [ target, output, ...flags ] = process.argv.slice( 2 );

if ( ! target || ! output ) {
	console.error( 'Uso: node scripts/shot.mjs <ruta-o-url> <salida.png> [opciones]' );
	process.exit( 1 );
}

const option = ( name, fallback ) => flags.find( ( flag ) => flag.startsWith( `--${ name }=` ) )?.split( '=' ).slice( 1 ).join( '=' ) ?? fallback;
const options = ( name ) => flags.filter( ( flag ) => flag.startsWith( `--${ name }=` ) ).map( ( flag ) => flag.split( '=' ).slice( 1 ).join( '=' ) );
const has = ( name ) => flags.includes( `--${ name }` );

const browser = await chromium.launch();
const context = await browser.newContext( {
	baseURL: env.baseUrl,
	locale: 'es-ES',
	viewport: { width: Number( option( 'width', 1280 ) ), height: Number( option( 'height', 900 ) ) },
	javaScriptEnabled: ! has( 'no-js' ),
} );
const page = await context.newPage();
const problems = [];

page.on( 'console', ( message ) => [ 'error', 'warning' ].includes( message.type() ) && problems.push( `[${ message.type() }] ${ message.text() }` ) );
page.on( 'pageerror', ( error ) => problems.push( `[pageerror] ${ error.message }` ) );
page.on( 'requestfailed', ( request ) => problems.push( `[requestfailed] ${ request.url() }` ) );

if ( has( 'admin' ) ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( env.admin.user );
	await page.locator( '#user_pass' ).fill( env.admin.password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForSelector( '#wpadminbar' );
}

const response = await page.goto( target, { waitUntil: 'networkidle' } );

if ( option( 'wait' ) ) {
	await page.waitForSelector( option( 'wait' ) );
}

for ( const selector of options( 'click' ) ) {
	await page.locator( selector ).first().click();
	await page.waitForTimeout( 400 );
}

await page.waitForTimeout( Number( option( 'delay', 300 ) ) );
await page.screenshot( { path: output, fullPage: has( 'full' ) } );

console.log( `HTTP ${ response?.status() } ${ page.url() }` );
console.log( problems.length ? problems.join( '\n' ) : 'Sin errores de consola.' );

await browser.close();
