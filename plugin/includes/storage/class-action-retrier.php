<?php
/**
 * Reintento de acciones fallidas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Vuelve a ejecutar sobre un envío guardado la acción que falló, cuando alguien lo pide.
 *
 * El reintento se hace en el momento, también para las acciones que normalmente van por la cola:
 * quien lo pulsa quiere ver el resultado.
 */
final class Action_Retrier {

	/**
	 * Constructor.
	 *
	 * @param Delivery_Repository   $deliveries  Registro de entregas.
	 * @param Submission_Repository $submissions Almacén de envíos.
	 * @param Delivery_Executor     $executor    Ejecución de la acción de una entrega.
	 * @param Failure_Counter       $failures    Contador de entregas fallidas.
	 * @param Clock                 $clock       Reloj.
	 */
	public function __construct(
		private readonly Delivery_Repository $deliveries,
		private readonly Submission_Repository $submissions,
		private readonly Delivery_Executor $executor,
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
			return new WP_Error( 'formulario_mensaje_delivery_not_retryable', __( 'This delivery cannot be retried.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		$stored = $this->submissions->find( $delivery->submission_id );

		if ( null === $stored ) {
			return new WP_Error( 'formulario_mensaje_delivery_not_retryable', __( 'The submission or its form no longer exists.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		$outcome = $this->executor->execute( $delivery, $stored->to_submission() );

		// Falta el formulario o la acción, o está desactivada: no se ha intentado nada y la entrega queda como estaba.
		if ( is_wp_error( $outcome ) && $this->executor->is_permanent( $outcome ) ) {
			return $outcome;
		}

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
}
