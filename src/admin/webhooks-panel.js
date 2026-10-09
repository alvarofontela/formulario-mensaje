import {
	Button,
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ActionList from './action-list';
import { sendTestWebhook } from './api';
import TagsHelp from './tags-help';

const METHODS = [ 'POST', 'PUT', 'PATCH' ];

// El servidor devuelve el secreto de firma cifrado, con este prefijo; lo que no lo lleva es uno nuevo.
const isStoredSecret = ( value ) =>
	typeof value === 'string' && value.startsWith( 'fm1:' );

/**
 * Lista editable de pares (cabeceras, o claves del destino con su valor).
 *
 * @param {Object}   props
 * @param {string}   props.legend     Título de la lista.
 * @param {Array}    props.items      Pares en edición.
 * @param {string}   props.nameKey    Propiedad de cada par que hace de nombre: `name` o `key`.
 * @param {string}   props.nameLabel  Etiqueta del nombre.
 * @param {string}   props.valueLabel Etiqueta del valor.
 * @param {string}   props.addLabel   Texto del botón de añadir.
 * @param {Function} props.onChange   Recibe la lista nueva.
 */
function PairList( {
	legend,
	items,
	nameKey,
	nameLabel,
	valueLabel,
	addLabel,
	onChange,
} ) {
	const list = Array.isArray( items ) ? items : [];
	const setItem = ( index, patch ) =>
		onChange(
			list.map( ( item, position ) =>
				position === index ? { ...item, ...patch } : item
			)
		);

	return (
		<fieldset className="fm-options">
			<legend className="fm-options__legend">{ legend }</legend>
			{ list.map( ( item, index ) => (
				<div className="fm-options__row" key={ index }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ nameLabel }
						hideLabelFromVision
						placeholder={ nameLabel }
						value={ item[ nameKey ] || '' }
						onChange={ ( value ) =>
							setItem( index, { [ nameKey ]: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ valueLabel }
						hideLabelFromVision
						placeholder={ valueLabel }
						value={ item.value || '' }
						onChange={ ( value ) => setItem( index, { value } ) }
					/>
					<Button
						size="small"
						icon="no-alt"
						isDestructive
						label={ __( 'Remove', 'formulario-mensaje' ) }
						onClick={ () =>
							onChange(
								list.filter(
									( other, position ) => position !== index
								)
							)
						}
					/>
				</div>
			) ) }
			<Button
				variant="secondary"
				size="compact"
				onClick={ () =>
					onChange( [ ...list, { [ nameKey ]: '', value: '' } ] )
				}
			>
				{ addLabel }
			</Button>
		</fieldset>
	);
}

/**
 * Envío de un webhook de prueba, con datos de ejemplo, que muestra lo que responde el destino.
 *
 * Se prueba el webhook tal como está en pantalla, aunque el formulario no se haya guardado.
 *
 * @param {Object} props
 * @param {string} props.title      Nombre del formulario.
 * @param {Object} props.definition Definición en edición.
 * @param {Object} props.action     Webhook que se prueba.
 */
function TestSender( { title, definition, action } ) {
	const [ sending, setSending ] = useState( false );
	const [ result, setResult ] = useState( null );

	const send = () => {
		setSending( true );
		setResult( null );

		sendTestWebhook( { title, definition, action_id: action.id } )
			.then( ( response ) =>
				setResult( {
					status: response.success ? 'success' : 'error',
					message: sprintf(
						/* translators: 1: HTTP status code, 2: number of milliseconds. */
						__(
							'The destination answered with HTTP status %1$d in %2$d ms.',
							'formulario-mensaje'
						),
						response.status,
						response.duration_ms
					),
					body: response.body,
				} )
			)
			.catch( ( error ) =>
				setResult( { status: 'error', message: error.message } )
			)
			.finally( () => setSending( false ) );
	};

	return (
		<div className="fm-test">
			<p className="fm-settings__note">
				{ __(
					'The test sends sample data right now, with the header X-Formulario-Mensaje-Test, and shows the answer of the destination.',
					'formulario-mensaje'
				) }
			</p>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				isBusy={ sending }
				disabled={ sending || ! action.settings.url }
				onClick={ send }
			>
				{ __( 'Send test webhook', 'formulario-mensaje' ) }
			</Button>
			{ result && (
				<Notice status={ result.status } isDismissible={ false }>
					{ result.message }
					{ result.body && (
						<pre className="fm-test__response">{ result.body }</pre>
					) }
				</Notice>
			) }
		</div>
	);
}

/**
 * Pestaña de webhooks: cada uno envía los datos del envío a la dirección de otro servicio, en
 * segundo plano y con reintentos.
 *
 * @param {Object}   props
 * @param {string}   props.title      Nombre del formulario.
 * @param {Object}   props.definition Definición en edición.
 * @param {Function} props.onChange   Recibe la lista de acciones nueva.
 */
export default function WebhooksPanel( { title, definition, onChange } ) {
	const { fields } = definition;

	return (
		<>
			<p className="fm-settings__note">
				{ __(
					'A webhook sends the data of each submission to another service, such as n8n, a CRM or a spreadsheet. It is sent in the background: the visitor does not wait for it, and if the destination fails it is retried several times over the following hours.',
					'formulario-mensaje'
				) }
			</p>
			<ActionList
				type="webhook"
				definition={ definition }
				onChange={ onChange }
				labels={ {
					empty: __(
						'This form does not send any webhook.',
						'formulario-mensaje'
					),
					add: __( 'Add webhook', 'formulario-mensaje' ),
					unavailable: __(
						'Webhooks are not available.',
						'formulario-mensaje'
					),
					condition: __( 'Send only if', 'formulario-mensaje' ),
					numbered: ( position ) =>
						sprintf(
							/* translators: %d: position of a webhook in the list, starting at 1. */
							__( 'Webhook %d', 'formulario-mensaje' ),
							position
						),
				} }
				summary={ ( action ) =>
					action.settings.url
						? `${ action.settings.method || 'POST' } ${
								action.settings.url
							}`
						: __( 'No address', 'formulario-mensaje' )
				}
				renderSettings={ ( { settings, set } ) => {
					const stored = isStoredSecret( settings.secret );
					const payload = Array.isArray( settings.payload )
						? settings.payload
						: [];

					return (
						<>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="url"
								label={ __(
									'Destination address',
									'formulario-mensaje'
								) }
								help={ __(
									'It must start with https:// or http:// and be reachable from the internet: addresses of the internal network are rejected.',
									'formulario-mensaje'
								) }
								placeholder="https://"
								value={ settings.url || '' }
								onChange={ ( value ) => set( 'url', value ) }
							/>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Method', 'formulario-mensaje' ) }
								value={ settings.method || 'POST' }
								options={ METHODS.map( ( method ) => ( {
									value: method,
									label: method,
								} ) ) }
								onChange={ ( value ) => set( 'method', value ) }
							/>
							<RadioControl
								label={ __(
									'Data sent',
									'formulario-mensaje'
								) }
								help={ __(
									'It is always sent as JSON.',
									'formulario-mensaje'
								) }
								selected={ settings.payload_mode || 'all' }
								options={ [
									{
										value: 'all',
										label: __(
											'The whole submission: form, reference, date, page and every field by its identifier',
											'formulario-mensaje'
										),
									},
									{
										value: 'custom',
										label: __(
											'Only the keys I choose, with the names the destination expects',
											'formulario-mensaje'
										),
									},
								] }
								onChange={ ( value ) =>
									set( 'payload_mode', value )
								}
							/>
							{ settings.payload_mode === 'custom' && (
								<div className="fm-settings__group">
									<PairList
										legend={ __(
											'Keys and values',
											'formulario-mensaje'
										) }
										items={ payload }
										nameKey="key"
										nameLabel={ __(
											'Key',
											'formulario-mensaje'
										) }
										valueLabel={ __(
											'Value, with tags',
											'formulario-mensaje'
										) }
										addLabel={ __(
											'Add key',
											'formulario-mensaje'
										) }
										onChange={ ( value ) =>
											set( 'payload', value )
										}
									/>
									<TagsHelp
										fields={ fields }
										// La etiqueta elegida entra como una clave nueva, lista para ponerle nombre.
										onInsert={ ( tag ) =>
											set( 'payload', [
												...payload,
												{ key: '', value: tag },
											] )
										}
									/>
								</div>
							) }
							<PairList
								legend={ __(
									'Additional headers',
									'formulario-mensaje'
								) }
								items={ settings.headers }
								nameKey="name"
								nameLabel={ __(
									'Header',
									'formulario-mensaje'
								) }
								valueLabel={ __(
									'Value',
									'formulario-mensaje'
								) }
								addLabel={ __(
									'Add header',
									'formulario-mensaje'
								) }
								onChange={ ( value ) =>
									set( 'headers', value )
								}
							/>
							<p className="fm-settings__note">
								{ __(
									'Headers are stored with the form and anyone who can edit it sees them. To let the destination check where the request comes from, use the signing secret.',
									'formulario-mensaje'
								) }
							</p>
							<div className="fm-secret">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="password"
									autoComplete="new-password"
									label={ __(
										'Signing secret',
										'formulario-mensaje'
									) }
									placeholder={
										stored
											? __(
													'Stored. Write to replace it',
													'formulario-mensaje'
												)
											: ''
									}
									help={ __(
										'Optional. With a secret, each request carries the headers X-Formulario-Mensaje-Timestamp and X-Formulario-Mensaje-Signature: “sha256=” followed by the HMAC-SHA256 of the timestamp, a dot and the body. It is stored encrypted and is never shown again.',
										'formulario-mensaje'
									) }
									value={
										stored ? '' : settings.secret || ''
									}
									onChange={ ( value ) =>
										set( 'secret', value )
									}
								/>
								{ stored && (
									<Button
										variant="link"
										isDestructive
										onClick={ () => set( 'secret', '' ) }
									>
										{ __(
											'Delete the stored one',
											'formulario-mensaje'
										) }
									</Button>
								) }
							</div>
						</>
					);
				} }
				renderFooter={ ( { action } ) => (
					<TestSender
						title={ title }
						definition={ definition }
						action={ action }
					/>
				) }
			/>
		</>
	);
}
