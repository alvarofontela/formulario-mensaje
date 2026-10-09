<?php
/**
 * Contrato del reloj.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Fuente de la hora actual, para poder probar la lógica que depende del tiempo.
 */
interface Clock {

	/**
	 * Marca de tiempo Unix actual.
	 */
	public function now(): int;
}
