<?php
/**
 * Ruta REST del mantenimiento.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Lifecycle\Maintenance_Schedule;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST para lanzar a mano la tarea diaria de mantenimiento, sin esperar a WP-Cron.
 */
final class Maintenance_Controller {

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/maintenance',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permiso para lanzar el mantenimiento: el mismo que para cambiar los ajustes.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage the plugin settings.', 'formulario-mensaje' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Ejecuta la tarea de mantenimiento ahora.
	 */
	public function run(): WP_REST_Response {
		/** Documentada en Formulario_Mensaje\Lifecycle\Maintenance_Schedule. */
		do_action( 'formulario_mensaje_daily_maintenance' );

		return new WP_REST_Response(
			array(
				'done'     => true,
				'next_run' => (int) wp_next_scheduled( Maintenance_Schedule::HOOK ),
			)
		);
	}
}
