<?php
/**
 * Servicio de gestión de envíos guardados.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

use Formulario_Mensaje\Support\Clock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones sobre los envíos guardados y su registro de entregas: listar, leer, borrar y descartar fallos.
 *
 * Los envíos contienen datos personales. Este servicio no comprueba permisos: lo hace cada adaptador.
 */
final class Submission_Service {

	/**
	 * Envíos que se borran en cada lote al vaciar un formulario.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param Submission_Repository $submissions Almacén de envíos.
	 * @param Delivery_Repository   $deliveries  Registro de entregas.
	 * @param Failure_Counter       $failures    Contador de entregas fallidas.
	 * @param Clock                 $clock       Reloj.
	 */
	public function __construct(
		private readonly Submission_Repository $submissions,
		private readonly Delivery_Repository $deliveries,
		private readonly Failure_Counter $failures,
		private readonly Clock $clock
	) {}

	/**
	 * Lista los envíos que cumplen unos filtros.
	 *
	 * @param Submission_Query $query Filtros y paginación.
	 * @return array{items: Stored_Submission[], total: int}
	 */
	public function search( Submission_Query $query ): array {
		return array(
			'items' => $this->submissions->search( $query ),
			'total' => $this->submissions->count( $query ),
		);
	}

	/**
	 * Busca un envío.
	 *
	 * @param int $id Identificador del envío.
	 */
	public function get( int $id ): ?Stored_Submission {
		return $this->submissions->find( $id );
	}

	/**
	 * Entregas de un envío.
	 *
	 * @param int $submission_id Identificador del envío.
	 * @return Delivery[]
	 */
	public function deliveries( int $submission_id ): array {
		return $this->deliveries->for_submission( $submission_id );
	}

	/**
	 * Entregas fallidas pendientes.
	 *
	 * @param int $page     Página, empezando en 1.
	 * @param int $per_page Entregas por página.
	 * @return array{items: Delivery[], total: int}
	 */
	public function failed_deliveries( int $page, int $per_page ): array {
		$per_page = max( 1, min( 100, $per_page ) );

		return array(
			'items' => $this->deliveries->failed( $per_page, ( max( 1, $page ) - 1 ) * $per_page ),
			'total' => $this->deliveries->count_failed(),
		);
	}

	/**
	 * Número de envíos guardados de cada formulario.
	 *
	 * @return array<int, int>
	 */
	public function counts_by_form(): array {
		return $this->submissions->counts_by_form();
	}

	/**
	 * Borra envíos, con su registro de entregas.
	 *
	 * @param int[] $ids Identificadores de los envíos.
	 * @return int Envíos borrados.
	 */
	public function delete( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$deleted = $this->submissions->delete( $ids );
		$this->deliveries->delete_for_submissions( $ids );
		$this->failures->refresh();

		/**
		 * Se dispara tras borrar envíos, para que quien guarde algo ligado a ellos lo borre también.
		 *
		 * @since 0.2.0
		 *
		 * @param int[] $ids Identificadores de los envíos borrados.
		 */
		do_action( 'formulario_mensaje_submissions_deleted', $ids );

		return $deleted;
	}

	/**
	 * Borra los envíos de un formulario anteriores a una fecha, por lotes.
	 *
	 * Sin tope de lotes borra todos, por muchos que sean: es lo que espera quien pide borrar los envíos
	 * de un formulario. La retención sí pone un tope, porque se repite a diario y puede seguir mañana.
	 *
	 * @param int    $form_id     Identificador del formulario.
	 * @param string $before_gmt  Fecha límite en GMT (formato MySQL); cadena vacía para todos.
	 * @param int    $max_batches Lotes como máximo; 0 para no parar hasta borrarlos todos.
	 * @return int Envíos borrados.
	 */
	public function delete_for_form( int $form_id, string $before_gmt = '', int $max_batches = 0 ): int {
		$deleted = 0;
		$batch   = 0;

		while ( 0 === $max_batches || $batch < $max_batches ) {
			$ids     = $this->submissions->ids_before( $form_id, $before_gmt, self::BATCH_SIZE );
			$removed = array() === $ids ? 0 : $this->delete( $ids );

			// Si un lote no borra nada, insistir sería un bucle sin fin.
			if ( 0 === $removed ) {
				break;
			}

			$deleted += $removed;
			++$batch;
		}

		return $deleted;
	}

	/**
	 * Descarta una entrega fallida: deja de contar como pendiente y ya no se reintenta.
	 *
	 * @param int $delivery_id Identificador de la entrega.
	 * @return true|WP_Error
	 */
	public function dismiss( int $delivery_id ): bool|WP_Error {
		$delivery = $this->deliveries->find( $delivery_id );

		if ( null === $delivery || Delivery_Status::Failed !== $delivery->status ) {
			return new WP_Error( 'formulario_mensaje_delivery_not_found', __( 'There is no failed delivery with that identifier.', 'formulario-mensaje' ), array( 'status' => 404 ) );
		}

		$this->deliveries->update( $delivery->id, Delivery_Status::Dismissed, $delivery->error, $delivery->attempts, $this->clock->now() );
		$this->failures->refresh( $delivery->submission_id );

		return true;
	}
}
