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
	 * Secretos que gestiona esta pantalla.
	 */
	private const SECRETS = array( Mail_Settings::SECRET_SMTP_PASSWORD, Mail_Settings::SECRET_RESEND_API_KEY );

	/**
	 * Constructor.
	 *
	 * @param Mail_Settings_Repository $settings  Almacén de ajustes de correo.
	 * @param Mail_Settings_Sanitizer  $sanitizer Saneado de ajustes de correo.
	 * @param Secret_Vault             $secrets   Almacén de secretos.
	 * @param Mail_Tester              $tester    Correos de prueba.
	 */
	public function __construct(
		private readonly Mail_Settings_Repository $settings,
		private readonly Mail_Settings_Sanitizer $sanitizer,
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
								'enum' => self::SECRETS,
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
		$settings = $this->sanitizer->sanitize( $request['settings'] );
		$secrets  = is_array( $request['secrets'] ) ? $request['secrets'] : array();

		$this->settings->save( $settings );

		foreach ( self::SECRETS as $name ) {
			$value = is_string( $secrets[ $name ] ?? null ) ? $secrets[ $name ] : '';

			// Un valor vacío significa «no lo he tocado»: el campo nunca muestra el secreto guardado.
			if ( '' !== $value ) {
				$this->secrets->set( $name, $value );
			} elseif ( in_array( $name, (array) $request['clear'], true ) ) {
				$this->secrets->set( $name, '' );
			}
		}

		return $this->respond( $settings );
	}

	/**
	 * Envía un correo de prueba con los ajustes guardados.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->tester->send_transport_test( sanitize_email( (string) $request['to'] ) );

		if ( is_wp_error( $result ) ) {
			// El mensaje del transporte es justo lo que hace falta para corregir la configuración.
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}

		return new WP_REST_Response( array( 'sent' => true ) );
	}

	/**
	 * Respuesta con los ajustes y el estado de cada credencial, sin su valor.
	 *
	 * @param Mail_Settings $settings Ajustes de correo.
	 */
	private function respond( Mail_Settings $settings ): WP_REST_Response {
		$secrets = array();

		foreach ( self::SECRETS as $name ) {
			$secrets[ $name ] = array(
				'source'   => $this->secrets->source( $name ),
				'constant' => $this->secrets->constant_name( $name ),
			);
		}

		return new WP_REST_Response(
			array(
				'settings' => $settings->to_array(),
				'secrets'  => $secrets,
			)
		);
	}
}
