import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { getActionType } from './config';
import TagsHelp from './tags-help';
import { createId, fieldTitle } from './utils';

const EMAIL = 'email';

/**
 * Pestaña de la notificación por correo del formulario.
 *
 * La definición guarda una lista de acciones; esta pantalla edita la primera de tipo correo.
 *
 * @param {Object}   props
 * @param {Array}    props.actions  Acciones de la definición.
 * @param {Array}    props.fields   Campos del formulario.
 * @param {Function} props.onChange Recibe la lista de acciones nueva.
 */
export default function NotificationPanel( { actions, fields, onChange } ) {
	const type = getActionType( EMAIL );
	const index = actions.findIndex( ( action ) => action.type === EMAIL );
	const action = index === -1 ? null : actions[ index ];

	if ( ! type ) {
		return (
			<p>
				{ __(
					'Email notifications are not available.',
					'formulario-mensaje'
				) }
			</p>
		);
	}

	if ( ! action ) {
		return (
			<div className="fm-settings">
				<p>
					{ __(
						'This form does not send any notification.',
						'formulario-mensaje'
					) }
				</p>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () =>
						onChange( [
							...actions,
							{
								id: createId( 'a_' ),
								type: EMAIL,
								enabled: true,
								settings: structuredClone( type.defaults ),
							},
						] )
					}
				>
					{ __( 'Add email notification', 'formulario-mensaje' ) }
				</Button>
			</div>
		);
	}

	const update = ( patch ) =>
		onChange(
			actions.map( ( item, position ) =>
				position === index ? { ...item, ...patch } : item
			)
		);
	const set = ( key, value ) =>
		update( { settings: { ...action.settings, [ key ]: value } } );
	const settings = action.settings;
	const emailFields = fields.filter( ( field ) => field.type === 'email' );
	const recipients = Array.isArray( settings.to )
		? settings.to.join( ', ' )
		: settings.to || '';

	return (
		<div className="fm-settings">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Send an email with each submission',
					'formulario-mensaje'
				) }
				checked={ !! action.enabled }
				onChange={ ( enabled ) => update( { enabled } ) }
			/>

			{ action.enabled && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Send to', 'formulario-mensaje' ) }
						help={ __(
							'One or more email addresses, separated by commas.',
							'formulario-mensaje'
						) }
						value={ recipients }
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
		</div>
	);
}
