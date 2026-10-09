<?php
/**
 * Rutas REST de gestión de formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Frontend\Form_Renderer;
use Formulario_Mensaje\Frontend\Frontend_Assets;
use Formulario_Mensaje\Frontend\Shortcode;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Submission_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST del constructor: listar, leer, crear, editar, previsualizar y borrar formularios.
 *
 * Todas las rutas exigen la capability de gestionar formularios, salvo el listado, que también pueden
 * leer (solo nombre e identificador de los publicados) quienes editan contenidos, para poder elegir
 * un formulario en el bloque. Los formularios no tienen propietario: la capability da acceso a todos.
 */
final class Forms_Controller {

	/**
	 * Constructor.
	 *
	 * @param Form_Service       $forms       Servicio de formularios.
	 * @param Form_Renderer      $renderer    HTML del formulario.
	 * @param Frontend_Assets    $assets      Estilos del formulario.
	 * @param Submission_Service $submissions Servicio de envíos guardados.
	 */
	public function __construct(
		private readonly Form_Service $forms,
		private readonly Form_Renderer $renderer,
		private readonly Frontend_Assets $assets,
		private readonly Submission_Service $submissions
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		$item_args = array(
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
			'/forms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_list' ),
					'args'                => array(
						'status' => array(
							'description' => __( 'Status of the forms to list.', 'formulario-mensaje' ),
							'type'        => 'string',
							'enum'        => array( Form::STATUS_PUBLISHED, Form::STATUS_TRASHED ),
							'default'     => Form::STATUS_PUBLISHED,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $item_args,
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $item_args,
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg + $item_args,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg + array(
						'force'              => array(
							'description' => __( 'Delete permanently instead of moving to the trash.', 'formulario-mensaje' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'delete_submissions' => array(
							'description' => __( 'When deleting permanently, delete the stored submissions of the form too.', 'formulario-mensaje' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/forms/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_item' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);
	}

	/**
	 * Permiso para gestionar formularios.
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
	 * Permiso para listar formularios: quien los gestiona o quien edita contenidos.
	 *
	 * @return true|WP_Error
	 */
	public function can_list(): bool|WP_Error {
		return current_user_can( 'edit_posts' ) ? true : $this->can_manage();
	}

	/**
	 * Lista los formularios.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$is_manager = current_user_can( Capabilities::MANAGE_FORMS );

		// Quien solo edita contenidos ve los publicados, y de ellos solo lo necesario para elegirlos.
		$status = $is_manager ? (string) $request['status'] : Form::STATUS_PUBLISHED;
		$counts = $this->submission_counts();

		return new WP_REST_Response(
			array_map(
				fn ( Form $form ): array => $is_manager
					? $this->summary( $form, $counts )
					: array(
						'id'    => $form->id,
						'title' => $form->title,
					),
				$this->forms->list( $status )
			)
		);
	}

	/**
	 * Devuelve un formulario con su definición.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->forms->get( (int) $request['id'] );

		if ( null === $form ) {
			return new WP_Error( 'formulario_mensaje_form_not_found', __( 'The form does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->detail( $form ) );
	}

	/**
	 * Crea un formulario.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->forms->create( $request['title'], $request['definition'] );

		return is_wp_error( $form ) ? $form : new WP_REST_Response( $this->detail( $form ), 201 );
	}

	/**
	 * Actualiza un formulario.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->forms->update( (int) $request['id'], $request['title'], $request['definition'] );

		return is_wp_error( $form ) ? $form : new WP_REST_Response( $this->detail( $form ) );
	}

	/**
	 * Envía un formulario a la papelera o, con `force`, lo borra definitivamente.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id                 = (int) $request['id'];
		$force              = (bool) $request['force'];
		$delete_submissions = $force && $request['delete_submissions'];

		/*
		 * Borrar los envíos es borrar datos personales: exige la misma capability que la ruta de borrado
		 * de envíos. Sin ella la petición se rechaza entera, antes de tocar nada: aceptarla a medias
		 * dejaría al usuario creyendo que los envíos se han borrado.
		 */
		if ( $delete_submissions && ! current_user_can( Capabilities::VIEW_SUBMISSIONS ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to delete submissions.', 'formulario-mensaje' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( null === $this->forms->get( $id ) ) {
			return new WP_Error( 'formulario_mensaje_form_not_found', __( 'The form does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		// Primero los envíos: si el borrado se interrumpe, el formulario sigue ahí para repetirlo.
		if ( $delete_submissions ) {
			$this->submissions->delete_for_form( $id );
		}

		$result = $force ? $this->forms->delete( $id ) : $this->forms->trash( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'id'      => $id,
				'deleted' => $force,
				'trashed' => ! $force,
			)
		);
	}

	/**
	 * Recupera un formulario de la papelera.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->forms->restore( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$form = $this->forms->get( (int) $request['id'] );

		return new WP_REST_Response( null === $form ? array() : $this->summary( $form, $this->submission_counts() ) );
	}

	/**
	 * Devuelve el HTML de un formulario sin guardar, para la vista previa del constructor.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$form = $this->forms->build( $request['title'], $request['definition'] );

		return new WP_REST_Response(
			array(
				'html'        => $this->renderer->render( $form, '', true, false ),
				'stylesheets' => array( add_query_arg( 'ver', $this->assets->version(), $this->assets->style_url() ) ),
				// Estilos globales del tema, para que la vista previa se parezca a la página real.
				'theme_css'   => wp_get_global_stylesheet(),
			)
		);
	}

	/**
	 * Datos de un formulario para los listados.
	 *
	 * @param Form                 $form   Formulario.
	 * @param array<int, int>|null $counts Envíos guardados por formulario, o null si el usuario no puede verlos.
	 * @return array<string, mixed>
	 */
	private function summary( Form $form, ?array $counts ): array {
		return array(
			'id'          => $form->id,
			'title'       => $form->title,
			'status'      => $form->status,
			'modified'    => '' === $form->modified ? '' : mysql_to_rfc3339( $form->modified ),
			'field_count' => count( $form->definition->fields ),
			'shortcode'   => sprintf( '[%s id="%d"]', Shortcode::TAG, $form->id ),
		) + ( null === $counts ? array() : array( 'submission_count' => $counts[ $form->id ] ?? 0 ) );
	}

	/**
	 * Número de envíos guardados de cada formulario, solo para quien puede ver los envíos.
	 *
	 * @return array<int, int>|null Null si el usuario no puede verlos.
	 */
	private function submission_counts(): ?array {
		return current_user_can( Capabilities::VIEW_SUBMISSIONS ) ? $this->submissions->counts_by_form() : null;
	}

	/**
	 * Datos completos de un formulario, con su definición.
	 *
	 * @param Form $form Formulario.
	 * @return array<string, mixed>
	 */
	private function detail( Form $form ): array {
		return $this->summary( $form, $this->submission_counts() ) + array( 'definition' => $form->definition->to_array() );
	}
}
