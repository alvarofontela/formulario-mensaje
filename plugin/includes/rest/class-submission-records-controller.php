<?php
/**
 * Rutas REST de los envíos guardados.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Rest;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Service;
use Formulario_Mensaje\Lifecycle\Capabilities;
use Formulario_Mensaje\Storage\Action_Retrier;
use Formulario_Mensaje\Storage\Delivery;
use Formulario_Mensaje\Storage\Stored_Submission;
use Formulario_Mensaje\Storage\Submission_Query;
use Formulario_Mensaje\Storage\Submission_Service;
use Formulario_Mensaje\Support\Plain_Text;
use Formulario_Mensaje\Uploads\Download_Links;
use Formulario_Mensaje\Uploads\Stored_File;
use Formulario_Mensaje\Uploads\Upload_Store;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptador REST de los envíos guardados y de su registro de entregas.
 *
 * Todo lo que devuelve son datos personales de visitantes: todas las rutas exigen la capability de
 * ver envíos. Los envíos no tienen propietario, así que la capability da acceso a todos.
 */
final class Submission_Records_Controller {

	/**
	 * Constructor.
	 *
	 * @param Submission_Service   $submissions  Servicio de envíos guardados.
	 * @param Action_Retrier       $retrier      Reintento de acciones fallidas.
	 * @param Form_Service         $forms        Servicio de formularios.
	 * @param Action_Type_Registry $action_types Tipos de acción.
	 * @param Upload_Store         $uploads      Ficheros de los envíos.
	 * @param Download_Links       $downloads    Enlaces de descarga.
	 */
	public function __construct(
		private readonly Submission_Service $submissions,
		private readonly Action_Retrier $retrier,
		private readonly Form_Service $forms,
		private readonly Action_Type_Registry $action_types,
		private readonly Upload_Store $uploads,
		private readonly Download_Links $downloads
	) {}

