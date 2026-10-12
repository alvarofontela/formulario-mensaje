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
	 * @param int      $form_id           Identificador del formulario.
	 * @param string   $instance_id       Identificador de esta aparición del formulario en la página.
	 * @param string[] $condition_sources Identificadores de los campos de los que depende la condición de algún otro.
	 */
	public function __construct(
		public readonly int $form_id,
		public readonly string $instance_id,
		public readonly array $condition_sources = array()
	) {}

	/**
	 * Indica si la condición de algún otro campo depende del valor de este: el navegador tiene que
	 * poder leerlo para mostrar u ocultar ese otro campo igual que hará el servidor al validar.
	 *
	 * @param Field $field Campo.
	 */
	public function is_condition_source( Field $field ): bool {
		return in_array( $field->id, $this->condition_sources, true );
	}

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
