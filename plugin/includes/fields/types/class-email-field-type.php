<?php
/**
 * Tipo de campo: correo electrónico.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de correo electrónico.
 */
final class Email_Field_Type extends Abstract_Text_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'email';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Email', 'formulario-mensaje' );
	}

	/**
	 * Atributo `type` del `<input>`.
	 */
	protected function input_type(): string {
		return 'email';
	}

	/**
	 * Longitud máxima del valor: la de una dirección de correo.
	 */
	protected function max_length(): int {
		return 254;
	}

	/**
	 * Valor inicial de la opción de autocompletado para un campo nuevo.
	 */
	protected function default_autocomplete(): string {
		return 'email';
	}

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		return is_string( $value ) && false !== is_email( $value )
			? null
			: __( 'Enter a valid email address.', 'formulario-mensaje' );
	}
}
