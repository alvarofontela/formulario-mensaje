import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ActionList from './action-list';
import { sendTestEmail } from './api';
import { userEmail } from './config';
import TagsHelp from './tags-help';
import { fieldTitle } from './utils';

const recipientsText = ( settings ) =>
	Array.isArray( settings.to ) ? settings.to.join( ', ' ) : settings.to || '';

/**
 * Envío de una notificación de prueba, con datos de ejemplo, a la dirección que se indique.
 *
 * Se prueba la notificación tal como está en pantalla, aunque el formulario no se haya guardado.
 *
 * @param {Object} props
 * @param {string} props.title      Nombre del formulario.
 * @param {Object} props.definition Definición en edición.
 * @param {string} props.actionId   Identificador de la notificación.
 */
function TestSender( { title, definition, actionId } ) {
	const [ to, setTo ] = useState( userEmail );
	const [ sending, setSending ] = useState( false );
	const [ result, setResult ] = useState( null );

	const send = () => {
		setSending( true );
		setResult( null );

		sendTestEmail( { title, definition, action_id: actionId, to } )
			.then( () =>
				setResult( {
					status: 'success',
					message: sprintf(
						/* translators: %s: email address. */
						__( 'Test sent to %s.', 'formulario-mensaje' ),
						to
					),
				} )
			)
			.catch( ( error ) =>
				setResult( { status: 'error', message: error.message } )
			)
			.finally( () => setSending( false ) );
	};

	return (
		<div className="fm-test">
			<div className="fm-options__row">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="email"
					label={ __( 'Send a test to', 'formulario-mensaje' ) }
					help={ __(
						'It is sent with sample data and only to this address, not to the recipients of the notification.',
						'formulario-mensaje'
					) }
					value={ to }
					onChange={ setTo }
				/>
				<Button
					__next40pxDefaultSize
					className="fm-test__button"
					variant="secondary"
					isBusy={ sending }
					disabled={ sending || ! to }
					onClick={ send }
				>
					{ __( 'Send test', 'formulario-mensaje' ) }
				</Button>
			</div>
			{ result && (
				<Notice status={ result.status } isDismissible={ false }>
					{ result.message }
				</Notice>
			) }
		</div>
	);
}

/**
 * Pestaña de notificaciones por correo. Cada formulario puede tener varias, y cada una decide con
 * una condición si se envía: así se avisa a un destinatario u otro según lo que se haya respondido.
 *
 * @param {Object}   props
 * @param {string}   props.title      Nombre del formulario.
 * @param {Object}   props.definition Definición en edición.
 * @param {Function} props.onChange   Recibe la lista de acciones nueva.
 */
export default function NotificationPanel( { title, definition, onChange } ) {
	const { fields } = definition;
	const emailFields = fields.filter( ( field ) => field.type === 'email' );

	return (
		<ActionList
			type="email"
			definition={ definition }
			onChange={ onChange }
			labels={ {
				empty: __(
					'This form does not send any notification.',
					'formulario-mensaje'
				),
				add: __( 'Add notification', 'formulario-mensaje' ),
				unavailable: __(
					'Email notifications are not available.',
					'formulario-mensaje'
				),
				condition: __( 'Send only if', 'formulario-mensaje' ),
				numbered: ( position ) =>
					sprintf(
						/* translators: %d: position of a notification in the list, starting at 1. */
						__( 'Notification %d', 'formulario-mensaje' ),
						position
					),
			} }
			summary={ ( action ) =>
				recipientsText( action.settings ) ||
				__( 'No recipients', 'formulario-mensaje' )
			}
			renderSettings={ ( { settings, set } ) => (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Send to', 'formulario-mensaje' ) }
						help={ __(
							'One or more email addresses, separated by commas.',
							'formulario-mensaje'
						) }
						value={ recipientsText( settings ) }
						// Se guarda como texto mientras se escribe; el servidor lo convierte en una lista de direcciones válidas.
						onChange={ ( value ) => set( 'to', value ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Subject', 'formulario-mensaje' ) }
						value={ settings.subject || '' }
						onChange={ ( value ) => set( 'subject', value ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Message', 'formulario-mensaje' ) }
						rows={ 8 }
						value={ settings.body || '' }
						onChange={ ( value ) => set( 'body', value ) }
					/>
					<TagsHelp
						fields={ fields }
						onInsert={ ( tag ) =>
							set( 'body', `${ settings.body || '' }${ tag }` )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Format', 'formulario-mensaje' ) }
						help={ __(
							'The HTML email uses the logo, color and footer from the plugin settings, and always includes a plain text version.',
							'formulario-mensaje'
						) }
						value={ settings.format || 'html' }
						options={ [
							{
								value: 'html',
								label: __(
									'HTML with the site’s branding',
									'formulario-mensaje'
								),
							},
							{
								value: 'text',
								label: __( 'Plain text', 'formulario-mensaje' ),
							},
						] }
						onChange={ ( value ) => set( 'format', value ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Reply to', 'formulario-mensaje' ) }
						help={ __(
							'Replying to the notification writes to the address the visitor entered in this field.',
							'formulario-mensaje'
						) }
						value={ settings.reply_to_field || '' }
						options={ [
							{
								value: '',
								label: __( 'Nobody', 'formulario-mensaje' ),
							},
							...emailFields.map( ( field ) => ( {
								value: field.id,
								label: fieldTitle( field ),
							} ) ),
						] }
						onChange={ ( value ) => set( 'reply_to_field', value ) }
					/>
				</>
			) }
			renderFooter={ ( { action } ) => (
				<TestSender
					title={ title }
					definition={ definition }
					actionId={ action.id }
				/>
			) }
		/>
	);
}
