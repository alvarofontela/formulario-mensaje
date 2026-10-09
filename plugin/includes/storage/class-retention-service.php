<?php
/**
 * Retención automática de envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Borra los envíos que han superado los días de retención que fija su formulario.
 *
 * Se ejecuta a diario. Si se retrasa o se repite no pasa nada: cada vez borra lo que toque en ese momento.
 */
final class Retention_Service {

	/**
	 * Días que se conservan los fallos de entrega de formularios que no guardan sus envíos.
	 */
	private const ORPHAN_DELIVERY_DAYS = 30;

	/**
	 * Lotes de borrado por formulario y ejecución, para acotar lo que tarda.
	 */
	private const MAX_BATCHES = 25;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository     $forms       Almacén de formularios.
	 * @param Submission_Service  $submissions Servicio de envíos guardados.
	 * @param Delivery_Repository $deliveries  Registro de entregas.
	 * @param Failure_Counter     $failures    Contador de entregas fallidas.
	 * @param Clock               $clock       Reloj.
	 */
	public function __construct(
		private readonly Form_Repository $forms,
		private readonly Submission_Service $submissions,
		private readonly Delivery_Repository $deliveries,
		private readonly Failure_Counter $failures,
		private readonly Clock $clock
	) {}

	/**
	 * Aplica la retención de todos los formularios.
	 *
	 * @return int Envíos borrados.
	 */
	public function purge(): int {
		$now     = $this->clock->now();
		$deleted = 0;

		// Un formulario en la papelera conserva sus envíos, y con ellos su retención.
		foreach ( array( Form::STATUS_PUBLISHED, Form::STATUS_TRASHED ) as $status ) {
			foreach ( $this->forms->all( $status ) as $form ) {
				$days = (int) $form->definition->setting( 'retention_days', 0 );

				if ( $days > 0 ) {
					$deleted += $this->submissions->delete_for_form( $form->id, gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ), self::MAX_BATCHES );
				}
			}
		}

		if ( $this->deliveries->delete_orphans_before( gmdate( 'Y-m-d H:i:s', $now - self::ORPHAN_DELIVERY_DAYS * DAY_IN_SECONDS ) ) > 0 ) {
			$this->failures->refresh();
		}

		return $deleted;
	}
}
