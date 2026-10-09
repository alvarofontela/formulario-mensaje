<?php
/**
 * Contador de entregas fallidas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Mantiene al día cuántas entregas fallidas hay pendientes, en total y por envío.
 *
 * El total se guarda en una opción para poder mostrarlo en el menú de administración sin consultar
 * la tabla en cada pantalla.
 */
final class Failure_Counter {

	/**
	 * Opción que guarda el total de entregas fallidas pendientes.
	 */
	public const OPTION = 'formulario_mensaje_failed_deliveries';

	/**
	 * Constructor.
	 *
	 * @param Delivery_Repository   $deliveries  Registro de entregas.
	 * @param Submission_Repository $submissions Almacén de envíos.
	 */
	public function __construct(
		private readonly Delivery_Repository $deliveries,
		private readonly Submission_Repository $submissions
	) {}

	/**
	 * Total de entregas fallidas pendientes, según el último recuento.
	 */
	public function total(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Vuelve a contar tras un cambio en el registro de entregas.
	 *
	 * @param int $submission_id Envío cuyas entregas han cambiado, o 0 si el cambio no es de un envío guardado.
	 */
	public function refresh( int $submission_id = 0 ): void {
		if ( $submission_id > 0 ) {
			$this->submissions->set_failed_actions( $submission_id, $this->deliveries->count_failed( $submission_id ) );
		}

		// Se carga con el resto de opciones: es un número y se lee en cada pantalla de administración.
		update_option( self::OPTION, $this->deliveries->count_failed(), true );
	}
}
