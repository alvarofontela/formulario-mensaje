// Comprueba que las traducciones de plugin/languages/*.po están completas y se pueden publicar.
//
// Los .po son la fuente de las traducciones: se editan a mano o con Poedit. `rt i18n` fusiona en ellos la
// plantilla .pot y después ejecuta este script, que termina con error si a algún idioma le queda un texto
// sin traducir o dudoso («fuzzy»), o si una traducción no conserva los marcadores del original (`%s`,
// `%1$d`, `{reference}`): un marcador de menos deja un hueco en la frase y uno de más rompe `sprintf()`.
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const languages = path.join( root, 'plugin/languages' );

const unescape = ( text ) => JSON.parse( `"${ text.replace( /\\(?!["\\nrt])/g, '\\\\' ) }"` );

/**
 * Convierte una entrada del .po en un objeto con sus claves (`msgid`, `msgstr[0]`…) y sus marcas.
 *
 * @param {string} block Líneas de la entrada, tal como están en el fichero.
 * @return {{ flags: string[], values: Object<string, string> }} Entrada leída.
 */
function parse( block ) {
	const entry = { flags: [], values: {} };
	let key = null;

	for ( const line of block.split( '\n' ) ) {
		if ( line.startsWith( '#,' ) ) {
			entry.flags.push( ...line.slice( 2 ).split( ',' ).map( ( flag ) => flag.trim() ) );
			continue;
		}

		// Comentarios, referencias y entradas obsoletas (`#~`), que ya no están en el código.
		if ( line.startsWith( '#' ) ) {
			continue;
		}

		const start = line.match( /^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) "(.*)"$/ );

		if ( start ) {
			key = start[ 1 ];
			entry.values[ key ] = unescape( start[ 2 ] );
		} else if ( key && /^".*"$/.test( line ) ) {
			entry.values[ key ] += unescape( line.slice( 1, -1 ) );
		}
	}

	return entry;
}

/**
 * Marcadores de un texto, ordenados: los de `sprintf()` y las etiquetas entre llaves.
 *
 * @param {string} text Texto original o traducido.
 * @return {string} Marcadores separados por espacios.
 */
function placeholders( text ) {
	return ( text.replace( /%%/g, '' ).match( /%(?:\d+\$)?[sdfu]|\{[a-z_]+(?::[A-Za-z_]+)?\}/g ) ?? [] ).sort().join( ' ' );
}

let failed = false;
const files = readdirSync( languages ).filter( ( name ) => name.endsWith( '.po' ) );

if ( ! files.length ) {
	console.error( 'No hay ningún .po en plugin/languages.' );
	process.exit( 1 );
}

for ( const file of files ) {
	const problems = [];
	let translated = 0;

	const blocks = readFileSync( path.join( languages, file ), 'utf8' ).replace( /\r\n/g, '\n' ).trim().split( /\n\n+/ );

	for ( const block of blocks ) {
		const { flags, values } = parse( block );
		const id = values.msgid;

		// La entrada con msgid vacío es la cabecera; una sin msgid es un bloque de obsoletas.
		if ( ! id ) {
			continue;
		}

		const label = JSON.stringify( id );
		const targets = Object.keys( values ).filter( ( key ) => key.startsWith( 'msgstr' ) );
		const sources = [ id, values.msgid_plural ].filter( ( text ) => typeof text === 'string' ).map( placeholders );

		if ( ! targets.length || targets.some( ( key ) => values[ key ] === '' ) ) {
			problems.push( `sin traducir: ${ label }` );
		} else if ( flags.includes( 'fuzzy' ) ) {
			problems.push( `dudosa (fuzzy), hay que revisarla y quitarle la marca: ${ label }` );
		} else if ( targets.some( ( key ) => ! sources.includes( placeholders( values[ key ] ) ) ) ) {
			problems.push( `los marcadores no coinciden con los del original: ${ label }` );
		} else {
			translated++;
		}
	}

	console.log( `${ file }: ${ translated } textos traducidos.` );

	if ( problems.length ) {
		failed = true;
		console.error( `  ${ problems.length } por resolver en plugin/languages/${ file }:\n    ${ problems.join( '\n    ' ) }` );
	}
}

process.exit( failed ? 1 : 0 );
