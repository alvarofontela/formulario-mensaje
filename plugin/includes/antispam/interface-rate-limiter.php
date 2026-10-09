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
 */
interface Rate_Limiter {

	/**
	 * Sucesos contados para una clave en la ventana en curso.
	 *
	 * @param string $key Clave del contador.
	 */
	public function count( string $key ): int;

	/**
	 * Cuenta un suceso más para una clave.
	 *
	 * @param string $key    Clave del contador.
	 * @param int    $window Duración de la ventana, en segundos, si con este suceso empieza una nueva.
	 */
	public function hit( string $key, int $window ): void;
}
