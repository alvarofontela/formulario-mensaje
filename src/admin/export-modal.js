import {
	Button,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { urls } from './config';

/**
 * Ventana para exportar a CSV los mensajes de un formulario, con un rango de fechas opcional.
 *
 * El CSV tiene una columna por campo, así que se exporta formulario a formulario. La descarga es una
 * navegación normal a `admin-post.php`, con el nonce que entrega el servidor.
 *
 * @param {Object}   props
 * @param {Array}    props.forms         Formularios entre los que elegir.
 * @param {number}   props.initialFormId Formulario elegido al abrir, o 0.
 * @param {Function} props.onClose       Cierra la ventana.
 */
export default function ExportModal( { forms, initialFormId, onClose } ) {
	const [ formId, setFormId ] = useState(
		initialFormId || forms[ 0 ]?.id || 0
	);
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );

	return (
		<Modal
			title={ __( 'Export messages', 'formulario-mensaje' ) }
			onRequestClose={ onClose }
			size="small"
		>
			<p>
				{ __(
					'Downloads a CSV file with the messages of one form. Leave the dates empty to export all of them.',
					'formulario-mensaje'
				) }
			</p>
			<div className="fm-settings">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Form', 'formulario-mensaje' ) }
					value={ formId }
					options={ forms.map( ( form ) => ( {
						value: form.id,
						label: form.title,
					} ) ) }
					onChange={ ( value ) => setFormId( Number( value ) ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'From', 'formulario-mensaje' ) }
					value={ from }
					onChange={ setFrom }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'To', 'formulario-mensaje' ) }
					value={ to }
					onChange={ setTo }
				/>
			</div>
			<div className="fm-admin__modal-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
				>
					{ __( 'Cancel', 'formulario-mensaje' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={ ! formId }
					href={ addQueryArgs( urls.export, {
						form: formId,
						from,
						to,
					} ) }
					// La ventana se cierra cuando el navegador ya ha empezado la descarga.
					onClick={ () => setTimeout( onClose, 200 ) }
				>
					{ __( 'Download CSV', 'formulario-mensaje' ) }
				</Button>
			</div>
		</Modal>
	);
}
