<?php
/**
 * Ejecución de la acción de una entrega.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Uploads\Submission_Uploads;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta sobre un envío la acción a la que se refiere una entrega, con su configuración actual.
 *
 * Lo comparten el reintento manual y la cola: si el fallo se debía a la configuración (un destino
 * mal escrito, por ejemplo), basta corregirla para que el siguiente intento salga bien.
 *
 * La acción recibe lo mismo que habría recibido al llegar el envío: los enlaces de descarga de sus
 * ficheros no se guardan con él, así que se vuelven a componer aquí.
 */
final class Delivery_Executor {

	/**
	 * Clave, en los datos de un error, que marca los fallos que un nuevo intento no puede arreglar.
	 */
	private const PERMANENT = 'permanent';

	/**
	 * Constructor.
	 *
	 * @param Form_Repository      $forms        Almacén de formularios.
	 * @param Action_Type_Registry $action_types Tipos de acción.
	 * @param Submission_Uploads   $uploads      Ficheros de los envíos.
	 */
	public function __construct(
		private readonly Form_Repository $forms,
		private readonly Action_Type_Registry $action_types,
		private readonly Submission_Uploads $uploads
	) {}

	/**
	 * Ejecuta la acción de una entrega.
	 *
	 * @param Delivery   $delivery   Entrega.
	 * @param Submission $submission Envío sobre el que se ejecuta.
	 * @return true|WP_Error True si la acción se completó.
	 */
	public function execute( Delivery $delivery, Submission $submission ): bool|WP_Error {
		$form = $this->forms->find( $delivery->form_id );

		if ( null === $form ) {
			return $this->permanent( 'formulario_mensaje_delivery_not_retryable', __( 'The submission or its form no longer exists.', 'formulario-mensaje' ), 404 );
		}

		$current = $form->definition->get_action( $delivery->action_id, $delivery->action_type );
		$type    = $this->action_types->get( $delivery->action_type );

		if ( null === $current || null === $type ) {
			return $this->permanent( 'formulario_mensaje_action_not_found', __( 'The form no longer has this action.', 'formulario-mensaje' ), 409 );
		}

		// Quien desactivó la acción decidió que dejara de ejecutarse: ni reintentar ni la cola pueden saltárselo.
		if ( ! $current['enabled'] ) {
			return $this->permanent( 'formulario_mensaje_action_disabled', __( 'This action is turned off in the form. Turn it on to retry.', 'formulario-mensaje' ), 409 );
		}

		// Un envío leído de la base de datos no trae los enlaces de descarga: sin esto, la notificación reintentada saldría sin ellos.
		$with_links = $submission->with_entries(
			$this->uploads->with_links( $form, $submission->reference, $delivery->submission_id, $submission->entries )
		);

		return $type->execute( $current['settings'], $with_links, $form );
	}

	/**
	 * Indica si un error es de los que un nuevo intento no puede arreglar: falta el formulario o la
	 * acción, o está desactivada.
	 *
	 * @param WP_Error $error Error devuelto por execute().
	 */
	public function is_permanent( WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data ) && ! empty( $data[ self::PERMANENT ] );
	}

	/**
	 * Construye un error permanente con su código de estado HTTP.
	 *
	 * @param string $code    Código del error.
	 * @param string $message Mensaje ya traducido.
	 * @param int    $status  Código de estado HTTP.
	 */
	private function permanent( string $code, string $message, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'        => $status,
				self::PERMANENT => true,
			)
		);
	}
}
