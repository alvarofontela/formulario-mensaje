<?php
/**
 * Envíos guardados en una tabla propia.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Submissions\Submission_Entry;
use wpdb;

defined( 'ABSPATH' ) || exit;

/*
 * Tabla propia: las consultas directas son la única vía y no hay caché de objetos que mantener.
 * Todas pasan por prepare(), con %i para el nombre de la tabla; ninguna interpola ni concatena datos.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Almacén de envíos sobre `$wpdb`.
 */
final class Wpdb_Submission_Repository implements Submission_Repository {

	/**
	 * Filtros de un listado, escritos una sola vez: cada condición se anula con su primer parámetro.
	 *
	 * Así la consulta es siempre el mismo texto y nada se compone a partir de la petición.
	 */
	private const FILTERS = '( %d = 0 OR form_id = %d )
		AND ( %d = 0 OR failed_actions > 0 )
		AND ( %s = \'\' OR search_text LIKE %s )
		AND ( %s = \'\' OR created_at >= %s )
		AND ( %s = \'\' OR created_at <= %s )';

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
	 * Guarda un envío.
	 *
	 * @param Stored_Submission $submission Envío sin identificador.
	 * @return int Identificador asignado, o 0 si no se pudo guardar.
	 */
	public function insert( Stored_Submission $submission ): int {
		$wpdb = $this->wpdb;

		$inserted = $wpdb->insert(
			$this->schema->submissions_table(),
			array(
				'form_id'        => $submission->form_id,
				'reference'      => $submission->reference,
				'created_at'     => gmdate( 'Y-m-d H:i:s', $submission->created_at ),
				'page_url'       => $submission->page_url,
				'ip'             => $submission->ip,
				'user_agent'     => $submission->user_agent,
				'entries'        => (string) wp_json_encode( array_map( static fn ( Submission_Entry $entry ): array => $entry->to_array(), array_values( $submission->entries ) ) ),
				'search_text'    => $submission->search_text(),
				'failed_actions' => $submission->failed_actions,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Busca un envío por su identificador.
	 *
	 * @param int $id Identificador del envío.
	 */
	public function find( int $id ): ?Stored_Submission {
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->schema->submissions_table(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->to_submission( $row ) : null;
	}

	/**
	 * Lista los envíos que cumplen unos filtros, del más reciente al más antiguo.
	 *
	 * @param Submission_Query $query Filtros y paginación.
	 * @return Stored_Submission[]
	 */
	public function search( Submission_Query $query ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Solo se concatena una constante de la clase; los datos van por los marcadores.
		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE ' . self::FILTERS . ' ORDER BY id DESC LIMIT %d OFFSET %d', ...array( ...$this->filter_args( $query ), $query->per_page, $query->offset() ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparada en la línea anterior.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'to_submission' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Cuenta los envíos que cumplen unos filtros, sin paginar.
	 *
	 * @param Submission_Query $query Filtros.
	 */
	public function count( Submission_Query $query ): int {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Solo se concatena una constante de la clase; los datos van por los marcadores.
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . self::FILTERS, ...$this->filter_args( $query ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Preparada en la línea anterior.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Cuenta los envíos guardados de cada formulario.
	 *
	 * @return array<int, int> Número de envíos, por identificador de formulario.
	 */
	public function counts_by_form(): array {
		$wpdb = $this->wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT form_id, COUNT(*) AS total FROM %i GROUP BY form_id', $this->schema->submissions_table() ),
			ARRAY_A
		);

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row['form_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Identificadores de los envíos de un formulario anteriores a una fecha.
	 *
	 * @param int    $form_id    Identificador del formulario.
	 * @param string $before_gmt Fecha límite en GMT (formato MySQL); cadena vacía para todos.
	 * @param int    $limit      Máximo de identificadores.
	 * @return int[]
	 */
	public function ids_before( int $form_id, string $before_gmt, int $limit ): array {
		$wpdb = $this->wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE form_id = %d AND ( %s = \'\' OR created_at < %s ) ORDER BY id ASC LIMIT %d',
				$this->schema->submissions_table(),
				$form_id,
				$before_gmt,
				$before_gmt,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Borra envíos.
	 *
	 * @param int[] $ids Identificadores de los envíos.
	 * @return int Envíos borrados.
	 */
	public function delete( array $ids ): int {
		$wpdb = $this->wpdb;

		$deleted = 0;

		// Uno a uno: los lotes son pequeños y así no hay que componer una lista dentro del SQL.
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$deleted += (int) $wpdb->delete( $this->schema->submissions_table(), array( 'id' => $id ), array( '%d' ) );
		}

		return $deleted;
	}

	/**
	 * Actualiza el número de entregas fallidas pendientes de un envío.
	 *
	 * @param int $id     Identificador del envío.
	 * @param int $failed Entregas fallidas pendientes.
	 */
	public function set_failed_actions( int $id, int $failed ): void {
		$wpdb = $this->wpdb;

		$wpdb->update( $this->schema->submissions_table(), array( 'failed_actions' => max( 0, $failed ) ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Parámetros de la consulta que corresponden, en orden, a los marcadores de la tabla y de FILTERS.
	 *
	 * @param Submission_Query $query Filtros.
	 * @return array<int, string|int>
	 */
	private function filter_args( Submission_Query $query ): array {
		$wpdb = $this->wpdb;

		$like = '' === $query->search ? '' : '%' . $wpdb->esc_like( $query->search ) . '%';

		return array(
			$this->schema->submissions_table(),
			$query->form_id,
			$query->form_id,
			$query->only_failed ? 1 : 0,
			$like,
			$like,
			$query->from_gmt,
			$query->from_gmt,
			$query->to_gmt,
			$query->to_gmt,
		);
	}

	/**
	 * Convierte una fila de la tabla en un envío.
	 *
	 * @param array<string, mixed> $row Fila de la tabla.
	 */
	private function to_submission( array $row ): Stored_Submission {
		$decoded = json_decode( (string) $row['entries'], true );
		$entries = array();

		foreach ( is_array( $decoded ) ? $decoded : array() as $item ) {
			$entry = Submission_Entry::from_array( $item );

			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return new Stored_Submission(
			(int) $row['id'],
			(int) $row['form_id'],
			(string) $row['reference'],
			(int) strtotime( $row['created_at'] . ' UTC' ),
			(string) $row['page_url'],
			(string) $row['ip'],
			(string) $row['user_agent'],
			$entries,
			(int) $row['failed_actions']
		);
	}

	/**
	 * Recalcula las entregas fallidas pendientes de los envíos que tenían alguna.
	 */
	public function recount_failed_actions(): void {
		$wpdb = $this->wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS s SET s.failed_actions = ( SELECT COUNT(*) FROM %i AS d WHERE d.submission_id = s.id AND d.status = %s ) WHERE s.failed_actions > 0',
				$this->schema->submissions_table(),
				$this->schema->deliveries_table(),
				'failed'
			)
		);
	}
}
