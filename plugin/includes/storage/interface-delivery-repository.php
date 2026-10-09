<?php
/**
 * Contrato del registro de entregas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda y lee el resultado de cada acción ejecutada sobre cada envío.
 */
interface Delivery_Repository {

	/**
	 * Guarda una entrega.
	 *
	 * @param Delivery $delivery Entrega sin identificador.
	 * @return int Identificador asignado, o 0 si no se pudo guardar.
	 */
	public function insert( Delivery $delivery ): int;

	/**
	 * Busca una entrega por su identificador.
	 *
	 * @param int $id Identificador de la entrega.
	 */
	public function find( int $id ): ?Delivery;

	/**
	 * Entregas de un envío, de la más antigua a la más reciente.
	 *
	 * @param int $submission_id Identificador del envío.
	 * @return Delivery[]
	 */
	public function for_submission( int $submission_id ): array;

	/**
	 * Entregas fallidas pendientes, de la más reciente a la más antigua.
	 *
	 * @param int $limit  Máximo de entregas.
	 * @param int $offset Posición de la primera.
	 * @return Delivery[]
	 */
	public function failed( int $limit, int $offset ): array;

	/**
	 * Número de entregas fallidas pendientes; con un envío, solo las suyas.
	 *
	 * @param int $submission_id Identificador del envío, o 0 para todas.
	 */
	public function count_failed( int $submission_id = 0 ): int;

	/**
	 * Actualiza el resultado de una entrega tras un nuevo intento o al descartarla.
	 *
	 * @param int             $id         Identificador de la entrega.
	 * @param Delivery_Status $status     Resultado.
	 * @param string          $error      Motivo del fallo, o cadena vacía.
	 * @param int             $attempts   Veces que se ha ejecutado la acción.
	 * @param int             $updated_at Marca de tiempo Unix del cambio.
	 */
	public function update( int $id, Delivery_Status $status, string $error, int $attempts, int $updated_at ): void;

	/**
	 * Borra las entregas de unos envíos.
	 *
	 * @param int[] $submission_ids Identificadores de los envíos.
	 */
	public function delete_for_submissions( array $submission_ids ): void;

	/**
	 * Borra las entregas terminadas (completadas, fallidas o descartadas) cuyo último cambio es
	 * anterior a una fecha. Las que están en la cola no se tocan.
	 *
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL).
	 * @return int Entregas borradas.
	 */
	public function delete_finished_before( string $before_gmt ): int;
}
