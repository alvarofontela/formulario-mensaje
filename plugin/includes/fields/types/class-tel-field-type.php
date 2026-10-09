<?php
/**
 * Tipo de campo: teléfono.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de teléfono.
 */
final class Tel_Field_Type extends Abstract_Text_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'tel';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Phone', 'formulario-mensaje' );
	}

	/**
	 * Atributo `type` del `<input>`.
	 */
	protected function input_type(): string {
		return 'tel';
	}

	/**
	 * Longitud máxima del valor.
	 */
	protected function max_length(): int {
		return 30;
	}

	/**
	 * Valor inicial de la opción de autocompletado para un campo nuevo.
	 */
	protected function default_autocomplete(): string {
		return 'tel';
	}

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * Admite cifras, espacios y los signos habituales, con cinco cifras como mínimo.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		$valid = is_string( $value )
			&& 1 === preg_match( '/^[0-9+().\-\s]+$/', $value )
			&& strlen( (string) preg_replace( '/\D/', '', $value ) ) >= 5;

		return $valid ? null : __( 'Enter a valid phone number.', 'formulario-mensaje' );
	}
}
