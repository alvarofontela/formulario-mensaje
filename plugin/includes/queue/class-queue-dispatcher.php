<?php
/**
 * Entrada de acciones en la cola.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Actions\Action_Result;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Storage\Delivery;
use Formulario_Mensaje\Storage\Delivery_Status;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Pone en cola las acciones de un envío que se ejecutan en segundo plano y pide que se procesen.
 */
final class Queue_Dispatcher {

	/**
	 * Constructor.
	 *
	 * @param Delivery_Queue $queue    Cola de entregas.
	 * @param Queue_Schedule $schedule Programación de la cola.
	 * @param Clock          $clock    Reloj.
	 */
	public function __construct(
		private readonly Delivery_Queue $queue,
		private readonly Queue_Schedule $schedule,
		private readonly Clock $clock
	) {}

	/**
	 * Pone en cola las acciones aplazadas de un envío.
	 *
	 * @param Form            $form          Formulario.
	 * @param Submission      $submission    Envío ya validado.
	 * @param int             $submission_id Identificador del envío guardado, o 0 si el formulario no los guarda.
	 * @param Action_Result[] $results       Resultado de cada acción del envío.
	 * @return int Acciones que han entrado en la cola.
	 */
	public function dispatch( Form $form, Submission $submission, int $submission_id, array $results ): int {
		$now    = $this->clock->now();
		$queued = 0;

		/*
		 * Si el envío no se guarda, la cola lleva su propia copia hasta que la entrega termina, bien o
		 * mal: es lo mínimo para poder reintentar. Se borra al salir de la cola.
		 */
		$payload = 0 === $submission_id ? (string) wp_json_encode( $submission->to_array() ) : '';

		foreach ( $results as $result ) {
			if ( ! $result->deferred ) {
				continue;
			}

			$id = $this->queue->push(
				new Delivery( 0, $submission_id, $form->id, $result->action_id, $result->type, Delivery_Status::Pending, 0, '', $now, $now, $now ),
				$payload
			);

			if ( $id > 0 ) {
				++$queued;
			}
		}

		if ( $queued > 0 ) {
			$this->schedule->kick();
		}

		return $queued;
	}
}
