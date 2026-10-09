<?php
/**
 * Contrato del almacén de envíos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda y lee los envíos recibidos. Contienen datos personales: quien lo use decide quién puede verlos.
 */
interface Submission_Repository {

	/**
	 * Guarda un envío.
	 *
	 * @param Stored_Submission $submission Envío sin identificador.
	 * @return int Identificador asignado, o 0 si no se pudo guardar.
	 */
	public function insert( Stored_Submission $submission ): int;

	/**
	 * Busca un envío por su identificador.
	 *
	 * @param int $id Identificador del envío.
	 */
	public function find( int $id ): ?Stored_Submission;

	/**
	 * Lista los envíos que cumplen unos filtros, del más reciente al más antiguo.
	 *
	 * @param Submission_Query $query Filtros y paginación.
	 * @return Stored_Submission[]
	 */
	public function search( Submission_Query $query ): array;

	/**
	 * Cuenta los envíos que cumplen unos filtros, sin paginar.
	 *
	 * @param Submission_Query $query Filtros.
	 */
	public function count( Submission_Query $query ): int;

	/**
	 * Cuenta los envíos guardados de cada formulario.
	 *
	 * @return array<int, int> Número de envíos, por identificador de formulario.
	 */
	public function counts_by_form(): array;

	/**
	 * Identificadores de los envíos de un formulario anteriores a una fecha.
	 *
	 * @param int    $form_id    Identificador del formulario.
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL); cadena vacía para todos.
	 * @param int    $limit      Máximo de identificadores.
	 * @return int[]
	 */
	public function ids_before( int $form_id, string $before_gmt, int $limit ): array;

	/**
	 * Borra envíos.
	 *
	 * @param int[] $ids Identificadores de los envíos.
	 * @return int Envíos borrados.
	 */
	public function delete( array $ids ): int;

	/**
	 * Actualiza el número de entregas fallidas pendientes de un envío.
	 *
	 * @param int $id     Identificador del envío.
	 * @param int $failed Entregas fallidas pendientes.
	 */
	public function set_failed_actions( int $id, int $failed ): void;

	/**
	 * Recalcula, a partir del registro de entregas, las entregas fallidas pendientes de los envíos
	 * que tenían alguna. Hace falta cuando el registro se purga.
	 */
	public function recount_failed_actions(): void;
}
