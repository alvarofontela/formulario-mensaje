<?php
/**
 * HTML en línea permitido en los textos configurables.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Limita a un formato básico (negrita, cursiva, enlaces y saltos de línea) los textos que escribe quien
 * configura un formulario: párrafos, texto del consentimiento y mensaje de éxito.
 */
final class Inline_Html {

	/**
	 * Etiquetas y atributos permitidos, en el formato de wp_kses().
	 *
	 * @return array<string, array<string, bool>>
	 */
	public function allowed(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
		);
	}

	/**
	 * Elimina de un texto todo el HTML que no sea formato básico.
	 *
	 * Sirve tanto para sanear al guardar como para escapar al imprimir.
	 *
	 * @param string $html Texto con HTML.
	 */
	public function filter( string $html ): string {
		return wp_kses( $html, $this->allowed(), array( 'http', 'https', 'mailto', 'tel' ) );
	}
}
