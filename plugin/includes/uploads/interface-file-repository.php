<?php
/**
 * Contrato del registro de ficheros.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda y lee qué ficheros hay en el almacén y a qué envío pertenece cada uno.
 */
interface File_Repository {

	/**
	 * Anota un fichero guardado.
	 *
	 * @param Stored_File $file Fichero sin identificador.
	 * @return int Identificador asignado, o 0 si no se pudo anotar.
	 */
	public function insert( Stored_File $file ): int;

	/**
	 * Busca un fichero por su identificador aleatorio.
	 *
	 * @param string $token Identificador aleatorio del fichero.
	 */
	public function find_by_token( string $token ): ?Stored_File;

	/**
	 * Ficheros de un envío, por su número de referencia. Sirve también para los envíos que no se guardan.
	 *
	 * @param int    $form_id   Identificador del formulario.
	 * @param string $reference Número de referencia del envío.
	 * @return Stored_File[]
	 */
	public function for_reference( int $form_id, string $reference ): array;

	/**
	 * Ficheros de unos envíos guardados.
	 *
	 * @param int[] $submission_ids Identificadores de los envíos.
	 * @return Stored_File[]
	 */
	public function for_submissions( array $submission_ids ): array;

	/**
	 * Asocia los ficheros de un envío con el envío ya guardado.
	 *
	 * @param int    $form_id       Identificador del formulario.
	 * @param string $reference     Número de referencia del envío.
	 * @param int    $submission_id Identificador del envío guardado.
	 */
	public function link( int $form_id, string $reference, int $submission_id ): void;

	/**
	 * Ficheros sin envío guardado anteriores a una fecha: restos de envíos que no llegaron a completarse.
	 *
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL).
	 * @param int    $limit      Máximo de ficheros.
	 * @return Stored_File[]
	 */
	public function orphans_before( string $before_gmt, int $limit ): array;

	/**
	 * Borra los registros de unos ficheros.
	 *
	 * @param int[] $ids Identificadores de los ficheros.
	 */
	public function delete( array $ids ): void;
}
