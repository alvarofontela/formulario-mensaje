/**
 * Aplicación de administración de Formulario Mensaje: listado de formularios, constructor y ajustes.
 */
import { createRoot } from '@wordpress/element';

import App from './app';
import './admin.scss';

const container = document.getElementById( 'formulario-mensaje-admin' );

if ( container ) {
	createRoot( container ).render(
		<App initialView={ container.dataset.view } />
	);
}
