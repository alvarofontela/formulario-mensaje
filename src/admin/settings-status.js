import { Button, Dashicon } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { getSubmissionCounts } from './api';
import { can, urls } from './config';

/**
 * Nombre del transporte con el que salen los correos. Son los mismos textos que ofrece la pestaña de correo.
 *
 * @param {string} transport Identificador del transporte elegido.
 * @return {string} Nombre para mostrar.
 */
function transportLabel( transport ) {
	return (
		{
			wp_mail: __(
				'WordPress, like the rest of the site',
				'formulario-mensaje'
			),
			smtp: __( 'An SMTP server', 'formulario-mensaje' ),
			resend: __( 'Resend', 'formulario-mensaje' ),
		}[ transport ] || transport
	);
}

/**
 * Con qué remitente salen los correos, en una frase.
 *
 * @param {Object} mail Ajustes de correo guardados.
 * @return {string} Explicación para mostrar.
 */
function senderLine( mail ) {
	if ( mail.from_name && mail.from_email ) {
		return sprintf(
			/* translators: 1: name of the sender, 2: email address of the sender. */
			__( 'They go out as “%1$s” from %2$s.', 'formulario-mensaje' ),
			mail.from_name,
			mail.from_email
		);
	}

	if ( mail.from_email ) {
		return sprintf(
			/* translators: %s: email address of the sender. */
			__( 'They go out from %s.', 'formulario-mensaje' ),
			mail.from_email
		);
	}

	if ( mail.from_name ) {
		return sprintf(
			/* translators: %s: name of the sender. */
			__(
				'They go out as “%s”, from the site’s address.',
				'formulario-mensaje'
			),
			mail.from_name
		);
	}

	return __(
		'They go out with the site’s name and address.',
		'formulario-mensaje'
	);
}

/**
 * Fila del resumen: un icono que dice cómo está, una frase, su explicación y lo que se puede hacer.
 *
 * @param {Object}  props
 * @param {string}  props.state    Estado: `ok`, `warn` o `idle`.
 * @param {string}  props.icon     Nombre del dashicon.
 * @param {string}  props.title    Frase que resume el estado.
 * @param {string}  props.text     Explicación.
 * @param {?Object} props.children Botones de la fila.
 */
function StatusRow( { state, icon, title, text, children } ) {
	return (
		<li className={ `fm-status__row is-${ state }` }>
			<span className="fm-status__icon">
				<Dashicon icon={ icon } />
			</span>
			<div className="fm-status__text">
				<h2>{ title }</h2>
				<p>{ text }</p>
			</div>
			{ children && (
				<div className="fm-status__actions">{ children }</div>
			) }
		</li>
	);
}

/**
 * Resumen de los ajustes en lenguaje llano: qué está en orden y qué hay que mirar.
 *
 * Describe lo que está guardado, no lo que se está escribiendo en las pestañas de debajo. No pide
 * los ajustes al servidor, que ya los tiene la pantalla: solo el recuento de entregas fallidas.
 *
 * @param {Object}   props
 * @param {Object}   props.mail        Ajustes de correo guardados.
 * @param {Object}   props.captcha     Ajustes del captcha guardados.
 * @param {Object}   props.captchaInfo Servicios de captcha disponibles: `{ providers }`.
 * @param {Function} props.onJump      Lleva a una pestaña de los ajustes: recibe su nombre.
 */
export default function SettingsStatus( {
	mail,
	captcha,
	captchaInfo,
	onJump,
} ) {
	const [ failed, setFailed ] = useState( 0 );

	useEffect( () => {
		// Las entregas son de los mensajes: solo las ve, y solo puede abrirlas, quien tiene ese permiso.
		if ( can.viewSubmissions ) {
			getSubmissionCounts()
				.then( ( counts ) =>
					setFailed( Number( counts.failed_deliveries ) || 0 )
				)
				.catch( () => {} );
		}
	}, [] );

	const provider = captchaInfo.providers.find(
		( candidate ) => candidate.id === captcha.provider
	);

	return (
		<ul className="fm-status">
			{ failed > 0 && (
				<StatusRow
					state="warn"
					icon="warning"
					title={ sprintf(
						/* translators: %d: number of failed deliveries. */
						_n(
							'%d delivery failed',
							'%d deliveries failed',
							failed,
							'formulario-mensaje'
						),
						failed
					) }
					text={ __(
						'Something a form had to send did not arrive. You can send it again or dismiss it.',
						'formulario-mensaje'
					) }
				>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href={ urls.submissions }
					>
						{ __( 'See them', 'formulario-mensaje' ) }
					</Button>
				</StatusRow>
			) }

			<StatusRow
				state="ok"
				icon="yes"
				title={ sprintf(
					/* translators: %s: how the emails are sent, like "An SMTP server". */
					__( 'Emails are sent with: %s', 'formulario-mensaje' ),
					transportLabel( mail.transport )
				) }
				text={ senderLine( mail ) }
			>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ () => onJump( 'mail' ) }
				>
					{ __( 'Change', 'formulario-mensaje' ) }
				</Button>
			</StatusRow>

			<StatusRow
				state="ok"
				icon="shield"
				title={ __( 'Spam protection is on', 'formulario-mensaje' ) }
				text={ __(
					'It works on its own in every form. There is nothing to set up.',
					'formulario-mensaje'
				) }
			>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ () => onJump( 'general' ) }
				>
					{ __( 'See options', 'formulario-mensaje' ) }
				</Button>
			</StatusRow>

			{ captcha.provider ? (
				<StatusRow
					state="ok"
					icon="yes"
					title={ sprintf(
						/* translators: %s: name of a captcha service. */
						__( 'Captcha: %s', 'formulario-mensaje' ),
						provider?.label || captcha.provider
					) }
					text={ __(
						'It asks whoever writes to prove they are not a robot.',
						'formulario-mensaje'
					) }
				>
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => onJump( 'captcha' ) }
					>
						{ __( 'Set up', 'formulario-mensaje' ) }
					</Button>
				</StatusRow>
			) : (
				<StatusRow
					state="idle"
					icon="clock"
					title={ __(
						'Captcha: not needed for now',
						'formulario-mensaje'
					) }
					text={ __(
						'Turn it on only if spam starts to arrive.',
						'formulario-mensaje'
					) }
				>
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => onJump( 'captcha' ) }
					>
						{ __( 'Set up', 'formulario-mensaje' ) }
					</Button>
				</StatusRow>
			) }
		</ul>
	);
}
