<?php
/**
 * Tipo de campo: fecha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de fecha.
 */
final class Date_Field_Type extends Abstract_Text_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'date';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Date', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'advanced';
	}

	/**
	 * Atributo `type` del `<input>`.
	 */
	protected function input_type(): string {
		return 'date';
	}

	/**
	 * Longitud máxima del valor: la de una fecha ISO.
	 */
	protected function max_length(): int {
		return 10;
	}

	/**
	 * Valida el formato de un valor saneado y no vacío: una fecha real en formato AAAA-MM-DD.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function validate( Field $field, string|array $value ): ?string {
		$valid = is_string( $value )
			&& 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts )
			&& checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );

		return $valid ? null : __( 'Enter a valid date.', 'formulario-mensaje' );
	}

	/**
	 * Convierte un valor saneado en un texto legible, con el formato de fecha del sitio.
	 *
	 * @param Field           $field Campo al que pertenece el valor.
	 * @param string|string[] $value Valor saneado.
	 */
	public function format_value( Field $field, string|array $value ): string {
		if ( ! is_string( $value ) || null !== $this->validate( $field, $value ) ) {
			return '';
		}

		$timestamp = strtotime( $value . ' 12:00:00 UTC' );

		return false === $timestamp ? $value : (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string|string[]
	 */
	public function sample_value( Field $field ): string|array {
		return (string) wp_date( 'Y-m-d' );
	}
}
