<?php
/**
 * Registro de entregas en una tabla propia.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Database\Schema;
use wpdb;

defined( 'ABSPATH' ) || exit;

/*
 * Tabla propia: las consultas directas son la única vía y no hay caché de objetos que mantener.
 * Todas pasan por prepare(), con %i para el nombre de la tabla; ninguna interpola ni concatena datos.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Registro de entregas sobre `$wpdb`.
 */
final class Wpdb_Delivery_Repository implements Delivery_Repository {

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
	 * Guarda una entrega.
	 *
	 * @param Delivery $delivery Entrega sin identificador.
	 * @return int Identificador asignado, o 0 si no se pudo guardar.
	 */
	public function insert( Delivery $delivery ): int {
		$wpdb = $this->wpdb;

		$inserted = $wpdb->insert(
			$this->schema->deliveries_table(),
			array(
				'submission_id' => $delivery->submission_id,
				'form_id'       => $delivery->form_id,
				'action_id'     => $delivery->action_id,
				'action_type'   => $delivery->action_type,
				'status'        => $delivery->status->value,
				'attempts'      => $delivery->attempts,
				'error'         => $delivery->error,
				'created_at'    => gmdate( 'Y-m-d H:i:s', $delivery->created_at ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s', $delivery->updated_at ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Busca una entrega por su identificador.
	 *
	 * @param int $id Identificador de la entrega.
	 */
	public function find( int $id ): ?Delivery {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->schema->deliveries_table(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->to_delivery( $row ) : null;
	}

	/**
	 * Entregas de un envío, de la más antigua a la más reciente.
	 *
	 * @param int $submission_id Identificador del envío.
	 * @return Delivery[]
	 */
	public function for_submission( int $submission_id ): array {
		$wpdb = $this->wpdb;

		if ( $submission_id <= 0 ) {
			return array();
		}

		return $this->to_deliveries(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE submission_id = %d ORDER BY id ASC', $this->schema->deliveries_table(), $submission_id ),
				ARRAY_A
			)
		);
	}

	/**
	 * Entregas fallidas pendientes, de la más reciente a la más antigua.
	 *
	 * @param int $limit  Máximo de entregas.
	 * @param int $offset Posición de la primera.
	 * @return Delivery[]
	 */
	public function failed( int $limit, int $offset ): array {
		$wpdb = $this->wpdb;

		return $this->to_deliveries(
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$this->schema->deliveries_table(),
					Delivery_Status::Failed->value,
					max( 1, $limit ),
					max( 0, $offset )
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Número de entregas fallidas pendientes; con un envío, solo las suyas.
	 *
	 * @param int $submission_id Identificador del envío, o 0 para todas.
	 */
	public function count_failed( int $submission_id = 0 ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND ( %d = 0 OR submission_id = %d )',
				$this->schema->deliveries_table(),
				Delivery_Status::Failed->value,
				$submission_id,
				$submission_id
			)
		);
	}

	/**
	 * Actualiza el resultado de una entrega tras un nuevo intento o al descartarla.
	 *
	 * @param int             $id         Identificador de la entrega.
	 * @param Delivery_Status $status     Resultado.
	 * @param string          $error      Motivo del fallo, o cadena vacía.
	 * @param int             $attempts   Veces que se ha ejecutado la acción.
	 * @param int             $updated_at Marca de tiempo Unix del cambio.
	 */
	public function update( int $id, Delivery_Status $status, string $error, int $attempts, int $updated_at ): void {
		$wpdb = $this->wpdb;

		$wpdb->update(
			$this->schema->deliveries_table(),
			array(
				'status'     => $status->value,
				'error'      => $error,
				'attempts'   => $attempts,
				'updated_at' => gmdate( 'Y-m-d H:i:s', $updated_at ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Borra las entregas de unos envíos.
	 *
	 * @param int[] $submission_ids Identificadores de los envíos.
	 */
	public function delete_for_submissions( array $submission_ids ): void {
		$wpdb = $this->wpdb;

		foreach ( array_unique( array_map( 'absint', $submission_ids ) ) as $submission_id ) {
			// El 0 agrupa las entregas sin envío guardado: no se borran por esta vía.
			if ( $submission_id > 0 ) {
				$wpdb->delete( $this->schema->deliveries_table(), array( 'submission_id' => $submission_id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Envíos guardados que tienen alguna entrega fallida cuyo último cambio es anterior a una fecha.
	 *
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL).
	 * @return int[] Identificadores de los envíos.
	 */
	public function submissions_failed_before( string $before_gmt ): array {
		$wpdb = $this->wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT submission_id FROM %i WHERE status = %s AND submission_id > 0 AND updated_at < %s',
				$this->schema->deliveries_table(),
				Delivery_Status::Failed->value,
				$before_gmt
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Borra las entregas terminadas cuyo último cambio es anterior a una fecha.
	 *
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL).
	 * @return int Entregas borradas.
	 */
	public function delete_finished_before( string $before_gmt ): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status <> %s AND updated_at < %s',
				$this->schema->deliveries_table(),
				Delivery_Status::Pending->value,
				$before_gmt
			)
		);
	}

	/**
	 * Convierte las filas de una consulta en entregas.
	 *
	 * @param mixed $rows Lo que devuelve get_results(): las filas, o null si la consulta ha fallado.
	 * @return Delivery[]
	 */
	private function to_deliveries( mixed $rows ): array {
		return array_map( array( $this, 'to_delivery' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Convierte una fila de la tabla en una entrega.
	 *
	 * @param array<string, mixed> $row Fila de la tabla.
	 */
	private function to_delivery( array $row ): Delivery {
		return new Delivery(
			(int) $row['id'],
			(int) $row['submission_id'],
			(int) $row['form_id'],
			(string) $row['action_id'],
			(string) $row['action_type'],
			Delivery_Status::tryFrom( (string) $row['status'] ) ?? Delivery_Status::Failed,
			(int) $row['attempts'],
			(string) $row['error'],
			(int) strtotime( $row['created_at'] . ' UTC' ),
			(int) strtotime( $row['updated_at'] . ' UTC' ),
			empty( $row['next_attempt_at'] ) ? 0 : (int) strtotime( $row['next_attempt_at'] . ' UTC' )
		);
	}
}
