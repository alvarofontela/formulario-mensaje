<?php
/**
 * Parámetros de la URL leídos de PHP.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lee los parámetros de la URL de la petición en curso.
 */
final class Php_Request_Query implements Request_Query {

	/**
	 * Longitud máxima que se acepta en un valor.
	 */
	private const MAX_LENGTH = 2000;

	/**
	 * Valor de texto de un parámetro de la URL, saneado.
	 *
	 * @param string $name Nombre del parámetro.
	 * @return string Cadena vacía si no existe o no es un texto.
	 */
	public function get( string $name ): string {
		/*
		 * Sin nonce a propósito: son parámetros de una URL pública (UTM, prellenado, aviso de envío
		 * correcto) que solo se leen para mostrarlos; no cambian estado ni autorizan nada. El valor se
		 * trata como entrada no fiable: se sanea aquí y se escapa al imprimirlo.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $name || ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Plain_Text::line() envuelve a sanitize_text_field().
		return mb_substr( Plain_Text::line( wp_unslash( $_GET[ $name ] ) ), 0, self::MAX_LENGTH );
	}
}
