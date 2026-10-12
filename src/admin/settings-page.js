import {
	Button,
	Notice,
	RadioControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	getCaptchaSettings,
	getMailSettings,
	getSettings,
	runMaintenance,
	saveCaptchaSettings,
	saveMailSettings,
	saveSettings,
} from './api';
import { initialUnread, siteHost } from './config';
import CaptchaSettings from './captcha-settings';
import MailSettings from './mail-settings';
import PageHeader from './page-header';
import SectionNav from './section-nav';
import SettingsStatus from './settings-status';

const TABS = [
	{ name: 'general', title: __( 'General', 'formulario-mensaje' ) },
	{ name: 'mail', title: __( 'Email', 'formulario-mensaje' ) },
	{ name: 'captcha', title: __( 'Captcha', 'formulario-mensaje' ) },
];

const toLines = ( list ) => ( Array.isArray( list ) ? list.join( '\n' ) : '' );
const toList = ( text ) =>
	text
		.split( /[\s,]+/ )
		.map( ( item ) => item.trim() )
		.filter( Boolean );

/**
 * Pantalla de ajustes. Arriba, un resumen en lenguaje llano de lo que está guardado; debajo, para
 * quien mantiene la web, los ajustes en sí: los generales (antispam, proxy de confianza, dominios de
 * redirección y datos), los de correo y los del captcha. Se guardan juntos con un solo botón.
 */
