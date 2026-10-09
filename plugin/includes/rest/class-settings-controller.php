<?php
/**
 * Rutas REST de los ajustes.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Antispam\Client_Ip_Resolver;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Settings\Settings;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Settings\Settings_Sanitizer;
use Formulario_Mensaje\Settings\Trusted_Proxy_Mode;
use Formulario_Mensaje\Support\Server_Vars;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST de los ajustes generales. Exige la capability de ajustes para leer y para guardar.
 */
final class Settings_Controller {

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings    Almacén de ajustes.
	 * @param Settings_Sanitizer  $sanitizer   Saneado de ajustes.
	 * @param Client_Ip_Resolver  $ip_resolver IP del visitante.
	 * @param Server_Vars         $server      Variables del servidor.
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly Settings_Sanitizer $sanitizer,
		private readonly Client_Ip_Resolver $ip_resolver,
		private readonly Server_Vars $server
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		$list = array(
			'type'    => 'array',
			'items'   => array( 'type' => 'string' ),
			'default' => array(),
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/settings',
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
						'trusted_proxy_mode'       => array(
							'description' => __( 'Where the visitor IP address is taken from.', 'formulario-mensaje' ),
							'type'        => 'string',
							'enum'        => array_column( Trusted_Proxy_Mode::cases(), 'value' ),
							'default'     => Trusted_Proxy_Mode::None->value,
						),
						'trusted_proxies'          => array( 'description' => __( 'IP addresses or CIDR ranges of your own proxy.', 'formulario-mensaje' ) ) + $list,
						'allowed_redirect_hosts'   => array( 'description' => __( 'External domains that forms may redirect to.', 'formulario-mensaje' ) ) + $list,
						'accept_no_js'             => array(
							'description' => __( 'Accept submissions sent without JavaScript.', 'formulario-mensaje' ),
							'type'        => 'boolean',
							'default'     => true,
						),
						'delete_data_on_uninstall' => array(
							'description' => __( 'Delete forms, submissions and settings when the plugin is uninstalled.', 'formulario-mensaje' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Permiso para leer y cambiar los ajustes.
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
	 * Devuelve los ajustes.
	 */
	public function get_settings(): WP_REST_Response {
		return $this->respond( $this->settings->get() );
	}

	/**
	 * Guarda los ajustes.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$settings = $this->sanitizer->sanitize(
			array(
				'trusted_proxy_mode'       => $request['trusted_proxy_mode'],
				'trusted_proxies'          => $request['trusted_proxies'],
				'allowed_redirect_hosts'   => $request['allowed_redirect_hosts'],
				'accept_no_js'             => $request['accept_no_js'],
				'delete_data_on_uninstall' => $request['delete_data_on_uninstall'],
			)
		);

		$this->settings->save( $settings );

		return $this->respond( $settings );
	}

	/**
	 * Respuesta con los ajustes y con la IP que el plugin ve, para comprobar la configuración del proxy.
	 *
	 * @param Settings $settings Ajustes.
	 */
	private function respond( Settings $settings ): WP_REST_Response {
		return new WP_REST_Response(
			$settings->to_array() + array(
				'detected_ip'   => $this->ip_resolver->resolve(),
				'connection_ip' => $this->server->get( 'REMOTE_ADDR' ),
			)
		);
	}
}
