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
	 * Indica si el campo llegó sin dato.
	 */
	public function is_empty(): bool {
		return '' === $this->value || array() === $this->value;
	}
}
