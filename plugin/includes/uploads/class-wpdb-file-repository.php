<?php
/**
 * Registro de ficheros en la base de datos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Uploads;

use Formulario_Mensaje\Database\Schema;
use wpdb;

defined( 'ABSPATH' ) || exit;

/*
 * Tabla propia, sin caché de objetos: cada consulta va a la base de datos y los nombres de tabla
 * salen del prefijo de WordPress por el marcador %i.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Registro de ficheros sobre una tabla propia.
 */
final class Wpdb_File_Repository implements File_Repository {

	/**
	 * Constructor.
	 *
	 * @param wpdb   $wpdb   Conexión de WordPress a la base de datos.
	 * @param Schema $schema Esquema de las tablas del plugin.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly Schema $schema
	) {}

	/**
	 * Anota un fichero guardado.
	 *
	 * @param Stored_File $file Fichero sin identificador.
	 * @return int Identificador asignado, o 0 si no se pudo anotar.
	 */
	public function insert( Stored_File $file ): int {
		$wpdb = $this->wpdb;

		$inserted = $wpdb->insert(
			$this->schema->files_table(),
			array(
				'submission_id' => $file->submission_id,
				'form_id'       => $file->form_id,
				'field_id'      => $file->field_id,
				'reference'     => $file->reference,
				'token'         => $file->token,
				'name'          => $file->name,
				'mime'          => $file->mime,
				'size'          => $file->size,
				'path'          => $file->path,
				'created_at'    => gmdate( 'Y-m-d H:i:s', $file->created_at ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Busca un fichero por su identificador aleatorio.
	 *
	 * @param string $token Identificador aleatorio del fichero.
	 */
	public function find_by_token( string $token ): ?Stored_File {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', $this->schema->files_table(), $token ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->to_file( $row ) : null;
	}

	/**
	 * Ficheros de un envío, por su número de referencia.
	 *
	 * @param int    $form_id   Identificador del formulario.
	 * @param string $reference Número de referencia del envío.
	 * @return Stored_File[]
	 */
	public function for_reference( int $form_id, string $reference ): array {
		$wpdb = $this->wpdb;

		return $this->rows(
			$wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d AND reference = %s ORDER BY id ASC', $this->schema->files_table(), $form_id, $reference )
		);
	}

	/**
	 * Ficheros de unos envíos guardados.
	 *
	 * @param int[] $submission_ids Identificadores de los envíos.
	 * @return Stored_File[]
	 */
	public function for_submissions( array $submission_ids ): array {
		$wpdb  = $this->wpdb;
		$files = array();

		// De uno en uno: así la consulta es siempre el mismo texto, sin listas compuestas a mano.
		foreach ( array_unique( array_filter( array_map( 'absint', $submission_ids ) ) ) as $submission_id ) {
			$files = array_merge(
				$files,
				$this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE submission_id = %d ORDER BY id ASC', $this->schema->files_table(), $submission_id ) )
			);
		}

		return $files;
	}

	/**
	 * Asocia los ficheros de un envío con el envío ya guardado.
	 *
	 * @param int    $form_id       Identificador del formulario.
	 * @param string $reference     Número de referencia del envío.
	 * @param int    $submission_id Identificador del envío guardado.
	 */
	public function link( int $form_id, string $reference, int $submission_id ): void {
		$wpdb = $this->wpdb;

		$wpdb->update(
			$this->schema->files_table(),
			array( 'submission_id' => $submission_id ),
			array(
				'form_id'       => $form_id,
				'reference'     => $reference,
				'submission_id' => 0,
			),
			array( '%d' ),
			array( '%d', '%s', '%d' )
		);
	}

	/**
	 * Ficheros sin envío guardado anteriores a una fecha.
	 *
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL).
	 * @param int    $limit      Máximo de ficheros.
	 * @return Stored_File[]
	 */
	public function orphans_before( string $before_gmt, int $limit ): array {
		$wpdb = $this->wpdb;

		return $this->rows(
			$wpdb->prepare( 'SELECT * FROM %i WHERE submission_id = 0 AND created_at < %s ORDER BY id ASC LIMIT %d', $this->schema->files_table(), $before_gmt, max( 1, $limit ) )
		);
	}

	/**
	 * Borra los registros de unos ficheros.
	 *
	 * @param int[] $ids Identificadores de los ficheros.
	 */
	public function delete( array $ids ): void {
		$wpdb = $this->wpdb;

		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			$wpdb->delete( $this->schema->files_table(), array( 'id' => $id ), array( '%d' ) );
		}
	}

	/**
	 * Ejecuta una consulta ya preparada y convierte sus filas.
	 *
	 * @param string|null $prepared Consulta preparada con prepare().
	 * @return Stored_File[]
	 */
	private function rows( ?string $prepared ): array {
		$wpdb = $this->wpdb;

		if ( null === $prepared ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- La prepara quien llama, con prepare().
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		return array_map( array( $this, 'to_file' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Convierte una fila de la tabla en un fichero.
	 *
	 * @param array<string, mixed> $row Fila de la tabla.
	 */
	private function to_file( array $row ): Stored_File {
		return new Stored_File(
			(int) $row['id'],
			(int) $row['submission_id'],
			(int) $row['form_id'],
			(string) $row['field_id'],
			(string) $row['reference'],
			(string) $row['token'],
			(string) $row['name'],
			(string) $row['mime'],
			(int) $row['size'],
			(string) $row['path'],
			(int) strtotime( $row['created_at'] . ' UTC' )
		);
	}
}
