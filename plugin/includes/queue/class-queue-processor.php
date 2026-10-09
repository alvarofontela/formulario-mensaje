<?php
/**
 * Procesado de la cola.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Storage\Delivery_Executor;
use Formulario_Mensaje\Storage\Delivery_Repository;
use Formulario_Mensaje\Storage\Delivery_Status;
use Formulario_Mensaje\Storage\Failure_Counter;
use Formulario_Mensaje\Storage\Submission_Repository;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta las entregas de la cola a las que ya les toca y reprograma las que fallan.
 *
 * Se puede lanzar tarde, o dos veces a la vez, sin daño: cada entrega se reserva antes de
 * ejecutarla, y la que se queda a medias vuelve a la cola cuando vence su reserva.
 */
final class Queue_Processor {

	/**
	 * Entregas que se procesan, como mucho, en una pasada.
	 */
	private const BATCH = 20;

	/**
	 * Segundos tras los que una pasada deja de coger entregas nuevas.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Segundos que una entrega queda reservada mientras se ejecuta.
	 */
	private const LOCK = 5 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Delivery_Queue        $queue       Cola de entregas.
	 * @param Delivery_Executor     $executor    Ejecución de la acción de una entrega.
	 * @param Delivery_Repository   $deliveries  Registro de entregas.
	 * @param Submission_Repository $submissions Almacén de envíos.
	 * @param Retry_Policy          $policy      Política de reintentos.
	 * @param Queue_Schedule        $schedule    Programación de la cola.
	 * @param Failure_Counter       $failures    Contador de entregas fallidas.
	 * @param Clock                 $clock       Reloj.
	 */
	public function __construct(
		private readonly Delivery_Queue $queue,
		private readonly Delivery_Executor $executor,
		private readonly Delivery_Repository $deliveries,
		private readonly Submission_Repository $submissions,
		private readonly Retry_Policy $policy,
		private readonly Queue_Schedule $schedule,
		private readonly Failure_Counter $failures,
		private readonly Clock $clock
	) {}

	/**
	 * Procesa las entregas pendientes a las que ya les toca.
	 *
	 * @return int Entregas procesadas en esta pasada.
	 */
	public function process(): int {
		$started   = $this->clock->now();
		$processed = 0;

		while ( $processed < self::BATCH && $this->clock->now() - $started < self::TIME_BUDGET ) {
			$job = $this->queue->claim_next( $this->clock->now(), self::LOCK );

			if ( null === $job ) {
				break;
			}

			$this->run( $job );
			++$processed;
		}

		// Queda programado el siguiente intento pendiente, sea de una entrega aplazada o de las que no han cabido.
		$next = $this->queue->next_due();

		if ( $next > 0 ) {
			$this->schedule->ensure( max( $next, $this->clock->now() ) );
		}

		return $processed;
	}

	/**
	 * Ejecuta una entrega reservada y anota su resultado.
	 *
	 * @param Queue_Job $job Entrega reservada.
	 */
	private function run( Queue_Job $job ): void {
		$delivery   = $job->delivery;
		$submission = $this->submission( $job );

		if ( null === $submission ) {
			$this->fail( $job, 'formulario_mensaje_submission_missing: ' . __( 'The submission or its form no longer exists.', 'formulario-mensaje' ) );

			return;
		}

		$outcome = $this->executor->execute( $delivery, $submission );

		if ( true === $outcome ) {
			$this->queue->finish( $delivery->id, Delivery_Status::Success, '', $this->clock->now() );

			return;
		}

		$error = is_wp_error( $outcome ) ? $outcome->get_error_code() . ': ' . $outcome->get_error_message() : '';
		$delay = is_wp_error( $outcome ) && $this->executor->is_permanent( $outcome ) ? null : $this->policy->delay_after( $delivery->attempts );

		if ( null === $delay ) {
			$this->fail( $job, $error );

			return;
		}

		$this->queue->reschedule( $delivery->id, $error, $this->clock->now() + $delay, $this->clock->now() );
	}

	/**
	 * Envío sobre el que se ejecuta una entrega: el guardado o, si el formulario no los guarda, la copia de la cola.
	 *
	 * @param Queue_Job $job Entrega reservada.
	 */
	private function submission( Queue_Job $job ): ?Submission {
		if ( $job->delivery->submission_id > 0 ) {
			return $this->submissions->find( $job->delivery->submission_id )?->to_submission();
		}

		return '' === $job->payload ? null : Submission::from_array( json_decode( $job->payload, true ) );
	}

	/**
	 * Da una entrega por fallida: ya no se reintenta sola.
	 *
	 * @param Queue_Job $job   Entrega reservada.
	 * @param string    $error Motivo del último fallo.
	 */
	private function fail( Queue_Job $job, string $error ): void {
		$this->queue->finish( $job->delivery->id, Delivery_Status::Failed, $error, $this->clock->now() );
		$this->failures->refresh( $job->delivery->submission_id );

		$failed = $this->deliveries->find( $job->delivery->id );

		if ( null !== $failed ) {
			/** This action is documented in includes/storage/class-submission-journal.php */
			do_action( 'formulario_mensaje_delivery_failed', $failed );
		}
	}
}
