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

		// Plain_Text y no sanitize_text_field(): esta quita los caracteres codificados (%20) de una dirección como la de procedencia.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Plain_Text::line() envuelve a sanitize_text_field().
		return Plain_Text::line( wp_unslash( $_SERVER[ $name ] ) );
	}
}
