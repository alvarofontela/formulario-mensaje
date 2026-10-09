<?php
/**
 * Reintento de acciones fallidas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Actions\Action_Type_Registry;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Vuelve a ejecutar sobre un envío guardado la acción que falló.
 *
 * Se usa la configuración actual de la acción en el formulario: si el fallo se debía a ella (un
 * destinatario mal escrito, por ejemplo), basta corregirla y reintentar.
 */
final class Action_Retrier {

	/**
	 * Constructor.
	 *
	 * @param Delivery_Repository   $deliveries   Registro de entregas.
	 * @param Submission_Repository $submissions  Almacén de envíos.
	 * @param Form_Repository       $forms        Almacén de formularios.
	 * @param Action_Type_Registry  $action_types Tipos de acción.
	 * @param Failure_Counter       $failures     Contador de entregas fallidas.
	 * @param Clock                 $clock        Reloj.
	 */
	public function __construct(
		private readonly Delivery_Repository $deliveries,
		private readonly Submission_Repository $submissions,
		private readonly Form_Repository $forms,
		private readonly Action_Type_Registry $action_types,
		private readonly Failure_Counter $failures,
		private readonly Clock $clock
	) {}

	/**
	 * Reintenta una entrega fallida.
	 *
	 * @param int $delivery_id Identificador de la entrega.
	 * @return Delivery|WP_Error La entrega con su nuevo resultado, haya ido bien o mal; un error si no se pudo reintentar.
	 */
	public function retry( int $delivery_id ): Delivery|WP_Error {
		$delivery = $this->deliveries->find( $delivery_id );

		if ( null === $delivery || ! $delivery->can_retry() ) {
			return $this->error( 'formulario_mensaje_delivery_not_retryable', __( 'This delivery cannot be retried.', 'formulario-mensaje' ), 404 );
		}

		$stored = $this->submissions->find( $delivery->submission_id );
		$form   = $this->forms->find( $delivery->form_id );

		if ( null === $stored || null === $form ) {
			return $this->error( 'formulario_mensaje_delivery_not_retryable', __( 'The submission or its form no longer exists.', 'formulario-mensaje' ), 404 );
		}

		$current = null;

		foreach ( $form->definition->actions as $action ) {
			if ( $action['id'] === $delivery->action_id && $action['type'] === $delivery->action_type ) {
				$current = $action;
				break;
			}
		}

		$type = $this->action_types->get( $delivery->action_type );

		if ( null === $current || null === $type ) {
			return $this->error( 'formulario_mensaje_action_not_found', __( 'The form no longer has this action.', 'formulario-mensaje' ), 409 );
		}

		// Quien desactivó la acción decidió que dejara de ejecutarse: reintentar no puede saltárselo.
		if ( ! $current['enabled'] ) {
			return $this->error( 'formulario_mensaje_action_disabled', __( 'This action is turned off in the form. Turn it on to retry.', 'formulario-mensaje' ), 409 );
		}

		$settings = $current['settings'];

		$outcome = $type->execute( $settings, $stored->to_submission(), $form );

		$this->deliveries->update(
			$delivery->id,
			true === $outcome ? Delivery_Status::Success : Delivery_Status::Failed,
			is_wp_error( $outcome ) ? $outcome->get_error_code() . ': ' . $outcome->get_error_message() : '',
			$delivery->attempts + 1,
			$this->clock->now()
		);
		$this->failures->refresh( $delivery->submission_id );

		return $this->deliveries->find( $delivery->id ) ?? $delivery;
	}

	/**
	 * Construye un error con su código de estado HTTP.
	 *
	 * @param string $code    Código del error.
	 * @param string $message Mensaje ya traducido.
	 * @param int    $status  Código de estado HTTP.
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
