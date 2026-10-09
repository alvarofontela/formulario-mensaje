import {
	Button,
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { sendMailTest } from './api';
import { userEmail } from './config';

/**
 * Campo para una credencial. Nunca muestra la guardada: el servidor no la devuelve.
 *
 * @param {Object}   props
 * @param {string}   props.label    Etiqueta del campo.
 * @param {Object}   props.status   Estado de la credencial: `{ source, constant }`.
 * @param {string}   props.value    Valor nuevo que se está escribiendo.
 * @param {Function} props.onChange Recibe el valor nuevo.
 * @param {boolean}  props.cleared  Si se ha pedido borrar la credencial guardada.
 * @param {Function} props.onClear  Pide borrar, o deja de pedirlo, la credencial guardada.
 */
function SecretControl( { label, status, value, onChange, cleared, onClear } ) {
	if ( status?.source === 'constant' ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				help={ sprintf(
					/* translators: %s: name of a PHP constant. */
					__(
						'Defined in wp-config.php with the constant %s. It cannot be changed here.',
						'formulario-mensaje'
					),
					status.constant
				) }
				value="••••••••"
				readOnly
				disabled
				onChange={ () => {} }
			/>
		);
	}

	const stored = status?.source === 'stored' && ! cleared;

	return (
		<div className="fm-secret">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="password"
				autoComplete="new-password"
				label={ label }
				placeholder={
					stored
						? __(
								'Stored. Write to replace it',
								'formulario-mensaje'
							)
						: ''
				}
				help={
					stored
						? __(
								'It is stored encrypted and is never shown again.',
								'formulario-mensaje'
							)
						: __(
								'It will be stored encrypted.',
								'formulario-mensaje'
							)
				}
				value={ value }
				onChange={ onChange }
			/>
			{ status?.source === 'stored' && (
				<Button variant="link" isDestructive onClick={ onClear }>
					{ cleared
						? __( 'Keep the stored one', 'formulario-mensaje' )
						: __( 'Delete the stored one', 'formulario-mensaje' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Abre la biblioteca de medios de WordPress para elegir una imagen.
 *
 * @param {Function} onSelect Recibe la dirección de la imagen elegida.
 */
function pickImage( onSelect ) {
	const frame = window.wp.media( {
		title: __( 'Email logo', 'formulario-mensaje' ),
		library: { type: 'image' },
		multiple: false,
	} );

	frame.on( 'select', () => {
		const image = frame.state().get( 'selection' ).first()?.toJSON();

		if ( image?.url ) {
			onSelect( image.url );
		}
	} );
	frame.open();
}

/**
 * Ajustes de correo: cómo se envían los correos del plugin y qué aspecto tienen.
 *
 * @param {Object}   props
 * @param {Object}   props.settings        Ajustes de correo.
 * @param {Function} props.onChange        Recibe los ajustes nuevos.
 * @param {Object}   props.secretStatus    Estado de cada credencial, por nombre.
 * @param {Object}   props.secrets         Credenciales nuevas que se están escribiendo, por nombre.
 * @param {Function} props.onSecretsChange Recibe las credenciales nuevas.
 * @param {Array}    props.cleared         Nombres de las credenciales guardadas que se van a borrar.
 * @param {Function} props.onClearedChange Recibe la lista nueva.
 * @param {boolean}  props.dirty           Si hay cambios sin guardar; la prueba usa lo guardado.
 */
export default function MailSettings( {
	settings,
	onChange,
	secretStatus,
	secrets,
	onSecretsChange,
	cleared,
	onClearedChange,
	dirty,
} ) {
	const [ testTo, setTestTo ] = useState( userEmail );
	const [ testing, setTesting ] = useState( false );
	const [ testResult, setTestResult ] = useState( null );

	const set = ( key, value ) => onChange( { ...settings, [ key ]: value } );
	const setSecret = ( name, value ) =>
		onSecretsChange( { ...secrets, [ name ]: value } );
	const toggleCleared = ( name ) =>
		onClearedChange(
			cleared.includes( name )
				? cleared.filter( ( item ) => item !== name )
				: [ ...cleared, name ]
		);

	const test = () => {
		setTesting( true );
		setTestResult( null );

		sendMailTest( testTo )
			.then( () =>
				setTestResult( {
					status: 'success',
					message: sprintf(
						/* translators: %s: email address. */
						__( 'Test sent to %s.', 'formulario-mensaje' ),
						testTo
					),
				} )
			)
			.catch( ( error ) =>
				setTestResult( { status: 'error', message: error.message } )
			)
			.finally( () => setTesting( false ) );
	};

	return (
		<>
			<section className="fm-card">
				<h2>{ __( 'Email delivery', 'formulario-mensaje' ) }</h2>
				<p>
					{ __(
						'How the emails of the forms are sent. It only affects the emails of this plugin: the rest of the email of the site does not change.',
						'formulario-mensaje'
					) }
				</p>
				<div className="fm-settings">
					<RadioControl
						label={ __( 'Send with', 'formulario-mensaje' ) }
						selected={ settings.transport }
						options={ [
							{
								value: 'wp_mail',
								label: __(
									'WordPress, like the rest of the site',
									'formulario-mensaje'
								),
							},
							{
								value: 'smtp',
								label: __(
									'An SMTP server',
									'formulario-mensaje'
								),
							},
							{
								value: 'resend',
								label: __( 'Resend', 'formulario-mensaje' ),
							},
						] }
						onChange={ ( value ) => set( 'transport', value ) }
					/>

					{ settings.transport === 'smtp' && (
						<div className="fm-settings__group">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Server', 'formulario-mensaje' ) }
								placeholder="smtp.example.com"
								value={ settings.smtp_host }
								onChange={ ( value ) =>
									set( 'smtp_host', value )
								}
							/>
							<div className="fm-settings__columns">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="number"
									min={ 1 }
									max={ 65535 }
									label={ __( 'Port', 'formulario-mensaje' ) }
									value={ String( settings.smtp_port ) }
									onChange={ ( value ) =>
										set(
											'smtp_port',
											parseInt( value, 10 ) || 0
										)
									}
								/>
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ __(
										'Encryption',
										'formulario-mensaje'
									) }
									value={ settings.smtp_encryption }
									options={ [
										{
											value: 'tls',
											label: __(
												'STARTTLS (usually port 587)',
												'formulario-mensaje'
											),
										},
										{
											value: 'ssl',
											label: __(
												'SSL/TLS (usually port 465)',
												'formulario-mensaje'
											),
										},
										{
											value: 'none',
											label: __(
												'None',
												'formulario-mensaje'
											),
										},
									] }
									onChange={ ( value ) =>
										set( 'smtp_encryption', value )
									}
								/>
							</div>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'The server asks for user and password',
									'formulario-mensaje'
								) }
								checked={ !! settings.smtp_auth }
								onChange={ ( value ) =>
									set( 'smtp_auth', value )
								}
							/>
							{ settings.smtp_auth && (
								<>
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										autoComplete="off"
										label={ __(
											'User',
											'formulario-mensaje'
										) }
										value={ settings.smtp_username }
										onChange={ ( value ) =>
											set( 'smtp_username', value )
										}
									/>
									<SecretControl
										label={ __(
											'Password',
											'formulario-mensaje'
										) }
										status={ secretStatus.smtp_password }
										value={ secrets.smtp_password || '' }
										onChange={ ( value ) =>
											setSecret( 'smtp_password', value )
										}
										cleared={ cleared.includes(
											'smtp_password'
										) }
										onClear={ () =>
											toggleCleared( 'smtp_password' )
										}
									/>
								</>
							) }
						</div>
					) }

					{ settings.transport === 'resend' && (
						<div className="fm-settings__group">
							<SecretControl
								label={ __(
									'Resend API key',
									'formulario-mensaje'
								) }
								status={ secretStatus.resend_api_key }
								value={ secrets.resend_api_key || '' }
								onChange={ ( value ) =>
									setSecret( 'resend_api_key', value )
								}
								cleared={ cleared.includes( 'resend_api_key' ) }
								onClear={ () =>
									toggleCleared( 'resend_api_key' )
								}
							/>
							<p className="fm-settings__note">
								{ __(
									'Resend only sends from an address of a domain verified in your Resend account: fill in the sender address below.',
									'formulario-mensaje'
								) }
							</p>
						</div>
					) }

					<div className="fm-settings__columns">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="email"
							label={ __(
								'Sender address',
								'formulario-mensaje'
							) }
							help={ __(
								'Empty to use the one of the site.',
								'formulario-mensaje'
							) }
							value={ settings.from_email }
							onChange={ ( value ) => set( 'from_email', value ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Sender name', 'formulario-mensaje' ) }
							help={ __(
								'Empty to use the name of the site.',
								'formulario-mensaje'
							) }
							value={ settings.from_name }
							onChange={ ( value ) => set( 'from_name', value ) }
						/>
					</div>

					<div className="fm-test">
						<div className="fm-options__row">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="email"
								label={ __(
									'Send a test email to',
									'formulario-mensaje'
								) }
								help={
									dirty
										? __(
												'Save the settings first: the test uses the saved ones.',
												'formulario-mensaje'
											)
										: __(
												'Checks that the emails are delivered with these settings.',
												'formulario-mensaje'
											)
								}
								value={ testTo }
								onChange={ setTestTo }
							/>
							<Button
								__next40pxDefaultSize
								className="fm-test__button"
								variant="secondary"
								isBusy={ testing }
								disabled={ testing || dirty || ! testTo }
								onClick={ test }
							>
								{ __( 'Send test', 'formulario-mensaje' ) }
							</Button>
						</div>
						{ testResult && (
							<Notice
								status={ testResult.status }
								isDismissible={ false }
							>
								{ testResult.message }
							</Notice>
						) }
					</div>
				</div>
			</section>

			<section className="fm-card">
				<h2>{ __( 'Email appearance', 'formulario-mensaje' ) }</h2>
				<p>
					{ __(
						'Brand of the notifications sent in HTML format.',
						'formulario-mensaje'
					) }
				</p>
				<div className="fm-settings">
					<div className="fm-test fm-test--plain">
						<div className="fm-options__row">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="url"
								label={ __( 'Logo', 'formulario-mensaje' ) }
								help={ __(
									'Address of the image. Without a logo, the emails show the name of the site.',
									'formulario-mensaje'
								) }
								value={ settings.brand_logo_url }
								onChange={ ( value ) =>
									set( 'brand_logo_url', value )
								}
							/>
							<Button
								__next40pxDefaultSize
								className="fm-test__button"
								variant="secondary"
								onClick={ () =>
									pickImage( ( url ) =>
										set( 'brand_logo_url', url )
									)
								}
							>
								{ __( 'Choose image', 'formulario-mensaje' ) }
							</Button>
						</div>
					</div>
					<div className="fm-color">
						<label htmlFor="fm-brand-color">
							{ __( 'Brand colour', 'formulario-mensaje' ) }
						</label>
						<input
							id="fm-brand-color"
							type="color"
							value={ settings.brand_color }
							onChange={ ( event ) =>
								set( 'brand_color', event.target.value )
							}
						/>
						<code>{ settings.brand_color }</code>
					</div>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Footer text', 'formulario-mensaje' ) }
						help={ __(
							'For example, the name and address of the company. Without it, the footer shows the name of the site.',
							'formulario-mensaje'
						) }
						rows={ 3 }
						value={ settings.brand_footer }
						onChange={ ( value ) => set( 'brand_footer', value ) }
					/>
				</div>
			</section>
		</>
	);
}
