<?php
/**
 * Limitador de frecuencia sobre una tabla propia.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Support\Clock;
use wpdb;

defined( 'ABSPATH' ) || exit;

/*
 * Tabla propia: las consultas directas son la única vía, y cachearlas rompería el contador.
 * Todas pasan por prepare(), con %i para el nombre de la tabla.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Contadores en una tabla, incrementados con una única sentencia SQL.
 *
 * Un transient obligaría a leer, sumar y escribir en tres pasos, y dos peticiones simultáneas leerían
 * el mismo valor. Aquí la base de datos hace la suma, que es atómica en cualquier alojamiento.
 */
final class Wpdb_Rate_Limiter implements Rate_Limiter {

	/**
	 * Constructor.
	 *
	 * @param wpdb   $wpdb   Conexión de WordPress a la base de datos.
	 * @param Schema $schema Esquema de las tablas del plugin.
	 * @param Clock  $clock  Reloj.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly Schema $schema,
		private readonly Clock $clock
	) {}

	/**
	 * Cuenta un suceso más para una clave y devuelve el total de la ventana en curso, incluido este.
	 *
	 * @param string $key    Clave del contador.
	 * @param int    $window Duración de la ventana, en segundos, si con este suceso empieza una nueva.
	 * @return int Sucesos contados en la ventana; 0 si no se pudo contar.
	 */
	public function hit( string $key, int $window ): int {
		$wpdb = $this->wpdb;

		$now     = $this->clock->now();
		$current = gmdate( 'Y-m-d H:i:s', $now );
		$expires = gmdate( 'Y-m-d H:i:s', $now + max( 1, $window ) );

		/*
		 * Si la clave no existe se crea con un suceso. Si existe y su ventana terminó, se reinicia; si no,
		 * se suma uno. MySQL evalúa las asignaciones de izquierda a derecha: `hits` se calcula con el
		 * `expires_at` anterior, y después se actualiza `expires_at`.
		 */
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, hits, expires_at) VALUES (%s, 1, %s)
				ON DUPLICATE KEY UPDATE
					hits = IF( expires_at <= %s, 1, hits + 1 ),
					expires_at = IF( expires_at <= %s, %s, expires_at )',
				$this->schema->rate_limits_table(),
				$key,
				$expires,
				$current,
				$current,
				$expires
			)
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT hits FROM %i WHERE bucket = %s', $this->schema->rate_limits_table(), $key )
		);
	}

	/**
	 * Descuenta un suceso contado antes, porque al final no llegó a producirse.
	 *
	 * @param string $key Clave del contador.
	 */
	public function release( string $key ): void {
		$wpdb = $this->wpdb;

		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET hits = hits - 1 WHERE bucket = %s AND hits > 0', $this->schema->rate_limits_table(), $key )
		);
	}

	/**
	 * Borra los contadores cuya ventana ya ha terminado.
	 *
	 * @return int Contadores borrados.
	 */
	public function purge_expired(): int {
		$wpdb = $this->wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', $this->schema->rate_limits_table(), gmdate( 'Y-m-d H:i:s', $this->clock->now() ) )
		);
	}
}
