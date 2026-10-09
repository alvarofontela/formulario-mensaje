<?php
/**
 * Rutas REST de los ajustes de correo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Mail\Mail_Settings;
use Formulario_Mensaje\Mail\Mail_Settings_Repository;
use Formulario_Mensaje\Mail\Mail_Settings_Sanitizer;
use Formulario_Mensaje\Mail\Mail_Settings_Service;
use Formulario_Mensaje\Mail\Mail_Tester;
use Formulario_Mensaje\Secrets\Secret_Vault;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST de los ajustes de correo: transporte, remitente, marca y credenciales.
 *
 * Las credenciales solo viajan hacia el servidor. Las respuestas dicen si cada una está definida y
 * de dónde sale, nunca su valor.
 */
final class Mail_Settings_Controller {

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings  Almacén de ajustes de correo.
	 * @param Mail_Settings_Sanitizer  $sanitizer Saneado de ajustes de correo.
	 * @param Mail_Settings_Service    $service   Guardado de los ajustes y sus credenciales.
	 * @param Secret_Vault             $secrets   Almacén de secretos, para decir de dónde sale cada uno.
	 * @param Mail_Tester              $tester    Correos de prueba.
	 */
	public function __construct(
		private readonly Mail_Settings_Repository $settings,
		private readonly Mail_Settings_Sanitizer $sanitizer,
		private readonly Mail_Settings_Service $service,
		private readonly Secret_Vault $secrets,
		private readonly Mail_Tester $tester
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/settings/mail',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'settings' => array(
							'description' => __( 'Transport, sender and brand of the emails.', 'formulario-mensaje' ),
							'type'        => 'object',
							'default'     => array(),
						),
						'secrets'  => array(
							'description' => __( 'New values for the credentials. An empty value keeps the stored one.', 'formulario-mensaje' ),
							'type'        => 'object',
							'default'     => array(),
						),
						'clear'    => array(
							'description' => __( 'Names of the stored credentials to delete.', 'formulario-mensaje' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => Mail_Settings_Service::SECRETS,
							),
							'default'     => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/settings/mail/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_test' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'to' => array(
						'description' => __( 'Address the test email is sent to.', 'formulario-mensaje' ),
						'type'        => 'string',
						'format'      => 'email',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Permiso para leer y cambiar los ajustes de correo.
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
	 * Devuelve los ajustes de correo.
	 */
	public function get_settings(): WP_REST_Response {
		return $this->respond( $this->settings->get() );
	}

	/**
	 * Guarda los ajustes de correo y, si llegan, las credenciales.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$settings  = $this->sanitizer->sanitize( $request['settings'] );
		$discarded = $this->service->update(
			$settings,
			is_array( $request['secrets'] ) ? $request['secrets'] : array(),
			array_filter( (array) $request['clear'], 'is_string' )
		);

		return $this->respond( $settings, $discarded );
	}

	/**
	 * Envía un correo de prueba con los ajustes guardados.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->tester->send_transport_test( sanitize_email( (string) $request['to'] ), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			// El mensaje del transporte es justo lo que hace falta para corregir la configuración.
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502 ) );
		}

		return new WP_REST_Response( array( 'sent' => true ) );
	}

	/**
	 * Respuesta con los ajustes y el estado de cada credencial, sin su valor.
	 *
	 * @param Mail_Settings $settings  Ajustes de correo.
	 * @param string[]      $discarded Credenciales descartadas al guardar sin que nadie lo pidiera.
	 */
	private function respond( Mail_Settings $settings, array $discarded = array() ): WP_REST_Response {
		$secrets = array();

		foreach ( Mail_Settings_Service::SECRETS as $name ) {
			$secrets[ $name ] = array(
				'source'   => $this->secrets->source( $name ),
				'constant' => $this->secrets->constant_name( $name ),
			);
		}

		return new WP_REST_Response(
			array(
				'settings'  => $settings->to_array(),
				'secrets'   => $secrets,
				'discarded' => $discarded,
			)
		);
	}
}
