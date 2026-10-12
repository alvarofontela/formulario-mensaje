import { Dashicon } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { can, urls } from './config';

/**
 * Barra con las secciones del plugin, fija en la parte de abajo de la pantalla.
 *
 * Solo se ve en móvil: en pantallas anchas las secciones ya están a la vista en el menú de
 * WordPress, que en el móvil queda escondido tras un botón. Cada entrada es un enlace normal a su
 * pantalla, y solo aparecen las que el usuario puede abrir.
 *
 * @param {Object} props
 * @param {string} props.current Sección en la que se está: `submissions`, `forms` o `settings`.
 * @param {number} props.unread  Mensajes sin leer.
 */
export default function SectionNav( { current, unread = 0 } ) {
	const sections = [
		{
			name: 'submissions',
			label: __( 'Messages', 'formulario-mensaje' ),
			icon: 'email-alt',
			show: can.viewSubmissions,
			count: unread,
		},
		{
			name: 'forms',
			label: __( 'Forms', 'formulario-mensaje' ),
			icon: 'feedback',
			show: can.manageForms,
		},
		{
			name: 'settings',
			label: __( 'Settings', 'formulario-mensaje' ),
			icon: 'admin-settings',
			show: can.manageSettings,
		},
	].filter( ( section ) => section.show );

	// Con una sola sección no hay adónde ir.
	if ( sections.length < 2 ) {
		return null;
	}

	return (
		<nav
			className="fm-sections"
			aria-label={ __( 'Sections of the plugin', 'formulario-mensaje' ) }
		>
			{ sections.map( ( section ) => (
				<a
					key={ section.name }
					className="fm-sections__item"
					href={ urls[ section.name ] }
					aria-current={
						section.name === current ? 'page' : undefined
					}
				>
					<Dashicon icon={ section.icon } />
					{ section.label }
					{ section.count > 0 && (
						<span className="fm-sections__count">
							<span aria-hidden="true">{ section.count }</span>
							<span className="screen-reader-text">
								{ sprintf(
									/* translators: %d: number of unread messages. */
									_n(
										'%d unread message',
										'%d unread messages',
										section.count,
										'formulario-mensaje'
									),
									section.count
								) }
							</span>
						</span>
					) }
				</a>
			) ) }
		</nav>
	);
}
