<?php
/**
 * Rutas REST públicas del formulario.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Antispam\Honeypot_Check;
use Formulario_Mensaje\Antispam\Token_Service;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Handler;
use Formulario_Mensaje\Submissions\Submission_Request_Factory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST del envío público: emite el token antispam y recibe los envíos.
 *
 * Son las dos únicas rutas del plugin abiertas a visitantes anónimos.
 */
final class Submissions_Controller {

	/**
	 * Constructor.
	 *
	 * @param Submission_Handler         $handler  Servicio de envío.
	 * @param Submission_Request_Factory $requests Construcción de peticiones de envío.
	 * @param Token_Service              $tokens   Token antispam.
	 * @param Form_Repository            $forms    Almacén de formularios.
	 */
	public function __construct(
		private readonly Submission_Handler $handler,
		private readonly Submission_Request_Factory $requests,
		private readonly Token_Service $tokens,
		private readonly Form_Repository $forms
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

		/*
		 * Rutas públicas a propósito: las usa el formulario que ven los visitantes anónimos. No dependen
		 * de un nonce para que funcionen en páginas servidas desde caché. La defensa contra el abuso es
		 * la del servicio de envío: token con tiempo mínimo, campo trampa y límite por IP.
		 */
		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/(?P<id>\d+)/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_token' ),
				'permission_callback' => '__return_true',
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/(?P<id>\d+)/submissions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_submission' ),
				'permission_callback' => '__return_true',
				'args'                => $id_arg + array(
					Render_Context::VALUES_PARAM => array(
						'description' => __( 'Field values, by field identifier.', 'formulario-mensaje' ),
						'type'        => 'object',
						'default'     => array(),
					),
					'token'                      => array(
						'description' => __( 'Anti-spam token issued for this form.', 'formulario-mensaje' ),
						'type'        => 'string',
						'default'     => '',
					),
					'captcha'                    => array(
						'description' => __( 'Token of the captcha service, if the form uses one.', 'formulario-mensaje' ),
						'type'        => 'string',
						'default'     => '',
					),
					Honeypot_Check::FIELD_NAME   => array(
						'description' => __( 'Must be empty.', 'formulario-mensaje' ),
						'type'        => 'string',
						'default'     => '',
					),
				),
			)
		);
	}

	/**
	 * Emite el token antispam de un formulario.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->forms->find( (int) $request['id'] );

		if ( null === $form || ! $form->is_published() ) {
			return new WP_Error( 'formulario_mensaje_form_unavailable', __( 'This form is no longer available.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		$response = new WP_REST_Response(
			array(
				'token' => $this->tokens->issue( $form->id ),
				// Segundos que el script espera antes de enviar, para cumplir el tiempo mínimo de relleno.
				'wait'  => $this->tokens->min_seconds(),
			)
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Recibe un envío.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_submission( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->handler->handle(
			$this->requests->create(
				(int) $request['id'],
				// Los valores los sanea y valida cada tipo de campo dentro del servicio.
				$request->get_param( Render_Context::VALUES_PARAM ),
				$request->get_param( Honeypot_Check::FIELD_NAME ),
				$request->get_param( 'token' ),
				Submission_Channel::Rest,
				$request->get_param( 'captcha' )
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response( $result->to_array() );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
