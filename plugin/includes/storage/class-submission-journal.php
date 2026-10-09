<?php
/**
 * Registro de un envío y del resultado de sus acciones.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Actions\Action_Result;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda cada envío, si el formulario lo pide, y anota cómo fue cada una de sus acciones.
 *
 * Qué metadatos se guardan lo decide cada formulario: la fecha, el formulario y la página, siempre;
 * la IP (completa o anonimizada) y el navegador, solo si están activados.
 */
final class Submission_Journal {

	public const IP_NONE       = 'none';
	public const IP_ANONYMIZED = 'anonymized';
	public const IP_FULL       = 'full';

	/**
	 * Constructor.
	 *
	 * @param Submission_Repository $submissions   Almacén de envíos.
	 * @param Delivery_Repository   $deliveries    Registro de entregas.
	 * @param Ip_Anonymizer         $ip_anonymizer Anonimizado de IP.
	 * @param Failure_Counter       $failures      Contador de entregas fallidas.
	 * @param Clock                 $clock         Reloj.
	 */
	public function __construct(
		private readonly Submission_Repository $submissions,
		private readonly Delivery_Repository $deliveries,
		private readonly Ip_Anonymizer $ip_anonymizer,
		private readonly Failure_Counter $failures,
		private readonly Clock $clock
	) {}

	/**
	 * Indica si un formulario guarda sus envíos. Lo hace salvo que se haya desactivado expresamente.
	 *
	 * @param Form $form Formulario.
	 */
	public function stores( Form $form ): bool {
		return ! empty( $form->definition->setting( 'store_enabled', true ) );
	}

	/**
	 * Guarda un envío, si el formulario lo pide.
	 *
	 * @param Form       $form       Formulario.
	 * @param Submission $submission Envío ya validado.
	 * @return int Identificador del envío guardado, o 0 si no se guarda o no se pudo guardar.
	 */
	public function record_submission( Form $form, Submission $submission ): int {
		if ( ! $this->stores( $form ) ) {
			return 0;
		}

		$ip = match ( $form->definition->setting( 'store_ip', self::IP_NONE ) ) {
			self::IP_FULL       => $submission->client_ip,
			self::IP_ANONYMIZED => $this->ip_anonymizer->anonymize( $submission->client_ip ),
			default             => '',
		};

		return $this->submissions->insert(
			new Stored_Submission(
				0,
				$form->id,
				$submission->reference,
				$submission->created_at,
				$submission->page_url,
				$ip,
				empty( $form->definition->setting( 'store_user_agent' ) ) ? '' : $submission->user_agent,
				array_values( $submission->entries )
			)
		);
	}

	/**
	 * Anota el resultado de las acciones de un envío.
	 *
	 * De un envío guardado se anotan todas, para ver en su detalle qué se hizo con él. Si el
	 * formulario no guarda sus envíos, solo los fallos: sirven para avisar, aunque no se puedan reintentar.
	 *
	 * @param Form            $form          Formulario.
	 * @param int             $submission_id Identificador del envío guardado, o 0.
	 * @param Action_Result[] $results       Resultado de cada acción ejecutada.
	 */
	public function record_results( Form $form, int $submission_id, array $results ): void {
		$now    = $this->clock->now();
		$failed = false;

		foreach ( $results as $result ) {
			if ( $result->success && 0 === $submission_id ) {
				continue;
			}

			$failed = $failed || ! $result->success;

			$this->deliveries->insert(
				new Delivery(
					0,
					$submission_id,
					$form->id,
					$result->action_id,
					$result->type,
					$result->success ? Delivery_Status::Success : Delivery_Status::Failed,
					1,
					$result->error,
					$now,
					$now
				)
			);
		}

		if ( $failed ) {
			$this->failures->refresh( $submission_id );
		}
	}
}
