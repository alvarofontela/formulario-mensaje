import { Dashicon } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import FormSettingsPanel from './form-settings-panel';
import NotificationPanel from './notification-panel';
import StoragePanel from './storage-panel';
import WebhooksPanel from './webhooks-panel';

/**
 * Cuenta las acciones activas de un tipo.
 *
 * @param {Object} definition Definición del formulario.
 * @param {string} type       Identificador del tipo de acción.
 * @return {number} Acciones de ese tipo que están encendidas.
 */
const activeActions = ( definition, type ) =>
	( definition.actions || [] ).filter(
		( action ) => action.type === type && action.enabled !== false
	).length;

/**
 * Paso «Al recibirlo»: todo lo que pasa con un mensaje desde que alguien lo envía.
 *
 * Son cuatro apartados que se despliegan de uno en uno, cada uno con un resumen de cómo está, para
 * ver de un vistazo qué hace el formulario sin abrirlos todos.
 *
 * @param {Object}   props
 * @param {string}   props.title      Nombre del formulario.
 * @param {Object}   props.definition Definición en edición.
 * @param {Function} props.onChange   Recibe los cambios de la definición (`settings` o `actions`).
 */
export default function ReceivedPanel( { title, definition, onChange } ) {
	const [ open, setOpen ] = useState( 'notification' );
	const notifications = activeActions( definition, 'email' );
	const webhooks = activeActions( definition, 'webhook' );
	const settings = definition.settings || {};

	const sections = [
		{
			name: 'storage',
			title: __( 'Store the messages', 'formulario-mensaje' ),
			summary:
				settings.store_enabled === false
					? __( 'They are not stored', 'formulario-mensaje' )
					: __(
							'Yes: you can read them in Messages',
							'formulario-mensaje'
						),
			content: (
				<StoragePanel
					settings={ settings }
					onChange={ ( next ) => onChange( { settings: next } ) }
				/>
			),
		},
		{
			name: 'notification',
			title: __( 'Email notifications', 'formulario-mensaje' ),
			summary:
				notifications > 0
					? sprintf(
							/* translators: %d: number of active notifications. */
							_n(
								'%d active',
								'%d active',
								notifications,
								'formulario-mensaje'
							),
							notifications
						)
					: __( 'None active', 'formulario-mensaje' ),
			content: (
				<NotificationPanel
					title={ title }
					definition={ definition }
					onChange={ ( actions ) => onChange( { actions } ) }
				/>
			),
		},
		{
			name: 'after',
			title: __( 'What the sender sees', 'formulario-mensaje' ),
			summary: settings.redirect_enabled
				? __( 'Goes to another page', 'formulario-mensaje' )
				: __( 'A thank-you message', 'formulario-mensaje' ),
			content: (
				<FormSettingsPanel
					settings={ settings }
					fields={ definition.fields }
					onChange={ ( next ) => onChange( { settings: next } ) }
				/>
			),
		},
		{
			name: 'webhooks',
			title: __(
				'Connect with another application',
				'formulario-mensaje'
			),
			summary:
				webhooks > 0
					? sprintf(
							/* translators: %d: number of active webhooks. */
							_n(
								'%d active webhook',
								'%d active webhooks',
								webhooks,
								'formulario-mensaje'
							),
							webhooks
						)
					: __(
							'Webhooks, for whoever maintains the site',
							'formulario-mensaje'
						),
			content: (
				<WebhooksPanel
					title={ title }
					definition={ definition }
					onChange={ ( actions ) => onChange( { actions } ) }
				/>
			),
		},
	];

	return (
		<div className="fm-sections-list">
			{ sections.map( ( section ) => {
				const isOpen = open === section.name;

				return (
					<section
						key={ section.name }
						className={ `fm-section${ isOpen ? ' is-open' : '' }` }
					>
						<h3 className="fm-section__heading">
							<button
								type="button"
								className="fm-section__toggle"
								aria-expanded={ isOpen }
								onClick={ () =>
									setOpen( isOpen ? '' : section.name )
								}
							>
								<span className="fm-section__title">
									{ section.title }
								</span>
								<span className="fm-section__summary">
									{ section.summary }
								</span>
								<Dashicon
									icon={
										isOpen
											? 'arrow-up-alt2'
											: 'arrow-down-alt2'
									}
								/>
							</button>
						</h3>
						{ isOpen && (
							<div className="fm-section__body">
								{ section.content }
							</div>
						) }
					</section>
				);
			} ) }
		</div>
	);
}