export default function SettingsPage() {
	const [ settings, setSettings ] = useState( null );
	const [ proxies, setProxies ] = useState( '' );
	const [ hosts, setHosts ] = useState( '' );
	const [ mail, setMail ] = useState( null );
	const [ savedMail, setSavedMail ] = useState( '' );
	const [ secretStatus, setSecretStatus ] = useState( {} );
	const [ secrets, setSecrets ] = useState( {} );
	const [ cleared, setCleared ] = useState( [] );
	const [ captcha, setCaptcha ] = useState( null );
	const [ captchaInfo, setCaptchaInfo ] = useState( {
		providers: [],
		secret: {},
	} );
	const [ captchaSecret, setCaptchaSecret ] = useState( '' );
	const [ captchaCleared, setCaptchaCleared ] = useState( false );
	// Lo último que ha confirmado el servidor: el resumen describe lo guardado, no lo que se está escribiendo.
	const [ savedCaptcha, setSavedCaptcha ] = useState( null );
	// Pestaña a la que ha llevado el resumen. El contador solo cambia en esos saltos: al cambiar de pestaña a mano no se toca.
	const [ jump, setJump ] = useState( { tab: 'general', count: 0 } );
	const advancedRef = useRef( null );
	const tabsRef = useRef( null );
	const [ saving, setSaving ] = useState( false );
	const [ cleaning, setCleaning ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const apply = ( response ) => {
		setSettings( response );
		setProxies( toLines( response.trusted_proxies ) );
		setHosts( toLines( response.allowed_redirect_hosts ) );
	};

	const applyMail = ( response ) => {
		setMail( response.settings );
		setSavedMail( JSON.stringify( response.settings ) );
		setSecretStatus( response.secrets );
		setSecrets( {} );
		setCleared( [] );
	};

	const applyCaptcha = ( response ) => {
		setCaptcha( response.settings );
		setSavedCaptcha( response.settings );
		setCaptchaInfo( {
			providers: response.providers,
			secret: response.secret,
		} );
		setCaptchaSecret( '' );
		setCaptchaCleared( false );
	};

	const mailDirty =
		!! mail &&
		( JSON.stringify( mail ) !== savedMail ||
			Object.values( secrets ).some( Boolean ) ||
			cleared.length > 0 );

	useEffect( () => {
		Promise.all( [
			getSettings(),
			getMailSettings(),
			getCaptchaSettings(),
		] )
			.then( ( [ general, mailResponse, captchaResponse ] ) => {
				apply( general );
				applyMail( mailResponse );
				applyCaptcha( captchaResponse );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	const storedMail = useMemo(
		() => ( savedMail ? JSON.parse( savedMail ) : null ),
		[ savedMail ]
	);

	// Tras un salto desde el resumen, las pestañas quedan a la vista y el foco en la elegida.
	useEffect( () => {
		if ( jump.count === 0 ) {
			return;
		}

		advancedRef.current?.scrollIntoView( { block: 'start' } );
		tabsRef.current
			?.querySelector( '[role="tab"][aria-selected="true"]' )
			?.focus( { preventScroll: true } );
	}, [ jump ] );

	const set = ( key, value ) =>
		setSettings( { ...settings, [ key ]: value } );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setNotice( null );

		/*
		 * De una en una y no a la vez: el correo y el captcha guardan sus credenciales en la misma
		 * opción, y dos peticiones simultáneas se pisarían (la segunda escribiría sin lo que acaba de
		 * guardar la primera, y una contraseña recién puesta se perdería sin aviso).
		 */
		const saveAll = async () => {
			const general = await saveSettings( {
				trusted_proxy_mode: settings.trusted_proxy_mode,
				trusted_proxies: toList( proxies ),
				allowed_redirect_hosts: toList( hosts ),
				accept_no_js: settings.accept_no_js,
				delete_data_on_uninstall: settings.delete_data_on_uninstall,
				failure_alerts: settings.failure_alerts,
				failure_alert_email: settings.failure_alert_email,
				delivery_log_days: settings.delivery_log_days,
			} );
			const mailResponse = await saveMailSettings( {
				settings: mail,
				secrets,
				clear: cleared,
			} );
			const captchaResponse = await saveCaptchaSettings( {
				settings: captcha,
				secret: captchaSecret,
				clear_secret: captchaCleared,
			} );

			return [ general, mailResponse, captchaResponse ];
		};

		saveAll()
			.then( ( [ general, mailResponse, captchaResponse ] ) => {
				apply( general );
				applyMail( mailResponse );
				applyCaptcha( captchaResponse );

				// Una credencial pertenece al servicio para el que se escribió: si cambia, hay que volver a escribirla.
				const warnings = [
					mailResponse.discarded?.includes( 'smtp_password' ) &&
						__(
							'The SMTP connection has changed, so the stored password has been deleted: enter the password of the new connection.',
							'formulario-mensaje'
						),
					mailResponse.locked?.length > 0 &&
						__(
							'The SMTP connection has not been changed: its password is defined in wp-config.php and is only sent to the server it was set up for.',
							'formulario-mensaje'
						),
					captchaResponse.discarded &&
						__(
							'The captcha service has changed, so the stored secret key has been deleted: enter the new service’s secret key.',
							'formulario-mensaje'
						),
				].filter( Boolean );

				setNotice( {
					status: warnings.length ? 'warning' : 'success',
					message: [
						__( 'Settings saved.', 'formulario-mensaje' ),
						...warnings,
					].join( ' ' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => {
				setSaving( false );
				window.scrollTo( { top: 0 } );
			} );
	};

	const clean = () => {
		setCleaning( true );
		setNotice( null );

		runMaintenance()
			.then( () =>
				setNotice( {
					status: 'success',
					message: __( 'Cleanup done.', 'formulario-mensaje' ),
				} )
			)
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => {
				setCleaning( false );
				window.scrollTo( { top: 0 } );
			} );
	};

	return (
		<div className="fm-admin fm-settings-page">
			<PageHeader
				title={ __( 'Forms settings', 'formulario-mensaje' ) }
			/>

			<div className="fm-page__body">
				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				{ ! ( settings && mail && captcha ) && ! notice && (
					<div className="fm-admin__loading">
						<Spinner />
					</div>
				) }

				{ settings && mail && captcha && (
					<form className="fm-settings-page__form" onSubmit={ save }>
						<SettingsStatus
							mail={ storedMail }
							captcha={ savedCaptcha }
							captchaInfo={ captchaInfo }
							onJump={ ( tab ) =>
								setJump( ( current ) => ( {
									tab,
									count: current.count + 1,
								} ) )
							}
						/>

						<h2 ref={ advancedRef } className="fm-status__advanced">
							{ __(
								'For whoever maintains the site',
								'formulario-mensaje'
							) }
						</h2>
						<p className="fm-status__note">
							{ __(
								'Technical settings. If you do not know what they are, you do not need to touch them.',
								'formulario-mensaje'
							) }
						</p>

						<div ref={ tabsRef }>
							<TabPanel
								key={ jump.count }
								className="fm-settings-page__tabs"
								tabs={ TABS }
								initialTabName={ jump.tab }
							>
								{ ( tab ) =>
									// eslint-disable-next-line no-nested-ternary -- Tres pestañas, una por rama.
									tab.name === 'captcha' ? (
										<CaptchaSettings
											settings={ captcha }
											onChange={ setCaptcha }
											providers={ captchaInfo.providers }
											secretStatus={ captchaInfo.secret }
											secret={ captchaSecret }
											onSecret={ setCaptchaSecret }
											cleared={ captchaCleared }
											onToggleClear={ () =>
												setCaptchaCleared(
													! captchaCleared
												)
											}
										/>
									) : tab.name === 'mail' ? (
										<MailSettings
											settings={ mail }
											onChange={ setMail }
											secretStatus={ secretStatus }
											secrets={ secrets }
											onSecretsChange={ setSecrets }
											cleared={ cleared }
											onClearedChange={ setCleared }
											dirty={ mailDirty }
											destinationLocked={
												secretStatus.smtp_password
													?.source === 'constant'
											}
										/>
									) : (
										<>
											<section className="fm-card">
												<h2>
													{ __(
														'Spam protection',
														'formulario-mensaje'
													) }
												</h2>
												<p>
													{ __(
														'Every form is protected by a hidden trap field, a minimum time to fill it in and a limit on submissions per IP address. None of them needs configuration.',
														'formulario-mensaje'
													) }
												</p>
												<ToggleControl
													__nextHasNoMarginBottom
													label={ __(
														'Accept submissions without JavaScript',
														'formulario-mensaje'
													) }
													help={ __(
														'Forms keep working in browsers without JavaScript, but those submissions cannot prove a minimum filling time, so they only have the trap field and a stricter limit per IP address. Turn it off if you receive spam through this path.',
														'formulario-mensaje'
													) }
													checked={
														!! settings.accept_no_js
													}
													onChange={ ( value ) =>
														set(
															'accept_no_js',
															value
														)
													}
												/>
											</section>

											<section className="fm-card">
												<h2>
													{ __(
														'Visitor IP address',
														'formulario-mensaje'
													) }
												</h2>
												<p>
													{ __(
														'The plugin limits how many submissions an IP address can send. If the site is behind a proxy or CDN, every visitor seems to come from the proxy unless you say which one it is.',
														'formulario-mensaje'
													) }
												</p>
												<RadioControl
													label={ __(
														'The site is behind',
														'formulario-mensaje'
													) }
													selected={
														settings.trusted_proxy_mode
													}
													options={ [
														{
															value: 'none',
															label: __(
																'Nothing: visitors connect directly to the server',
																'formulario-mensaje'
															),
														},
														{
															value: 'cloudflare',
															label: __(
																'Cloudflare',
																'formulario-mensaje'
															),
														},
														{
															value: 'custom',
															label: __(
																'My own proxy or load balancer',
																'formulario-mensaje'
															),
														},
													] }
													onChange={ ( value ) =>
														set(
															'trusted_proxy_mode',
															value
														)
													}
												/>
												{ settings.trusted_proxy_mode ===
													'custom' && (
													<TextareaControl
														__nextHasNoMarginBottom
														label={ __(
															'IP addresses of the proxy',
															'formulario-mensaje'
														) }
														help={ __(
															'One per line. IPv4, IPv6 and CIDR ranges such as 10.0.0.0/8. The X-Forwarded-For header is only trusted when the connection comes from one of them.',
															'formulario-mensaje'
														) }
														rows={ 4 }
														value={ proxies }
														onChange={ setProxies }
													/>
												) }
												<p className="fm-settings-page__ip">
													{ sprintf(
														/* translators: 1: IP address the plugin detects for the current user, 2: IP address of the connection to the server. */
														__(
															'Your IP address as the plugin sees it: %1$s (connection: %2$s).',
															'formulario-mensaje'
														),
														settings.detected_ip ||
															'—',
														settings.connection_ip ||
															'—'
													) }
												</p>
											</section>

											<section className="fm-card">
												<h2>
													{ __(
														'Allowed redirect domains',
														'formulario-mensaje'
													) }
												</h2>
												<p>
													{ sprintf(
														/* translators: %s: domain of the site. */
														__(
															'After a submission, a form can redirect to any address on this site (%s). To redirect to another site, add its domain here.',
															'formulario-mensaje'
														),
														siteHost
													) }
												</p>
												<TextareaControl
													__nextHasNoMarginBottom
													label={ __(
														'Domains',
														'formulario-mensaje'
													) }
													help={ __(
														'One per line, without https://. For example: pay.example.com',
														'formulario-mensaje'
													) }
													rows={ 4 }
													value={ hosts }
													onChange={ setHosts }
												/>
											</section>

											<section className="fm-card">
												<h2>
													{ __(
														'Failed actions',
														'formulario-mensaje'
													) }
												</h2>
												<p>
													{ __(
														'When a notification cannot be sent, or a webhook fails after all its retries, it stays on the Messages screen until someone retries or dismisses it.',
														'formulario-mensaje'
													) }
												</p>
												<div className="fm-settings">
													<ToggleControl
														__nextHasNoMarginBottom
														label={ __(
															'Send me an email when an action fails',
															'formulario-mensaje'
														) }
														help={ __(
															'At most one email every 12 hours, with the number of failures since the previous one.',
															'formulario-mensaje'
														) }
														checked={
															!! settings.failure_alerts
														}
														onChange={ ( value ) =>
															set(
																'failure_alerts',
																value
															)
														}
													/>
													{ settings.failure_alerts && (
														<TextControl
															__next40pxDefaultSize
															__nextHasNoMarginBottom
															type="email"
															label={ __(
																'Address for those emails',
																'formulario-mensaje'
															) }
															help={ __(
																'Leave empty to use the site administrator’s address.',
																'formulario-mensaje'
															) }
															value={
																settings.failure_alert_email ||
																''
															}
															onChange={ (
																value
															) =>
																set(
																	'failure_alert_email',
																	value
																)
															}
														/>
													) }
													<TextControl
														__next40pxDefaultSize
														__nextHasNoMarginBottom
														className="fm-settings__short"
														type="number"
														min={ 1 }
														max={ 365 }
														label={ __(
															'Days the delivery log is kept',
															'formulario-mensaje'
														) }
														help={ __(
															'The log says what was done with each submission: which notifications and webhooks were sent, and which failed. Entries older than this are deleted, except the ones still waiting for a retry.',
															'formulario-mensaje'
														) }
														value={ String(
															settings.delivery_log_days ??
																30
														) }
														onChange={ ( value ) =>
															set(
																'delivery_log_days',
																parseInt(
																	value,
																	10
																) || 1
															)
														}
													/>
												</div>
											</section>

											<section className="fm-card">
												<h2>
													{ __(
														'Data',
														'formulario-mensaje'
													) }
												</h2>
												<p>
													{ __(
														'Once a day the plugin deletes the submissions that have exceeded the retention period of their form and the old entries of the delivery log, and sends the webhooks that were left waiting. You can run that maintenance now.',
														'formulario-mensaje'
													) }
												</p>
												<p>
													<Button
														variant="secondary"
														isBusy={ cleaning }
														disabled={ cleaning }
														onClick={ clean }
													>
														{ __(
															'Run cleanup now',
															'formulario-mensaje'
														) }
													</Button>
												</p>
												<ToggleControl
													__nextHasNoMarginBottom
													label={ __(
														'Delete all data when the plugin is uninstalled',
														'formulario-mensaje'
													) }
													help={ __(
														'Forms, submissions and settings are deleted when the plugin is deleted from the Plugins screen. Deactivating it never deletes anything.',
														'formulario-mensaje'
													) }
													checked={
														!! settings.delete_data_on_uninstall
													}
													onChange={ ( value ) =>
														set(
															'delete_data_on_uninstall',
															value
														)
													}
												/>
											</section>
										</>
									)
								}
							</TabPanel>
						</div>

						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save settings', 'formulario-mensaje' ) }
						</Button>
					</form>
				) }
			</div>

			<SectionNav current="settings" unread={ initialUnread } />
		</div>
	);
}
