<?php
/**
 * Rutas REST para duplicar, exportar e importar formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Porter;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Lifecycle\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST de las operaciones que crean un formulario a partir de otro: duplicarlo, y llevarlo
 * de una web a otra como un documento JSON.
 */
final class Form_Transfer_Controller {

	/**
	 * Constructor.
	 *
	 * @param Form_Service $forms  Servicio de formularios.
	 * @param Form_Porter  $porter Exportación e importación.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Form_Porter $porter
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'description' => __( 'Form identifier.', 'formulario-mensaje' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
			),
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/(?P<id>\d+)/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'document' => array(
						'description' => __( 'Content of a form exported from Formulario Mensaje.', 'formulario-mensaje' ),
						'type'        => 'object',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Permiso para duplicar, exportar e importar: el mismo que para gestionar formularios. No hay
	 * formularios con propietario: la capability da acceso a todos.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		if ( current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage forms.', 'formulario-mensaje' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Crea una copia de un formulario.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$copy = $this->forms->duplicate( (int) $request['id'] );

		return is_wp_error( $copy ) ? $copy : $this->created( $copy );
	}

	/**
	 * Devuelve el documento de exportación de un formulario.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->forms->get( (int) $request['id'] );

		if ( null === $form ) {
			return new WP_Error( 'formulario_mensaje_form_not_found', __( 'The form does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->porter->export( $form ) );
	}

	/**
	 * Crea un formulario a partir de un documento de exportación.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->porter->import( $request['document'] );

		return is_wp_error( $form ) ? $form : $this->created( $form );
	}

	/**
	 * Respuesta de un formulario recién creado.
	 *
	 * @param Form $form Formulario.
	 */
	private function created( Form $form ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'id'    => $form->id,
				'title' => $form->title,
			),
			201
		);
	}
}
