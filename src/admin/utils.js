import { getFieldType } from './config';

/**
 * Genera un identificador de campo o de acción: un prefijo y ocho caracteres aleatorios.
 *
 * @param {string} prefix Prefijo: `f_` para campos, `a_` para acciones.
 * @return {string} Identificador con el formato que valida el servidor.
 */
export function createId( prefix ) {
	const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
	const values = window.crypto.getRandomValues( new Uint8Array( 8 ) );

	return (
		prefix +
		Array.from(
			values,
			( value ) => alphabet[ value % alphabet.length ]
		).join( '' )
	);
}

/**
 * Crea un campo nuevo de un tipo, con los valores por defecto de su esquema.
 *
 * @param {Object} type Descripción del tipo de campo.
 * @return {Object} Campo listo para añadir a la definición.
 */
export function createField( type ) {
	const settings = {};

	type.schema.forEach( ( control ) => {
		settings[ control.key ] = structuredClone( control.default ?? '' );
	} );

	return { id: createId( 'f_' ), type: type.type, settings };
}

/**
 * @param {Object} field Campo de la definición.
 * @return {string} Nombre con el que se muestra el campo en el constructor.
 */
export function fieldTitle( field ) {
	return (
		field.settings?.label || getFieldType( field.type )?.label || field.type
	);
}

/**
 * Mueve un elemento de una lista a otra posición, sin modificar la lista original.
 *
 * @param {Array}  list Lista.
 * @param {number} from Posición actual.
 * @param {number} to   Posición nueva.
 * @return {Array} Lista reordenada.
 */
export function moveItem( list, from, to ) {
	if ( to < 0 || to >= list.length || from === to ) {
		return list;
	}

	const next = [ ...list ];
	const [ item ] = next.splice( from, 1 );

	next.splice( to, 0, item );

	return next;
}

/**
 * Lee un parámetro de la URL de la pantalla.
 *
 * @param {string} name Nombre del parámetro.
 * @return {?string} Valor, o null si no está.
 */
export const getQueryParam = ( name ) =>
	new URLSearchParams( window.location.search ).get( name );

/**
 * Cambia un parámetro de la URL sin recargar la página, para que el botón de volver funcione.
 *
 * @param {string}  name  Nombre del parámetro.
 * @param {?string} value Valor nuevo, o null para quitarlo.
 */
export function setQueryParam( name, value ) {
	const url = new URL( window.location.href );

	if ( value === null ) {
		url.searchParams.delete( name );
	} else {
		url.searchParams.set( name, value );
	}

	window.history.pushState( {}, '', url );
}
