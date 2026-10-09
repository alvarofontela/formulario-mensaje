import {
	Button,
	Notice,
	RadioControl,
	Spinner,
	TabPanel,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	getMailSettings,
	getSettings,
	runMaintenance,
	saveMailSettings,
	saveSettings,
} from './api';
import { siteHost } from './config';
import MailSettings from './mail-settings';

const TABS = [
	{ name: 'general', title: __( 'General', 'formulario-mensaje' ) },
	{ name: 'mail', title: __( 'Email', 'formulario-mensaje' ) },
];

const toLines = ( list ) => ( Array.isArray( list ) ? list.join( '\n' ) : '' );
const toList = ( text ) =>
	text
		.split( /[\s,]+/ )
		.map( ( item ) => item.trim() )
		.filter( Boolean );

/**
 * Pantalla de ajustes: los generales (antispam, proxy de confianza, dominios de redirección y datos)
 * y los de correo. Se guardan juntos con un solo botón.
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

	const mailDirty =
		!! mail &&
		( JSON.stringify( mail ) !== savedMail ||
			Object.values( secrets ).some( Boolean ) ||
			cleared.length > 0 );

	useEffect( () => {
		Promise.all( [ getSettings(), getMailSettings() ] )
			.then( ( [ general, mailResponse ] ) => {
				apply( general );
				applyMail( mailResponse );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	const set = ( key, value ) =>
		setSettings( { ...settings, [ key ]: value } );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setNotice( null );

		Promise.all( [
			saveSettings( {
				trusted_proxy_mode: settings.trusted_proxy_mode,
				trusted_proxies: toList( proxies ),
				allowed_redirect_hosts: toList( hosts ),
				accept_no_js: settings.accept_no_js,
				delete_data_on_uninstall: settings.delete_data_on_uninstall,
			} ),
			saveMailSettings( { settings: mail, secrets, clear: cleared } ),
		] )
			.then( ( [ general, mailResponse ] ) => {
				apply( general );
				applyMail( mailResponse );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'formulario-mensaje' ),
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
			<div className="fm-admin__header">
				<h1 className="fm-admin__title">
					{ __( 'Forms settings', 'formulario-mensaje' ) }
				</h1>
			</div>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ ! ( settings && mail ) && ! notice && (
				<div className="fm-admin__loading">
					<Spinner />
				</div>
			) }

			{ settings && mail && (
				<form className="fm-settings-page__form" onSubmit={ save }>
					<TabPanel className="fm-settings-page__tabs" tabs={ TABS }>
						{ ( tab ) =>
							tab.name === 'mail' ? (
								<MailSettings
									settings={ mail }
									onChange={ setMail }
									secretStatus={ secretStatus }
									secrets={ secrets }
									onSecretsChange={ setSecrets }
									cleared={ cleared }
									onClearedChange={ setCleared }
									dirty={ mailDirty }
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
												'Every form is protected by a hidden trap field, a minimum time to fill it in and a limit of submissions per IP address. None of them needs configuration.',
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
											checked={ !! settings.accept_no_js }
											onChange={ ( value ) =>
												set( 'accept_no_js', value )
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
												settings.detected_ip || '—',
												settings.connection_ip || '—'
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
													'After a submission, a form can redirect to any address of this site (%s). To redirect to another site, add its domain here.',
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
												'Data',
												'formulario-mensaje'
											) }
										</h2>
										<p>
											{ __(
												'Once a day the plugin deletes the submissions that have exceeded the retention period of their form. You can run that cleanup now.',
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
	);
}
