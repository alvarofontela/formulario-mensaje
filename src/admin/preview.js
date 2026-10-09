import { Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { previewForm } from './api';

const DEBOUNCE_MS = 400;

/**
 * Documento de la vista previa: el HTML que genera el servidor con los estilos del formulario y del tema.
 *
 * @param {Object} preview Respuesta de la ruta de vista previa.
 * @return {string} Documento HTML completo para el `srcdoc` del iframe.
 */
function buildDocument( preview ) {
	const links = preview.stylesheets
		.map(
			( href ) => `<link rel="stylesheet" href="${ encodeURI( href ) }">`
		)
		.join( '' );

	return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${ preview.theme_css }</style>${ links }<style>body{margin:0;padding:24px;}</style></head><body>${ preview.html }</body></html>`;
}

/**
 * Vista previa en vivo. La genera el mismo código de PHP que pinta el formulario en la web, así que
 * lo que se ve aquí es lo que verá el visitante.
 *
 * @param {Object} props
 * @param {string} props.title      Nombre del formulario.
 * @param {Object} props.definition Definición en edición.
 */
export default function Preview( { title, definition } ) {
	const [ preview, setPreview ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const frameRef = useRef( null );
	const [ height, setHeight ] = useState( 320 );

	useEffect( () => {
		const controller = new AbortController();

		// Se espera a que el usuario deje de escribir para no pedir una vista previa por cada tecla.
		const timer = setTimeout( () => {
			setLoading( true );

			previewForm( { title, definition }, controller.signal )
				.then( ( response ) => {
					setPreview( response );
					setError( '' );
				} )
				.catch( ( failure ) => {
					if ( failure?.name !== 'AbortError' ) {
						setError(
							failure?.message ||
								__(
									'The preview could not be loaded.',
									'formulario-mensaje'
								)
						);
					}
				} )
				.finally(
					() => ! controller.signal.aborted && setLoading( false )
				);
		}, DEBOUNCE_MS );

		return () => {
			clearTimeout( timer );
			controller.abort();
		};
	}, [ title, definition ] );

	return (
		<div className="fm-preview">
			<div className="fm-preview__bar">
				<h2 className="fm-preview__title">
					{ __( 'Preview', 'formulario-mensaje' ) }
				</h2>
				{ loading && <Spinner /> }
			</div>
			{ error && <p className="fm-preview__error">{ error }</p> }
			{ preview && (
				<iframe
					ref={ frameRef }
					className="fm-preview__frame"
					title={ __( 'Form preview', 'formulario-mensaje' ) }
					// Sin scripts ni envíos: la vista previa solo se mira. `allow-same-origin` permite medir su altura.
					sandbox="allow-same-origin"
					srcDoc={ buildDocument( preview ) }
					style={ { height } }
					onLoad={ () => {
						const body = frameRef.current?.contentDocument?.body;

						if ( body ) {
							setHeight( Math.max( 200, body.scrollHeight + 8 ) );
						}
					} }
				/>
			) }
		</div>
	);
}
