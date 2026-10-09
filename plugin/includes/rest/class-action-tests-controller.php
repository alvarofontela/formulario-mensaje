<?php
/**
 * Rutas REST para probar las acciones de un formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Mail\Mail_Tester;
use Formulario_Mensaje\Webhooks\Webhook_Tester;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST de las pruebas del constructor: enviar una notificación o un webhook del
 * formulario que se está editando, con datos de ejemplo.
 *
 * Reciben el formulario tal como está en pantalla, así que se puede probar antes de guardar.
 */
final class Action_Tests_Controller {

	/**
	 * Constructor.
	 *
	 * @param Form_Service   $forms          Servicio de formularios.
	 * @param Mail_Tester    $mail_tester    Correos de prueba.
	 * @param Webhook_Tester $webhook_tester Webhooks de prueba.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Mail_Tester $mail_tester,
		private readonly Webhook_Tester $webhook_tester
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		$form_args = array(
			'title'      => array(
				'description' => __( 'Form name.', 'formulario-mensaje' ),
				'type'        => 'string',
				'default'     => '',
			),
			'definition' => array(
				'description' => __( 'Fields, settings and actions of the form.', 'formulario-mensaje' ),
				'type'        => 'object',
				'default'     => array(),
			),
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/test-email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_email' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $form_args + array(
					'action_id' => array(
						'description' => __( 'Identifier of the notification to test.', 'formulario-mensaje' ),
						'type'        => 'string',
						'required'    => true,
					),
					'to'        => array(
						'description' => __( 'Address the test email is sent to.', 'formulario-mensaje' ),
						'type'        => 'string',
						'format'      => 'email',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/test-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_webhook' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $form_args + array(
					'action_id' => array(
						'description' => __( 'Identifier of the webhook to test.', 'formulario-mensaje' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Permiso para probar las acciones: el mismo que para editarlas.
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
	 * Envía una notificación del formulario en edición, con datos de ejemplo, a la dirección indicada.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->mail_tester->send_notification_test(
			$this->forms->build( $request['title'], $request['definition'] ),
			(string) $request['action_id'],
			sanitize_email( (string) $request['to'] ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return new WP_REST_Response( array( 'sent' => true ) );
	}

	/**
	 * Envía un webhook del formulario en edición, con datos de ejemplo, y devuelve la respuesta del destino.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->webhook_tester->test(
			$this->forms->build( $request['title'], $request['definition'] ),
			(string) $request['action_id'],
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result );
		}

		return new WP_REST_Response(
			array(
				'success'     => $result->is_success(),
				'status'      => $result->status_code,
				'body'        => $result->body,
				'duration_ms' => $result->duration_ms,
			)
		);
	}

	/**
	 * Da a un error su código de estado HTTP. El de un transporte o un destino no trae ninguno: es un
	 * fallo del otro extremo, no de la petición.
	 *
	 * @param WP_Error $error Error del servicio.
	 */
	private function with_status( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502 )
		);
	}
}
