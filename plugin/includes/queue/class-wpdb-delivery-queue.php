<?php
/**
 * Cola de entregas en la base de datos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Storage\Delivery;
use Formulario_Mensaje\Storage\Delivery_Repository;
use Formulario_Mensaje\Storage\Delivery_Status;
use wpdb;

defined( 'ABSPATH' ) || exit;

/*
 * Tabla propia, sin caché de objetos: cada consulta va a la base de datos y los nombres de tabla
 * salen del prefijo de WordPress por el marcador %i.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Cola de entregas sobre la tabla del registro de entregas.
 */
final class Wpdb_Delivery_Queue implements Delivery_Queue {

	/**
	 * Constructor.
	 *
	 * @param wpdb                $wpdb       Conexión de WordPress a la base de datos.
	 * @param Schema              $schema     Esquema de las tablas del plugin.
	 * @param Delivery_Repository $deliveries Registro de entregas.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly Schema $schema,
		private readonly Delivery_Repository $deliveries
	) {}

	/**
	 * Pone en cola una entrega.
	 *
	 * @param Delivery $delivery Entrega pendiente, con la fecha de su primer intento.
	 * @param string   $payload  Copia del envío en JSON, o cadena vacía.
	 * @return int Identificador asignado, o 0 si no se pudo guardar.
	 */
	public function push( Delivery $delivery, string $payload ): int {
		$wpdb = $this->wpdb;

		$inserted = $wpdb->insert(
			$this->schema->deliveries_table(),
			array(
				'submission_id'   => $delivery->submission_id,
				'form_id'         => $delivery->form_id,
				'action_id'       => $delivery->action_id,
				'action_type'     => $delivery->action_type,
				'status'          => Delivery_Status::Pending->value,
				'attempts'        => 0,
				'error'           => '',
				'created_at'      => gmdate( 'Y-m-d H:i:s', $delivery->created_at ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s', $delivery->updated_at ),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $delivery->next_attempt_at ),
				'payload'         => '' === $payload ? null : $payload,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Reserva la siguiente entrega a la que ya le toca.
	 *
	 * @param int $now          Marca de tiempo Unix actual.
	 * @param int $lock_seconds Segundos durante los que la entrega queda reservada.
	 */
	public function claim_next( int $now, int $lock_seconds ): ?Queue_Job {
		$wpdb  = $this->wpdb;
		$table = $this->schema->deliveries_table();
		$due   = gmdate( 'Y-m-d H:i:s', $now );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT 5',
				$table,
				Delivery_Status::Pending->value,
				$due
			)
		);

		foreach ( $ids as $id ) {
			/*
			 * La reserva es una sola sentencia que repite la condición: si otro proceso se ha adelantado,
			 * no cambia ninguna fila y se prueba con la siguiente.
			 */
			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET attempts = attempts + 1, next_attempt_at = %s, updated_at = %s WHERE id = %d AND status = %s AND next_attempt_at <= %s',
					$table,
					gmdate( 'Y-m-d H:i:s', $now + $lock_seconds ),
					$due,
					(int) $id,
					Delivery_Status::Pending->value,
					$due
				)
			);

			$delivery = 1 === $claimed ? $this->deliveries->find( (int) $id ) : null;

			if ( null !== $delivery ) {
				$payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d', $table, (int) $id ) );

				return new Queue_Job( $delivery, is_string( $payload ) ? $payload : '' );
			}
		}

		return null;
	}

	/**
	 * Deja una entrega pendiente de un nuevo intento.
	 *
	 * @param int    $id              Identificador de la entrega.
	 * @param string $error           Motivo del fallo del intento que acaba de hacerse.
	 * @param int    $next_attempt_at Marca de tiempo Unix del siguiente intento.
	 * @param int    $now             Marca de tiempo Unix actual.
	 */
	public function reschedule( int $id, string $error, int $next_attempt_at, int $now ): void {
		$wpdb = $this->wpdb;

		$wpdb->update(
			$this->schema->deliveries_table(),
			array(
				'error'           => $error,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next_attempt_at ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array(
				'id'     => $id,
				'status' => Delivery_Status::Pending->value,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Saca una entrega de la cola con su resultado definitivo y borra la copia del envío.
	 *
	 * @param int             $id     Identificador de la entrega.
	 * @param Delivery_Status $status Resultado.
	 * @param string          $error  Motivo del fallo, o cadena vacía.
	 * @param int             $now    Marca de tiempo Unix actual.
	 */
	public function finish( int $id, Delivery_Status $status, string $error, int $now ): void {
		$wpdb = $this->wpdb;

		$wpdb->update(
			$this->schema->deliveries_table(),
			array(
				'status'          => $status->value,
				'error'           => $error,
				'next_attempt_at' => null,
				'payload'         => null,
				'updated_at'      => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marca de tiempo Unix del intento pendiente más próximo, o 0 si la cola está vacía.
	 */
	public function next_due(): int {
		$wpdb = $this->wpdb;

		$next = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(next_attempt_at) FROM %i WHERE status = %s', $this->schema->deliveries_table(), Delivery_Status::Pending->value )
		);

		return is_string( $next ) && '' !== $next ? (int) strtotime( $next . ' UTC' ) : 0;
	}
}
