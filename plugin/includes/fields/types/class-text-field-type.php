<?php
/**
 * Tipo de campo: texto de una línea.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de texto de una línea.
 */
final class Text_Field_Type extends Abstract_Text_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'text';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Text', 'formulario-mensaje' );
	}

	/**
	 * Atributo `type` del `<input>`.
	 */
	protected function input_type(): string {
		return 'text';
	}
}