	/**
	 * Registra las rutas.
	 */
	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		$paging = array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/submissions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => $paging + array(
					'form'   => array(
						'description' => __( 'Limit the list to the submissions of this form.', 'formulario-mensaje' ),
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
					),
					'search' => array(
						'description' => __( 'Text to look for in the reference and in the values.', 'formulario-mensaje' ),
						'type'        => 'string',
						'default'     => '',
					),
					'failed' => array(
						'description' => __( 'Only submissions with failed deliveries.', 'formulario-mensaje' ),
						'type'        => 'boolean',
						'default'     => false,
					),
					'unread' => array(
						'description' => __( 'Only submissions that nobody has read yet.', 'formulario-mensaje' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/submissions/counts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_counts' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/submissions/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'ids'  => array(
						'description' => __( 'Identifiers of the submissions to mark.', 'formulario-mensaje' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'maxItems'    => 500,
						'default'     => array(),
					),
					'read' => array(
						'description' => __( 'Mark them as read (true) or as unread (false).', 'formulario-mensaje' ),
						'type'        => 'boolean',
						'default'     => true,
					),
					'all'  => array(
						'description' => __( 'Mark every unread submission as read, whatever the identifiers.', 'formulario-mensaje' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/submissions/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete_items' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'ids' => array(
						'description' => __( 'Identifiers of the submissions to delete.', 'formulario-mensaje' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'maxItems'    => 500,
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/submissions/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/deliveries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_failed_deliveries' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => $paging,
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/deliveries/(?P<id>\d+)/retry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'retry_delivery' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			Rest_Provider::ROUTE_NAMESPACE,
			'/deliveries/(?P<id>\d+)/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_delivery' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => $id_arg,
			)
		);
	}

	/**
	 * Permiso para ver y gestionar los envíos.
	 *
	 * @return true|WP_Error
	 */
	public function can_view(): bool|WP_Error {
		if ( current_user_can( Capabilities::VIEW_SUBMISSIONS ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to view submissions.', 'formulario-mensaje' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Lista los envíos.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$query  = new Submission_Query(
			(int) $request['form'],
			mb_substr( Plain_Text::line( (string) $request['search'] ), 0, 200 ),
			(bool) $request['failed'],
			'',
			'',
			(int) $request['page'],
			(int) $request['per_page'],
			(bool) $request['unread']
		);
		$result = $this->submissions->search( $query );
		$forms  = array();
		$items  = array();

		foreach ( $result['items'] as $submission ) {
			$items[] = $this->summary( $submission, $forms );
		}

		return $this->paginated( $items, $result['total'], $query->per_page );
	}

	/**
	 * Devuelve cuántos envíos hay sin leer y cuántas entregas fallidas quedan pendientes.
	 */
	public function get_counts(): WP_REST_Response {
		return new WP_REST_Response( $this->submissions->counts() );
	}

	/**
	 * Marca envíos como leídos o como no leídos.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		$updated = (bool) $request['all']
			? $this->submissions->mark_all_read()
			: $this->submissions->mark_read( (array) $request['ids'], (bool) $request['read'] );

		return new WP_REST_Response( array( 'updated' => $updated ) + $this->submissions->counts() );
	}

	/**
	 * Devuelve un envío con todos sus datos y sus entregas.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$submission = $this->submissions->get( (int) $request['id'] );

		if ( null === $submission ) {
			return $this->not_found();
		}

		$entries = array();

		foreach ( $submission->entries as $entry ) {
			$entries[] = array(
				'field_id' => $entry->field_id,
				'type'     => $entry->type,
				'label'    => $entry->label,
				'value'    => $entry->formatted,
			);
		}

		$forms      = array();
		$deliveries = array();

		foreach ( $this->submissions->deliveries( $submission->id ) as $delivery ) {
			$deliveries[] = $this->delivery( $delivery, $forms );
		}

		return new WP_REST_Response(
			$this->summary( $submission, $forms ) + array(
				'ip'            => $submission->ip,
				// Campos que ya se ven en la cabecera del mensaje, para no repetirlos entre los demás datos.
				'header_fields' => $submission->header_field_ids(),
				'user_agent'    => $submission->user_agent,
				'entries'       => $entries,
				'files'         => array_map(
					fn ( Stored_File $file ): array => array(
						'field_id' => $file->field_id,
						'name'     => $file->name,
						'size'     => (string) size_format( $file->size, 1 ),
						// Enlace para quien tiene sesión y el permiso de ver envíos: no sirve sin ellos.
						'url'      => $this->downloads->admin( $file ),
					),
					$this->uploads->for_submission( $submission->id )
				),
				'deliveries'    => $deliveries,
			)
		);
	}

	/**
	 * Borra un envío.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 0 === $this->submissions->delete( array( (int) $request['id'] ) ) ) {
			return $this->not_found();
		}

		return new WP_REST_Response( array( 'deleted' => 1 ) );
	}

	/**
	 * Borra varios envíos.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function delete_items( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'deleted' => $this->submissions->delete( (array) $request['ids'] ) ) );
	}

	/**
	 * Lista las entregas fallidas pendientes.
	 *
	 * @param WP_REST_Request $request Petición.
	 */
	public function get_failed_deliveries( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->submissions->failed_deliveries( (int) $request['page'], (int) $request['per_page'] );
		$forms  = array();
		$items  = array();

		foreach ( $result['items'] as $delivery ) {
			$items[] = $this->delivery( $delivery, $forms );
		}

		return $this->paginated( $items, $result['total'], (int) $request['per_page'] );
	}

	/**
	 * Reintenta una entrega fallida.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry_delivery( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$delivery = $this->retrier->retry( (int) $request['id'] );
		$forms    = array();

		return is_wp_error( $delivery ) ? $delivery : new WP_REST_Response( $this->delivery( $delivery, $forms ) );
	}

	/**
	 * Descarta una entrega fallida.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_delivery( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->submissions->dismiss( (int) $request['id'] );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'dismissed' => true ) );
	}

	/**
	 * Datos de un envío para los listados.
	 *
	 * @param Stored_Submission     $submission Envío.
	 * @param array<int, Form|null> $forms      Formularios ya leídos en esta petición, por identificador.
	 * @return array<string, mixed>
	 */
	private function summary( Stored_Submission $submission, array &$forms ): array {
		$form = $this->form( $submission->form_id, $forms );

		return array(
			'id'             => $submission->id,
			'form_id'        => $submission->form_id,
			'form_title'     => null === $form ? '' : $form->title,
			'reference'      => $submission->reference,
			'created_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $submission->created_at ),
			'page_url'       => $submission->page_url,
			'summary'        => $submission->summary(),
			'failed_actions' => $submission->failed_actions,
			'read'           => $submission->is_read(),
			// Para presentarlo como un mensaje: quién escribe, cómo contactarle y cómo empieza.
			'sender'         => $submission->sender(),
			'email'          => $submission->email(),
			'phone'          => $submission->phone(),
			'excerpt'        => $submission->excerpt(),
			'has_files'      => $submission->has_files(),
		);
	}

	/**
	 * Datos de una entrega.
	 *
	 * @param Delivery              $delivery Entrega.
	 * @param array<int, Form|null> $forms    Formularios ya leídos en esta petición, por identificador.
	 * @return array<string, mixed>
	 */
	private function delivery( Delivery $delivery, array &$forms ): array {
		$type   = $this->action_types->get( $delivery->action_type );
		$form   = $this->form( $delivery->form_id, $forms );
		$action = null === $form ? null : $form->definition->get_action( $delivery->action_id, $delivery->action_type );

		return array(
			'id'            => $delivery->id,
			'submission_id' => $delivery->submission_id,
			'form_id'       => $delivery->form_id,
			'form_title'    => null === $form ? '' : $form->title,
			'action_id'     => $delivery->action_id,
			'action_type'   => $delivery->action_type,
			'action_label'  => null === $type ? $delivery->action_type : $type->get_label(),
			// El nombre que se le dio a la acción: con dos notificaciones o dos webhooks, es lo que dice cuál es.
			'action_name'   => is_string( $action['name'] ?? null ) ? $action['name'] : '',
			'status'        => $delivery->status->value,
			'attempts'      => $delivery->attempts,
			'error'         => $delivery->error,
			'created_at'    => gmdate( 'Y-m-d\TH:i:s\Z', $delivery->created_at ),
			'updated_at'    => gmdate( 'Y-m-d\TH:i:s\Z', $delivery->updated_at ),
			'next_attempt'  => $delivery->next_attempt_at > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $delivery->next_attempt_at ) : null,
			'can_retry'     => $delivery->can_retry(),
		);
	}

	/**
	 * Formulario de un envío o de una entrega, leído una sola vez por petición.
	 *
	 * La lista vive en la petición y no en el controlador, que dura más que ella: leer un formulario
	 * es decodificar su definición entera, y un listado repite el mismo formulario en muchas filas.
	 *
	 * @param int                   $form_id Identificador del formulario.
	 * @param array<int, Form|null> $forms   Formularios ya leídos en esta petición, por identificador.
	 */
	private function form( int $form_id, array &$forms ): ?Form {
		if ( ! array_key_exists( $form_id, $forms ) ) {
			$forms[ $form_id ] = $this->forms->get( $form_id );
		}

		return $forms[ $form_id ];
	}

	/**
	 * Respuesta de un listado, con el total y el número de páginas en las cabeceras habituales.
	 *
	 * @param array<int, array<string, mixed>> $items    Elementos de la página.
	 * @param int                              $total    Total de elementos.
	 * @param int                              $per_page Elementos por página.
	 */
	private function paginated( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = new WP_REST_Response( $items );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * Error de envío inexistente.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'formulario_mensaje_submission_not_found', __( 'The submission does not exist.', 'formulario-mensaje' ), array( 'status' => 404 ) );
	}
}
