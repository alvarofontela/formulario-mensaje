<?php
/**
 * Limitador de frecuencia sobre transients.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Contadores guardados en transients: en la caché de objetos si el sitio la tiene, o en la base de datos.
 *
 * El incremento no es atómico: dos envíos simultáneos pueden contar como uno. Para un límite contra
 * el abuso es una imprecisión aceptable.
 */
final class Transient_Rate_Limiter implements Rate_Limiter {

	/**
	 * Prefijo de los transients.
	 */
	private const PREFIX = 'formulario_mensaje_rl_';

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Reloj.
	 */
	public function __construct( private readonly Clock $clock ) {}

	/**
	 * Sucesos contados para una clave en la ventana en curso.
	 *
	 * @param string $key Clave del contador.
	 */
	public function count( string $key ): int {
		return $this->read( $key )['count'];
	}

	/**
	 * Cuenta un suceso más para una clave.
	 *
	 * @param string $key    Clave del contador.
	 * @param int    $window Duración de la ventana, en segundos, si con este suceso empieza una nueva.
	 */
	public function hit( string $key, int $window ): void {
		$state = $this->read( $key );
		$now   = $this->clock->now();

		if ( 0 === $state['count'] ) {
			$state['expires'] = $now + max( 1, $window );
		}

		++$state['count'];

		set_transient( self::PREFIX . $key, $state, max( 1, $state['expires'] - $now ) );
	}

	/**
	 * Estado de un contador; a cero si no existe o su ventana ha terminado.
	 *
	 * @param string $key Clave del contador.
	 * @return array{count: int, expires: int}
	 */
	private function read( string $key ): array {
		$state = get_transient( self::PREFIX . $key );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['expires'] ) || (int) $state['expires'] <= $this->clock->now() ) {
			return array(
				'count'   => 0,
				'expires' => 0,
			);
		}

		return array(
			'count'   => (int) $state['count'],
			'expires' => (int) $state['expires'],
		);
	}
}
