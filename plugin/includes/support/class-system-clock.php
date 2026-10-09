<?php
/**
 * Reloj del sistema.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reloj que devuelve la hora del servidor.
 */
final class System_Clock implements Clock {

	/**
	 * Marca de tiempo Unix actual.
	 */
	public function now(): int {
		return time();
	}
}
