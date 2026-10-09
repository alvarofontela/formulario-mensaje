// Genera plugin/languages/<slug>-<locale>.po a partir de la plantilla .pot y de scripts/i18n/<locale>.json.
//
// El JSON es la fuente de las traducciones: { "msgid": "traducción" } y, para plurales,
// { "msgid singular": ["singular", "plural"] }. Termina con error si falta alguna traducción, para que
// ningún texto de la interfaz llegue sin traducir (regla 5 de CLAUDE.md). Lo ejecuta `rt i18n`.
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const slug = process.argv[ 2 ] || 'formulario-mensaje';
const languages = path.join( root, 'plugin/languages' );
const sources = path.join( root, 'scripts/i18n' );

const unescape = ( text ) => JSON.parse( `"${ text.replace( /\\(?!["\\nrt])/g, '\\\\' ) }"` );
const escape = ( text ) => JSON.stringify( text ).slice( 1, -1 );

/**
 * Lee el valor de una clave de una entrada del .pot, uniendo las líneas de continuación.
 *
 * @param {string[]} lines Líneas de la entrada.
 * @param {string}   key   `msgid`, `msgid_plural` o `msgctxt`.
 * @return {?string} Texto sin escapar, o null si la entrada no tiene esa clave.
 */
function read( lines, key ) {
	const start = lines.findIndex( ( line ) => line.startsWith( `${ key } "` ) );

	if ( start === -1 ) {
		return null;
	}

	let value = lines[ start ].slice( key.length + 2, -1 );

	for ( let index = start + 1; index < lines.length && lines[ index ].startsWith( '"' ); index++ ) {
		value += lines[ index ].slice( 1, -1 );
	}

	return unescape( value );
}

const entries = readFileSync( path.join( languages, `${ slug }.pot` ), 'utf8' )
	.replace( /\r\n/g, '\n' )
	.trim()
	.split( /\n\n+/ )
	.map( ( block ) => block.split( '\n' ) );

let failed = false;

for ( const file of readdirSync( sources ).filter( ( name ) => name.endsWith( '.json' ) ) ) {
	const locale = path.basename( file, '.json' );
	const translations = JSON.parse( readFileSync( path.join( sources, file ), 'utf8' ) );
	const missing = [];
	const used = new Set();

	const output = [
		`# Traducción de ${ slug } a ${ locale }. Generado por scripts/i18n-build.mjs: no editar a mano.`,
		'msgid ""',
		'msgstr ""',
		`"Project-Id-Version: ${ slug }\\n"`,
		'"MIME-Version: 1.0\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		`"Language: ${ locale }\\n"`,
		'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
		`"X-Domain: ${ slug }\\n"`,
		'',
	];

	for ( const lines of entries ) {
		const id = read( lines, 'msgid' );
		const plural = read( lines, 'msgid_plural' );

		// La primera entrada, con msgid vacío, es la cabecera de la plantilla.
		if ( ! id ) {
			continue;
		}

		const translation = translations[ id ];
		const valid = plural === null ? typeof translation === 'string' : Array.isArray( translation ) && translation.length === 2;

		if ( ! valid ) {
			missing.push( id );
			continue;
		}

		used.add( id );
		output.push( ...lines.filter( ( line ) => line.startsWith( '#' ) || line.startsWith( 'msgctxt' ) ) );
		output.push( `msgid "${ escape( id ) }"` );

		if ( plural === null ) {
			output.push( `msgstr "${ escape( translation ) }"` );
		} else {
			output.push( `msgid_plural "${ escape( plural ) }"`, `msgstr[0] "${ escape( translation[ 0 ] ) }"`, `msgstr[1] "${ escape( translation[ 1 ] ) }"` );
		}

		output.push( '' );
	}

	writeFileSync( path.join( languages, `${ slug }-${ locale }.po` ), output.join( '\n' ) );

	const unused = Object.keys( translations ).filter( ( id ) => ! used.has( id ) );

	console.log( `${ locale }: ${ used.size } textos traducidos.` );

	if ( unused.length ) {
		console.log( `  Traducciones que ya no se usan (se pueden borrar de ${ file }):\n    ${ unused.join( '\n    ' ) }` );
	}

	if ( missing.length ) {
		failed = true;
		console.error( `  FALTAN ${ missing.length } traducciones en scripts/i18n/${ file }:\n    ${ missing.map( ( id ) => JSON.stringify( id ) ).join( '\n    ' ) }` );
	}
}

process.exit( failed ? 1 : 0 );
