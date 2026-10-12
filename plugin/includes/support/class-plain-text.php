<?php
/**
 * Saneado de texto sin formato.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Limpia un texto sin formato (lo que escribe un visitante en un campo, una etiqueta, un asunto,
 * una dirección) como lo hace WordPress, pero sin estropear lo que no es HTML.
 *
 * `sanitize_text_field()` está pensada para un texto que se va a imprimir sin escapar, y por eso:
 *
 * - convierte en `&lt;` todo «<» que no abre una etiqueta: «a<b» pasa a ser `a&lt;b`;
 * - elimina todo «%» seguido de dos cifras hexadecimales: «un 50%de descuento» se queda en
 *   «un 50 descuento», y una dirección como `…/mi%20informe.pdf`, en `…/miinforme.pdf`.
 *
 * Este plugin guarda el texto tal cual y lo escapa al mostrarlo, así que lo primero se veía como
 * «Me encanta &lt;3» en correos y administración, y lo segundo se guardaba ya roto, sin avisar.
 *
 * Las etiquetas HTML se siguen quitando igual. El resultado no es HTML seguro: es texto, y se
 * escapa siempre al imprimirlo, como cualquier otro dato del plugin.
 *
 * Son funciones puras y sin estado, equivalentes a las de WordPress a las que sustituyen: por eso
 * se llaman directamente y no se inyectan como un servicio.
 */
final class Plain_Text {

	/**
	 * Carácter de uso privado con el que se guardan los «<» sueltos mientras WordPress limpia el texto.
	 */
	private const LESS_THAN = "\u{E000}";

	/**
	 * Carácter de uso privado con el que se guardan los «%» mientras WordPress limpia el texto.
	 */
	private const PERCENT = "\u{E001}";

	/**
	 * Pasadas que se dan, como mucho, hasta que el texto deja de cambiar.
	 */
	private const MAX_PASSES = 5;

	/**
	 * Limpia un texto de una línea: sin etiquetas, sin saltos de línea y sin espacios de más.
	 *
	 * @param string $text Texto sin validar.
	 */
	public static function line( string $text ): string {
		return self::clean( $text, 'sanitize_text_field' );
	}

	/**
	 * Limpia un texto de varias líneas: sin etiquetas y conservando los saltos de línea.
	 *
	 * Los saltos se guardan siempre como `\n`: un navegador envía los de un área de texto como
	 * `\r\n`, y así llegarían a los correos, a los webhooks y a la exportación.
	 *
	 * @param string $text Texto sin validar.
	 */
	public static function block( string $text ): string {
		return self::clean( str_replace( array( "\r\n", "\r" ), "\n", $text ), 'sanitize_textarea_field' );
	}

	/**
	 * Limpia un texto con la función de WordPress indicada, conservando los «%» y los «<» que no
	 * abren una etiqueta.
	 *
	 * @param string   $text     Texto sin validar.
	 * @param callable $sanitize Función de saneado de WordPress.
	 */
	private static function clean( string $text, callable $sanitize ): string {
		// Los caracteres reservados no pueden venir en el texto: se quitan antes de usarlos de marca.
		$text = str_replace( array( self::LESS_THAN, self::PERCENT ), '', $text );
		$text = str_replace( '%', self::PERCENT, $text );
		$text = str_contains( $text, '<' ) ? self::without_tags( $text, $sanitize ) : (string) $sanitize( $text );

		return str_replace( self::PERCENT, '%', $text );
	}

	/**
	 * Limpia un texto que contiene algún «<»: quita las etiquetas y conserva los que no lo son.
	 *
	 * @param string   $text     Texto, sin los caracteres reservados.
	 * @param callable $sanitize Función de saneado de WordPress.
	 */
	private static function without_tags( string $text, callable $sanitize ): string {
		/*
		 * Al devolver los «<» a su sitio podría recomponerse una etiqueta a partir de trozos
		 * («<scr» + «ipt>»). Se repite hasta que el texto ya no cambia: lo que queda no tiene
		 * ninguna etiqueta que WordPress quitaría.
		 */
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$cleaned = self::pass( $text, $sanitize );

			if ( $cleaned === $text ) {
				return $cleaned;
			}

			$text = $cleaned;
		}

		// Si no se estabiliza, se devuelve lo que daría WordPress por sí solo.
		return (string) $sanitize( $text );
	}

	/**
	 * Una pasada de limpieza.
	 *
	 * @param string   $text     Texto.
	 * @param callable $sanitize Función de saneado de WordPress.
	 */
	private static function pass( string $text, callable $sanitize ): string {
		// La misma expresión con la que WordPress distingue una etiqueta de un «<» suelto (wp_pre_kses_less_than).
		$marked = preg_replace_callback(
			'%<[^>]*?((?=<)|>|$)%',
			static fn ( array $found ): string => str_contains( $found[0], '>' ) ? $found[0] : self::LESS_THAN . substr( $found[0], 1 ),
			$text
		);

		return str_replace( self::LESS_THAN, '<', (string) $sanitize( null === $marked ? $text : $marked ) );
	}
}
