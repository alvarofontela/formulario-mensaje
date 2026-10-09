<?php
/**
 * Contexto de pintado de un campo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con el formulario y la instancia en los que se pinta un campo.
 */
final class Render_Context {

	/**
	 * Nombre del parámetro de la petición que agrupa los valores de los campos.
	 */
	public const VALUES_PARAM = 'fm';

	/**
	 * Constructor.
	 *
	 * @param int    $form_id     Identificador del formulario.
	 * @param string $instance_id Identificador de esta aparición del formulario en la página.
	 */
	public function __construct(
		public readonly int $form_id,
		public readonly string $instance_id
	) {}

	/**
	 * Atributo `id` del control de un campo, único en la página.
	 *
	 * @param Field $field Campo.
	 */
	public function control_id( Field $field ): string {
		return 'fm-' . $this->instance_id . '-' . $field->id;
	}

	/**
	 * Atributo `name` del control de un campo.
	 *
	 * @param Field $field Campo.
	 */
	public function control_name( Field $field ): string {
		return self::VALUES_PARAM . '[' . $field->id . ']';
	}
}
