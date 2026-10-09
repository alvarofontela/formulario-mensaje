<?php
/**
 * Retención de datos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Forms\Form_Repository;
use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Borra los datos que han superado su periodo de retención: los envíos, según lo que diga su
 * formulario, y el registro de entregas, según los ajustes del plugin.
 *
 * Se ejecuta a diario y se puede lanzar a mano. Hacerlo tarde o dos veces no causa daño: borra lo
 * que en ese momento esté caducado.
 */
final class Retention_Service {

	/**
	 * Lotes de envíos que se borran, como mucho, por formulario en cada pasada.
	 */
	private const MAX_BATCHES = 25;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository       $forms       Almacén de formularios.
	 * @param Submission_Service    $submissions Servicio de envíos guardados.
	 * @param Submission_Repository $stored      Almacén de envíos.
	 * @param Delivery_Repository   $deliveries  Registro de entregas.
	 * @param Failure_Counter       $failures    Contador de entregas fallidas.
	 * @param Settings_Repository   $settings    Ajustes del plugin.
	 * @param Clock                 $clock       Reloj.
	 */
	public function __construct(
		private readonly Form_Repository $forms,
		private readonly Submission_Service $submissions,
		private readonly Submission_Repository $stored,
		private readonly Delivery_Repository $deliveries,
		private readonly Failure_Counter $failures,
		private readonly Settings_Repository $settings,
		private readonly Clock $clock
	) {}

	/**
	 * Borra los envíos y las entregas caducados.
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

		// Las entregas en cola no se tocan: todavía tienen trabajo pendiente.
		$log_days = $this->settings->get()->delivery_log_days;

		if ( $this->deliveries->delete_finished_before( gmdate( 'Y-m-d H:i:s', $now - $log_days * DAY_IN_SECONDS ) ) > 0 ) {
			// Entre lo borrado puede haber fallos que nadie llegó a revisar: los recuentos se rehacen.
			$this->stored->recount_failed_actions();
			$this->failures->refresh();
		}

		return $deleted;
	}
}
