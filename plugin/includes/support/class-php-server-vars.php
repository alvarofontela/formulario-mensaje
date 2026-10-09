<?php
/**
 * Variables del servidor leídas de PHP.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lee las variables del servidor de la petición en curso.
 */
final class Php_Server_Vars implements Server_Vars {

	/**
	 * Valor de una variable del servidor, saneado como texto de una línea.
	 *
	 * Las cabeceras las escribe el cliente: quien use el valor decide si es de fiar.
	 *
	 * @param string $name Nombre de la variable, como `REMOTE_ADDR` o `HTTP_REFERER`.
	 * @return string Cadena vacía si no existe.
	 */
	public function get( string $name ): string {
		if ( ! isset( $_SERVER[ $name ] ) || ! is_string( $_SERVER[ $name ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) );
	}
}
