import {
	Button,
	Notice,
	RadioControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getSettings, saveSettings } from './api';
import { siteHost } from './config';

const toLines = ( list ) => ( Array.isArray( list ) ? list.join( '\n' ) : '' );
const toList = ( text ) =>
	text
		.split( /[\s,]+/ )
		.map( ( item ) => item.trim() )
		.filter( Boolean );

/**
 * Pantalla de ajustes generales: proxy de confianza y dominios de redirección permitidos.
 */
export default function SettingsPage() {
	const [ settings, setSettings ] = useState( null );
	const [ proxies, setProxies ] = useState( '' );
	const [ hosts, setHosts ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const apply = ( response ) => {
		setSettings( response );
		setProxies( toLines( response.trusted_proxies ) );
		setHosts( toLines( response.allowed_redirect_hosts ) );
	};

	useEffect( () => {
		getSettings()
			.then( apply )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setNotice( null );

		saveSettings( {
			trusted_proxy_mode: settings.trusted_proxy_mode,
			trusted_proxies: toList( proxies ),
			allowed_redirect_hosts: toList( hosts ),
		} )
			.then( ( response ) => {
				apply( response );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'formulario-mensaje' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => setSaving( false ) );
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

			{ ! settings && ! notice && (
				<div className="fm-admin__loading">
					<Spinner />
				</div>
			) }

			{ settings && (
				<form className="fm-settings-page__form" onSubmit={ save }>
					<section className="fm-card">
						<h2>
							{ __( 'Visitor IP address', 'formulario-mensaje' ) }
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
							selected={ settings.trusted_proxy_mode }
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
								setSettings( {
									...settings,
									trusted_proxy_mode: value,
								} )
							}
						/>
						{ settings.trusted_proxy_mode === 'custom' && (
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
							label={ __( 'Domains', 'formulario-mensaje' ) }
							help={ __(
								'One per line, without https://. For example: pay.example.com',
								'formulario-mensaje'
							) }
							rows={ 4 }
							value={ hosts }
							onChange={ setHosts }
						/>
					</section>

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
