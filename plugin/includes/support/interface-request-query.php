<?php
/**
 * Contrato de acceso a los parámetros de la URL.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Parámetros de la URL de la petición actual.
 */
interface Request_Query {

	/**
	 * Valor de texto de un parámetro de la URL, saneado.
	 *
	 * @param string $name Nombre del parámetro.
	 * @return string Cadena vacía si no existe o no es un texto.
	 */
	public function get( string $name ): string;
}
