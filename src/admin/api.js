/**
 * Llamadas a las rutas REST del plugin. El nonce lo añade @wordpress/api-fetch.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { restBase } from './config';

const request = ( path, method = 'GET', data = undefined ) =>
	apiFetch( { path: restBase + path, method, data } );

/**
 * Pide una página de un listado y devuelve también el total, que viaja en las cabeceras.
 *
 * @param {string} path  Ruta del listado, sin el namespace.
 * @param {Object} query Filtros y paginación.
 * @return {Promise<{items: Array, total: number, pages: number}>} Elementos de la página y totales.
 */
async function paginated( path, query ) {
	const response = await apiFetch( {
		path: addQueryArgs( restBase + path, query ),
		parse: false,
	} );

	return {
		items: await response.json(),
		total: Number( response.headers.get( 'X-WP-Total' ) ) || 0,
		pages: Number( response.headers.get( 'X-WP-TotalPages' ) ) || 0,
	};
}

export const listForms = ( status ) =>
	apiFetch( { path: addQueryArgs( `${ restBase }/forms`, { status } ) } );
export const getForm = ( id ) => request( `/forms/${ id }` );
export const createForm = ( form ) => request( '/forms', 'POST', form );
export const updateForm = ( id, form ) =>
	request( `/forms/${ id }`, 'PUT', form );
export const trashForm = ( id ) => request( `/forms/${ id }`, 'DELETE' );
export const deleteForm = ( id, deleteSubmissions = false ) =>
	apiFetch( {
		path: addQueryArgs( `${ restBase }/forms/${ id }`, {
			force: true,
			delete_submissions: deleteSubmissions,
		} ),
		method: 'DELETE',
	} );
export const restoreForm = ( id ) =>
	request( `/forms/${ id }/restore`, 'POST' );
export const duplicateForm = ( id ) =>
	request( `/forms/${ id }/duplicate`, 'POST' );
export const exportForm = ( id ) => request( `/forms/${ id }/export` );
export const importForm = ( document ) =>
	request( '/forms/import', 'POST', { document } );
export const previewForm = ( form, signal ) =>
	apiFetch( {
		path: `${ restBase }/forms/preview`,
		method: 'POST',
		data: form,
		signal,
	} );

export const listSubmissions = ( query ) => paginated( '/submissions', query );
export const getSubmission = ( id ) => request( `/submissions/${ id }` );
export const deleteSubmissions = ( ids ) =>
	request( '/submissions/delete', 'POST', { ids } );
export const getSubmissionCounts = () => request( '/submissions/counts' );
export const markSubmissionsRead = ( ids, read = true ) =>
	request( '/submissions/read', 'POST', { ids, read } );
export const markAllSubmissionsRead = () =>
	request( '/submissions/read', 'POST', { all: true } );
export const listFailedDeliveries = ( query ) =>
	paginated( '/deliveries', query );
export const retryDelivery = ( id ) =>
	request( `/deliveries/${ id }/retry`, 'POST' );
export const dismissDelivery = ( id ) =>
	request( `/deliveries/${ id }/dismiss`, 'POST' );

export const getSettings = () => request( '/settings' );
export const saveSettings = ( settings ) =>
	request( '/settings', 'PUT', settings );
export const runMaintenance = () => request( '/maintenance', 'POST' );

export const getMailSettings = () => request( '/settings/mail' );
export const saveMailSettings = ( data ) =>
	request( '/settings/mail', 'PUT', data );
export const sendMailTest = ( to ) =>
	request( '/settings/mail/test', 'POST', { to } );
export const getCaptchaSettings = () => request( '/settings/captcha' );
export const saveCaptchaSettings = ( data ) =>
	request( '/settings/captcha', 'PUT', data );
export const sendTestEmail = ( data ) =>
	request( '/forms/test-email', 'POST', data );
export const sendTestWebhook = ( data ) =>
	request( '/forms/test-webhook', 'POST', data );
