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
 *
 * Si la tabla no responde (no existe, está dañada), el límite no se desactiva: se cuenta con un
 * transient. Es menos preciso ante envíos simultáneos, pero sigue limitando.
 */
final class Wpdb_Rate_Limiter implements Rate_Limiter {

	/**
	 * Prefijo de los transients del contador de respaldo.
	 */
	private const FALLBACK_PREFIX = 'formulario_mensaje_rl_';

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
	 * @return int Sucesos contados en la ventana; siempre al menos 1.
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

		$hits = false === $result
			? null
			: $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE bucket = %s', $this->schema->rate_limits_table(), $key ) );

		// Fallar aquí no puede equivaler a «sin límite»: se cuenta por la vía de respaldo.
		return null === $hits ? $this->fallback_hit( $key, max( 1, $window ), $now ) : max( 1, (int) $hits );
	}

	/**
	 * Cuenta un suceso con un transient, cuando la tabla no responde.
	 *
	 * @param string $key    Clave del contador.
	 * @param int    $window Duración de la ventana, en segundos.
	 * @param int    $now    Marca de tiempo actual.
	 * @return int Sucesos contados en la ventana.
	 */
	private function fallback_hit( string $key, int $window, int $now ): int {
		$state  = get_transient( self::FALLBACK_PREFIX . $key );
		$active = is_array( $state ) && isset( $state['count'], $state['expires'] ) && (int) $state['expires'] > $now;
		$count  = $active ? (int) $state['count'] + 1 : 1;
		$until  = $active ? (int) $state['expires'] : $now + $window;

		set_transient(
			self::FALLBACK_PREFIX . $key,
			array(
				'count'   => $count,
				'expires' => $until,
			),
			max( 1, $until - $now )
		);

		return $count;
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

		// Si el suceso se contó por la vía de respaldo, se descuenta también ahí.
		$state = get_transient( self::FALLBACK_PREFIX . $key );

		if ( is_array( $state ) && isset( $state['count'], $state['expires'] ) && (int) $state['count'] > 0 ) {
			$state['count'] = (int) $state['count'] - 1;

			set_transient( self::FALLBACK_PREFIX . $key, $state, max( 1, (int) $state['expires'] - $this->clock->now() ) );
		}
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
