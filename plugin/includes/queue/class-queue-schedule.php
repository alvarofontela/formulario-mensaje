<?php
/**
 * Programación de la cola.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Programa en WP-Cron el procesado de la cola.
 *
 * Hay como mucho un evento programado: el del intento pendiente más próximo.
 */
final class Queue_Schedule {

	/**
	 * Acción que procesa la cola.
	 */
	public const HOOK = 'formulario_mensaje_process_queue';

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Reloj.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Se asegura de que la cola se procese, como tarde, en el momento indicado.
	 *
	 * @param int $timestamp Marca de tiempo Unix.
	 */
	public function ensure( int $timestamp ): void {
		$next = wp_next_scheduled( self::HOOK );

		if ( false !== $next && $next <= $timestamp ) {
			return;
		}

		// WordPress descarta un evento igual a otro a menos de diez minutos: el más tardío se retira antes.
		if ( false !== $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}

		wp_schedule_single_event( $timestamp, self::HOOK );
	}

	/**
	 * Pide que la cola se procese cuanto antes: al terminar esta petición, sin hacer esperar a quien la hizo.
	 */
	public function kick(): void {
		$this->ensure( $this->clock->now() );

		// Con WP-Cron desactivado, la cola la procesa el cron del sistema en su siguiente pasada.
		if ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || false !== has_action( 'shutdown', array( $this, 'spawn' ) ) ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'spawn' ) );
	}

	/**
	 * Lanza WP-Cron en una petición aparte, que no bloquea a la actual.
	 */
	public function spawn(): void {
		spawn_cron();
	}

	/**
	 * Retira el evento programado.
	 */
	public function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
