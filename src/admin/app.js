import FormEditor from './form-editor';
import FormsList from './forms-list';
import { useUrlParam } from './hooks';
import Inbox from './inbox';
import SettingsPage from './settings-page';

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
		return (
			<Inbox
				// En esta pantalla, `form` es el filtro del listado.
				initialFormId={ Number( formParam ) || 0 }
				openId={ Number( submissionParam ) || 0 }
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
