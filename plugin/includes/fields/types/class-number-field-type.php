<?php
/**
 * Tipo de campo: número.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo numérico.
 */
final class Number_Field_Type extends Abstract_Text_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'number';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Number', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'advanced';
	}

	/**
	 * Atributo `type` del `<input>`.
	 *
	 * Es `text` con teclado numérico: un `type="number"` descarta sin avisar lo que no entiende y
	 * cambia el valor con la rueda del ratón.
	 */
	protected function input_type(): string {
		return 'text';
	}

	/**
	 * Longitud máxima del valor.
	 */
	protected function max_length(): int {
		return 40;
	}

	/**
	 * Atributos propios del tipo: teclado numérico en los dispositivos táctiles.
	 *
	 * @return array<string, string|int|bool|null>
	 */
	protected function extra_attributes(): array {
		return array( 'inputmode' => 'decimal' );
	}

	/**
	 * Sanea el valor recibido en un envío; la coma decimal se convierte en punto.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		$value = parent::sanitize_value( $field, $raw );

		return is_string( $value ) ? str_replace( array( ' ', ',' ), array( '', '.' ), $value ) : '';
	}

	/**
	 * Valida el formato de un valor saneado y no vacío.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $value )
			? null
			: __( 'Enter a valid number.', 'formulario-mensaje' );
	}
}
