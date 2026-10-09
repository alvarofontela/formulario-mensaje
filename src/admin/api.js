/**
 * Llamadas a las rutas REST del plugin. El nonce lo añade @wordpress/api-fetch.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { restBase } from './config';

const request = ( path, method = 'GET', data = undefined ) =>
	apiFetch( { path: restBase + path, method, data } );

export const listForms = ( status ) =>
	apiFetch( { path: addQueryArgs( `${ restBase }/forms`, { status } ) } );
export const getForm = ( id ) => request( `/forms/${ id }` );
export const createForm = ( form ) => request( '/forms', 'POST', form );
export const updateForm = ( id, form ) =>
	request( `/forms/${ id }`, 'PUT', form );
export const trashForm = ( id ) => request( `/forms/${ id }`, 'DELETE' );
export const deleteForm = ( id ) =>
	apiFetch( {
		path: addQueryArgs( `${ restBase }/forms/${ id }`, { force: true } ),
		method: 'DELETE',
	} );
export const restoreForm = ( id ) =>
	request( `/forms/${ id }/restore`, 'POST' );
export const previewForm = ( form, signal ) =>
	apiFetch( {
		path: `${ restBase }/forms/preview`,
		method: 'POST',
		data: form,
		signal,
	} );
export const getSettings = () => request( '/settings' );
export const saveSettings = ( settings ) =>
	request( '/settings', 'PUT', settings );
