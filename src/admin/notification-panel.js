import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { sendTestEmail } from './api';
import ConditionEditor from './condition-editor';
import { getActionType, userEmail } from './config';
import TagsHelp from './tags-help';
import { createId, fieldTitle } from './utils';

const EMAIL = 'email';

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
 * La definición guarda una lista de acciones de varios tipos; aquí se editan las de correo.
 *
 * @param {Object}   props
 * @param {string}   props.title      Nombre del formulario.
 * @param {Object}   props.definition Definición en edición.
 * @param {Function} props.onChange   Recibe la lista de acciones nueva.
 */
export default function NotificationPanel( { title, definition, onChange } ) {
	const type = getActionType( EMAIL );
	const { actions, fields } = definition;
	// Con una sola notificación, que es lo habitual, se muestra ya abierta.
	const [ openId, setOpenId ] = useState( () => {
		const emails = actions.filter( ( action ) => action.type === EMAIL );

		return emails.length === 1 ? emails[ 0 ].id : null;
	} );

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

	const emailFields = fields.filter( ( field ) => field.type === 'email' );
	const notifications = actions.filter( ( action ) => action.type === EMAIL );

	const update = ( id, patch ) =>
		onChange(
			actions.map( ( action ) =>
				action.id === id ? { ...action, ...patch } : action
			)
		);

	const add = () => {
		const action = {
			id: createId( 'a_' ),
			type: EMAIL,
			enabled: true,
			name: '',
			condition: { match: 'all', rules: [] },
			settings: structuredClone( type.defaults ),
		};

		onChange( [ ...actions, action ] );
		setOpenId( action.id );
	};

	const duplicate = ( action ) => {
		const copy = { ...structuredClone( action ), id: createId( 'a_' ) };
		const next = [ ...actions ];

		next.splice( actions.indexOf( action ) + 1, 0, copy );
		onChange( next );
		setOpenId( copy.id );
	};

	return (
		<div className="fm-fields">
			{ notifications.length === 0 && (
				<p className="fm-fields__empty">
					{ __(
						'This form does not send any notification.',
						'formulario-mensaje'
					) }
				</p>
			) }

			<ol className="fm-fields__list">
				{ notifications.map( ( action, index ) => {
					const isOpen = openId === action.id;
					const settings = action.settings;
					const set = ( key, value ) =>
						update( action.id, {
							settings: { ...settings, [ key ]: value },
						} );
					const recipients = Array.isArray( settings.to )
						? settings.to.join( ', ' )
						: settings.to || '';
					const name =
						action.name ||
						sprintf(
							/* translators: %d: position of a notification in the list, starting at 1. */
							__( 'Notification %d', 'formulario-mensaje' ),
							index + 1
						);
					const hasCondition = action.condition?.rules?.length > 0;

					return (
						<li
							key={ action.id }
							className={ `fm-fields__item${ isOpen ? ' is-open' : '' }` }
						>
							<div className="fm-fields__row">
								<button
									type="button"
									className="fm-fields__toggle"
									aria-expanded={ isOpen }
									onClick={ () =>
										setOpenId( isOpen ? null : action.id )
									}
								>
									<span className="fm-fields__name">
										{ name }
									</span>
									<span className="fm-fields__type">
										{ [
											action.enabled
												? recipients ||
													__(
														'No recipients',
														'formulario-mensaje'
													)
												: __(
														'Turned off',
														'formulario-mensaje'
													),
											hasCondition
												? __(
														'With condition',
														'formulario-mensaje'
													)
												: '',
										]
											.filter( Boolean )
											.join( ' · ' ) }
									</span>
								</button>
								<div className="fm-fields__tools">
									<Button
										size="small"
										icon="admin-page"
										label={ sprintf(
											/* translators: %s: name of a notification. */
											__(
												'Duplicate “%s”',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () => duplicate( action ) }
									/>
									<Button
										size="small"
										icon="trash"
										isDestructive
										label={ sprintf(
											/* translators: %s: name of a notification. */
											__(
												'Delete “%s”',
												'formulario-mensaje'
											),
											name
										) }
										onClick={ () =>
											onChange(
												actions.filter(
													( item ) =>
														item.id !== action.id
												)
											)
										}
									/>
								</div>
							</div>

							{ isOpen && (
								<div className="fm-fields__settings">
									<div className="fm-settings">
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Name',
												'formulario-mensaje'
											) }
											help={ __(
												'Only to tell this notification apart from the others.',
												'formulario-mensaje'
											) }
											value={ action.name || '' }
											onChange={ ( value ) =>
												update( action.id, {
													name: value,
												} )
											}
										/>
										<ToggleControl
											__nextHasNoMarginBottom
											label={ __(
												'Turned on',
												'formulario-mensaje'
											) }
											checked={ !! action.enabled }
											onChange={ ( enabled ) =>
												update( action.id, { enabled } )
											}
										/>
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Send to',
												'formulario-mensaje'
											) }
											help={ __(
												'One or more email addresses, separated by commas.',
												'formulario-mensaje'
											) }
											value={ recipients }
											// Se guarda como texto mientras se escribe; el servidor lo convierte en una lista de direcciones válidas.
											onChange={ ( value ) =>
												set( 'to', value )
											}
										/>
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Subject',
												'formulario-mensaje'
											) }
											value={ settings.subject || '' }
											onChange={ ( value ) =>
												set( 'subject', value )
											}
										/>
										<TextareaControl
											__nextHasNoMarginBottom
											label={ __(
												'Message',
												'formulario-mensaje'
											) }
											rows={ 8 }
											value={ settings.body || '' }
											onChange={ ( value ) =>
												set( 'body', value )
											}
										/>
										<TagsHelp
											fields={ fields }
											onInsert={ ( tag ) =>
												set(
													'body',
													`${ settings.body || '' }${ tag }`
												)
											}
										/>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Format',
												'formulario-mensaje'
											) }
											help={ __(
												'The HTML email uses the logo, colour and footer of the plugin settings, and always includes a plain text version.',
												'formulario-mensaje'
											) }
											value={ settings.format || 'html' }
											options={ [
												{
													value: 'html',
													label: __(
														'HTML with the brand of the site',
														'formulario-mensaje'
													),
												},
												{
													value: 'text',
													label: __(
														'Plain text',
														'formulario-mensaje'
													),
												},
											] }
											onChange={ ( value ) =>
												set( 'format', value )
											}
										/>
										<SelectControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											label={ __(
												'Reply to',
												'formulario-mensaje'
											) }
											help={ __(
												'Replying to the notification writes to the address the visitor entered in this field.',
												'formulario-mensaje'
											) }
											value={
												settings.reply_to_field || ''
											}
											options={ [
												{
													value: '',
													label: __(
														'Nobody',
														'formulario-mensaje'
													),
												},
												...emailFields.map(
													( field ) => ( {
														value: field.id,
														label: fieldTitle(
															field
														),
													} )
												),
											] }
											onChange={ ( value ) =>
												set( 'reply_to_field', value )
											}
										/>
										<ConditionEditor
											label={ __(
												'Send only if',
												'formulario-mensaje'
											) }
											condition={ action.condition }
											fields={ fields }
											onChange={ ( condition ) =>
												update( action.id, {
													condition,
												} )
											}
										/>
										<TestSender
											title={ title }
											definition={ definition }
											actionId={ action.id }
										/>
									</div>
								</div>
							) }
						</li>
					);
				} ) }
			</ol>

			<Button
				__next40pxDefaultSize
				variant="secondary"
				icon="plus-alt2"
				onClick={ add }
			>
				{ __( 'Add notification', 'formulario-mensaje' ) }
			</Button>
		</div>
	);
}
