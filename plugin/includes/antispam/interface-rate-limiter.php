<?php
/**
 * Contrato del limitador de frecuencia.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

defined( 'ABSPATH' ) || exit;

/**
 * Cuenta sucesos por clave dentro de una ventana de tiempo.
 *
 * Contar es una sola operación atómica: dos peticiones simultáneas nunca obtienen el mismo total, así
 * que un límite basado en él no se puede saltar lanzando envíos en paralelo.
 */
interface Rate_Limiter {

	/**
	 * Cuenta un suceso más para una clave y devuelve el total de la ventana en curso, incluido este.
	 *
	 * @param string $key    Clave del contador.
	 * @param int    $window Duración de la ventana, en segundos, si con este suceso empieza una nueva.
	 * @return int Sucesos contados en la ventana, al menos 1. Si el almacenamiento falla, la implementación
	 *             debe seguir contando por otra vía: devolver 0 dejaría el límite sin efecto.
	 */
	public function hit( string $key, int $window ): int;

	/**
	 * Descuenta un suceso contado antes, porque al final no llegó a producirse.
	 *
	 * @param string $key Clave del contador.
	 */
	public function release( string $key ): void;

	/**
	 * Borra los contadores cuya ventana ya ha terminado.
	 *
	 * @return int Contadores borrados.
	 */
	public function purge_expired(): int;
}
