<?php
/**
 * Dato de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con lo que se recibió en un campo, junto a su etiqueta tal como era en ese momento.
 */
final class Submission_Entry {

	/**
	 * Constructor.
	 *
	 * @param string          $field_id  Identificador del campo.
	 * @param string          $type      Tipo del campo.
	 * @param string          $label     Etiqueta del campo cuando se recibió el envío.
	 * @param string|string[] $value     Valor saneado.
	 * @param string          $formatted Valor legible, sin escapar; cadena vacía si no hay dato.
	 */
	public function __construct(
		public readonly string $field_id,
		public readonly string $type,
		public readonly string $label,
		public readonly string|array $value,
		public readonly string $formatted
	) {}

	/**
	 * Representación para guardar como JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'field_id'  => $this->field_id,
			'type'      => $this->type,
			'label'     => $this->label,
			'value'     => $this->value,
			'formatted' => $this->formatted,
		);
	}

	/**
	 * Reconstruye un dato a partir del array que devuelve to_array().
	 *
	 * @param mixed $data Array leído de la base de datos.
	 * @return self|null Null si no tiene la forma esperada.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['field_id'] ?? null ) ) {
			return null;
		}

		$value = $data['value'] ?? '';

		return new self(
			$data['field_id'],
			is_string( $data['type'] ?? null ) ? $data['type'] : '',
			is_string( $data['label'] ?? null ) ? $data['label'] : '',
			is_array( $value ) ? array_map( 'strval', $value ) : (string) $value,
			is_string( $data['formatted'] ?? null ) ? $data['formatted'] : ''
		);
	}

	/**
	 * Indica si el dato está vacío.
	 */
	public function is_empty(): bool {
		return '' === $this->value || array() === $this->value;
	}
}
