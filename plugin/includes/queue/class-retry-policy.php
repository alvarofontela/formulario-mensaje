<?php
/**
 * Política de reintentos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Decide cuánto se espera antes de reintentar una entrega de la cola y cuándo se deja de intentar.
 */
final class Retry_Policy {

	/**
	 * Esperas, en segundos, tras cada intento fallido: 1 minuto, 5 minutos, 30 minutos, 2 horas y 8 horas.
	 */
	private const DELAYS = array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 8 * HOUR_IN_SECONDS );

	/**
	 * Tope de reintentos, por mucho que amplíe el filtro: la cola no es un sitio donde dejar cosas para siempre.
	 */
	private const MAX_RETRIES = 20;

	/**
	 * Espera antes del siguiente intento.
	 *
	 * @param int $attempts Intentos hechos hasta ahora, todos fallidos.
	 * @return int|null Segundos de espera, o null si ya no se reintenta más.
	 */
	public function delay_after( int $attempts ): ?int {
		/**
		 * Filtra las esperas entre los reintentos de una acción en segundo plano.
		 *
		 * Cada elemento es la espera, en segundos, tras un intento fallido; el número de elementos es
		 * el número de reintentos. Un array vacío desactiva los reintentos.
		 *
		 * @since 0.4.0
		 *
		 * @param int[] $delays Esperas en segundos.
		 */
		$delays = apply_filters( 'formulario_mensaje_retry_delays', self::DELAYS );
		$delays = is_array( $delays ) ? array_slice( array_values( array_filter( array_map( 'absint', $delays ) ) ), 0, self::MAX_RETRIES ) : self::DELAYS;

		return $delays[ $attempts - 1 ] ?? null;
	}
}
