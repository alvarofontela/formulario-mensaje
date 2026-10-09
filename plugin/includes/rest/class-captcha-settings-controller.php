<?php
/**
 * Rutas REST de los ajustes del captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Captcha\Captcha_Config;
use Formulario_Mensaje\Captcha\Captcha_Config_Repository;
use Formulario_Mensaje\Captcha\Captcha_Config_Sanitizer;
use Formulario_Mensaje\Captcha\Captcha_Provider;
use Formulario_Mensaje\Captcha\Captcha_Provider_Registry;
use Formulario_Mensaje\Lifecycle\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST de los ajustes del captcha: proveedor, clave pública, umbral y clave secreta.
 *
 * La clave secreta solo viaja hacia el servidor. Las respuestas dicen si está definida y de dónde
 * sale, nunca su valor.
 */
final class Captcha_Settings_Controller {

	/**
	 * Constructor.
	 *
	 * @param Captcha_Config_Repository $config    Ajustes del captcha.
	 * @param Captcha_Config_Sanitizer  $sanitizer Saneado de los ajustes.
	 * @param Captcha_Provider_Registry $providers Proveedores de captcha.
	 */
	public function __construct(
		private readonly Captcha_Config_Repository $config,
		private readonly Captcha_Config_Sanitizer $sanitizer,
		private readonly Captcha_Provider_Registry $providers
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/settings/captcha',
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
						'settings'     => array(
							'description' => __( 'Captcha service, site key and score threshold.', 'formulario-mensaje' ),
							'type'        => 'object',
							'default'     => array(),
						),
						'secret'       => array(
							'description' => __( 'New secret key. An empty value keeps the stored one.', 'formulario-mensaje' ),
							'type'        => 'string',
							'default'     => '',
						),
						'clear_secret' => array(
							'description' => __( 'Delete the stored secret key.', 'formulario-mensaje' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Permiso para leer y cambiar los ajustes del captcha.
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
	 * Devuelve los ajustes del captcha.
	 */
	public function get_settings(): WP_REST_Response {
		return $this->respond( $this->config->get(), false );
	}

	/**
	 * Guarda los ajustes del captcha y, si llega, la clave secreta.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$config    = $this->sanitizer->sanitize( $request['settings'] );
		$discarded = $this->config->save( $config, trim( (string) $request['secret'] ), (bool) $request['clear_secret'] );

		return $this->respond( $config, $discarded );
	}

	/**
	 * Respuesta con los ajustes, los proveedores disponibles y el estado de la clave secreta, sin su valor.
	 *
	 * @param Captcha_Config $config    Ajustes del captcha.
	 * @param bool           $discarded Si la clave secreta guardada se ha descartado al cambiar de proveedor.
	 */
	private function respond( Captcha_Config $config, bool $discarded ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'settings'  => $config->to_array(),
				'providers' => array_map(
					static fn ( Captcha_Provider $provider ): array => array(
						'id'    => $provider->get_id(),
						'label' => $provider->get_label(),
					),
					$this->providers->all()
				),
				'secret'    => array(
					'source'   => $this->config->secret_source(),
					'constant' => $this->config->secret_constant(),
				),
				'discarded' => $discarded,
			)
		);
	}
}
