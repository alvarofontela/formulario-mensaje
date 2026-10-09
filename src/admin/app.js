import { useCallback, useEffect, useState } from '@wordpress/element';

import FormEditor from './form-editor';
import FormsList from './forms-list';
import SettingsPage from './settings-page';
import { getQueryParam, setQueryParam } from './utils';

/**
 * Raíz de la aplicación. La pantalla de ajustes es fija; la de formularios alterna entre el listado
 * y el constructor según el parámetro `form` de la URL (`new` o el identificador del formulario).
 *
 * @param {Object} props
 * @param {string} props.initialView Vista que indica el servidor: `forms` o `settings`.
 */
export default function App( { initialView } ) {
	const [ formParam, setFormParam ] = useState( () =>
		getQueryParam( 'form' )
	);

	useEffect( () => {
		const onPopState = () => setFormParam( getQueryParam( 'form' ) );

		window.addEventListener( 'popstate', onPopState );

		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	const openForm = useCallback( ( id ) => {
		setQueryParam( 'form', id === null ? null : String( id ) );
		setFormParam( id === null ? null : String( id ) );
	}, [] );

	if ( initialView === 'settings' ) {
		return <SettingsPage />;
	}

	if ( formParam ) {
		return (
			<FormEditor
				// Al pasar de un formulario nuevo a su identificador ya guardado no se pierde el estado.
				formId={ formParam === 'new' ? 0 : Number( formParam ) }
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
