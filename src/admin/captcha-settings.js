import { RadioControl, RangeControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import SecretControl from './secret-control';

/**
 * Ajustes del captcha: un servicio para todos los formularios del sitio.
 *
 * @param {Object}   props
 * @param {Object}   props.settings      Ajustes del captcha.
 * @param {Function} props.onChange      Recibe los ajustes nuevos.
 * @param {Array}    props.providers     Servicios disponibles: `{ id, label }`.
 * @param {Object}   props.secretStatus  Estado de la clave secreta: `{ source, constant }`.
 * @param {string}   props.secret        Clave secreta nueva que se está escribiendo.
 * @param {Function} props.onSecret      Recibe la clave secreta nueva.
 * @param {boolean}  props.cleared       Si se ha pedido borrar la clave secreta guardada.
 * @param {Function} props.onToggleClear Pide borrar, o deja de pedirlo, la clave secreta guardada.
 */
export default function CaptchaSettings( {
	settings,
	onChange,
	providers,
	secretStatus,
	secret,
	onSecret,
	cleared,
	onToggleClear,
} ) {
	const set = ( key, value ) => onChange( { ...settings, [ key ]: value } );

	return (
		<section className="fm-card">
			<h2>{ __( 'Captcha', 'formulario-mensaje' ) }</h2>
			<p>
				{ __(
					'Every form is already protected without a captcha. Add one only if you still receive spam: it loads a script from another company on the pages with a form, when the visitor starts filling it in, and a form with a captcha cannot be sent without JavaScript.',
					'formulario-mensaje'
				) }
			</p>
			<div className="fm-settings">
				<RadioControl
					label={ __( 'Captcha service', 'formulario-mensaje' ) }
					selected={ settings.provider }
					options={ [
						{
							value: '',
							label: __( 'None', 'formulario-mensaje' ),
						},
						...providers.map( ( provider ) => ( {
							value: provider.id,
							label: provider.label,
						} ) ),
					] }
					onChange={ ( value ) => set( 'provider', value ) }
				/>

				{ settings.provider !== '' && (
					<div className="fm-settings__group">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							autoComplete="off"
							label={ __( 'Site key', 'formulario-mensaje' ) }
							help={ __(
								'The public key the service gives you for this site.',
								'formulario-mensaje'
							) }
							value={ settings.site_key }
							onChange={ ( value ) => set( 'site_key', value ) }
						/>
						<SecretControl
							label={ __( 'Secret key', 'formulario-mensaje' ) }
							status={ secretStatus }
							value={ secret }
							onChange={ onSecret }
							cleared={ cleared }
							onClear={ onToggleClear }
						/>
						{ settings.provider === 'recaptcha' && (
							<RangeControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Minimum score',
									'formulario-mensaje'
								) }
								help={ __(
									'reCAPTCHA scores each visitor from 0 (a bot) to 1 (a person). Submissions below this score are rejected. 0.5 is the usual value: raise it if spam gets through, lower it if real visitors are rejected.',
									'formulario-mensaje'
								) }
								min={ 0.1 }
								max={ 0.9 }
								step={ 0.1 }
								value={ settings.score_threshold }
								onChange={ ( value ) =>
									set( 'score_threshold', value ?? 0.5 )
								}
							/>
						) }
						<p className="fm-settings__note">
							{ __(
								'The captcha is used in every form as soon as both keys are saved. Each form can opt out in the “What the sender sees” section of its “When it arrives” step.',
								'formulario-mensaje'
							) }
						</p>
					</div>
				) }
			</div>
		</section>
	);
}
