import { useCallback, useEffect, useState } from '@wordpress/element';

import FormEditor from './form-editor';
import FormsList from './forms-list';
import SettingsPage from './settings-page';
import SubmissionDetail from './submission-detail';
import SubmissionsPage from './submissions-page';
import { getQueryParam, setQueryParam } from './utils';

/**
 * Lee de la URL y mantiene en ella un parámetro que decide qué se muestra, para que el botón de
 * volver del navegador funcione dentro de la aplicación.
 *
 * @param {string} name Nombre del parámetro.
 * @return {[?string, Function]} Valor actual y función para cambiarlo (null lo quita).
 */
function useUrlParam( name ) {
	const [ value, setValue ] = useState( () => getQueryParam( name ) );

	useEffect( () => {
		const onPopState = () => setValue( getQueryParam( name ) );

		window.addEventListener( 'popstate', onPopState );

		return () => window.removeEventListener( 'popstate', onPopState );
	}, [ name ] );

	const change = useCallback(
		( next ) => {
			const text = next === null ? null : String( next );

			setQueryParam( name, text );
			setValue( text );
		},
		[ name ]
	);

	return [ value, change ];
}

/**
 * Raíz de la aplicación. El servidor indica la pantalla (`forms`, `submissions` o `settings`); dentro
 * de cada una, un parámetro de la URL decide si se ve el listado o un elemento concreto.
 *
 * @param {Object} props
 * @param {string} props.initialView Vista que indica el servidor.
 */
export default function App( { initialView } ) {
	const [ formParam, openForm ] = useUrlParam( 'form' );
	const [ submissionParam, openSubmission ] = useUrlParam( 'submission' );

	if ( initialView === 'settings' ) {
		return <SettingsPage />;
	}

	if ( initialView === 'submissions' ) {
		return submissionParam ? (
			<SubmissionDetail
				submissionId={ Number( submissionParam ) }
				onClose={ () => openSubmission( null ) }
			/>
		) : (
			<SubmissionsPage
				// En esta pantalla, `form` es el filtro del listado.
				initialFormId={ Number( formParam ) || 0 }
				onOpen={ openSubmission }
			/>
		);
	}

	if ( formParam ) {
		return (
			<FormEditor
				// Al pasar de un formulario nuevo a su identificador ya guardado no se pierde el estado.
				formId={
					formParam.startsWith( 'new' ) ? 0 : Number( formParam )
				}
				// Un formulario nuevo puede partir de una plantilla: `new:<plantilla>`.
				templateId={ formParam.split( ':' )[ 1 ] || '' }
				onClose={ () => openForm( null ) }
				onCreated={ ( id ) => {
					const url = new URL( window.location.href );

					url.searchParams.set( 'form', String( id ) );
					window.history.replaceState( {}, '', url );
				} }
			/>
		);
	}

	return <FormsList onOpen={ openForm } />;
}
