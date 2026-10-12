<?php
/**
 * Tipo de campo: casillas.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de casillas: varias elecciones a la vez.
 */
final class Checkboxes_Field_Type extends Abstract_Choice_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'checkboxes';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Checkboxes', 'formulario-mensaje' );
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		return array(
			$this->common->label( $this->get_label() ),
			$this->common->required(),
			$this->options_setting(),
			$this->common->help(),
			$this->common->css_class(),
		);
	}

	/**
	 * Devuelve el HTML del campo, con todos los valores ya escapados.
	 *
	 * El atributo `required` no sirve en un grupo de casillas (obligaría a marcarlas todas): la
	 * obligatoriedad la comprueba el servidor.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		$id       = $context->control_id( $field );
		$controls = '';
		$index    = 0;

		foreach ( $this->options( $field ) as $value => $label ) {
			$option_id = $id . '-' . $index;
			$controls .= sprintf(
				'<div class="fm-choice"><input%s><label for="%s">%s</label></div>',
				$this->markup->attributes(
					array(
						'type'  => 'checkbox',
						'id'    => $option_id,
						'name'  => $context->control_name( $field ) . '[]',
						'value' => (string) $value,
					)
				),
				esc_attr( $option_id ),
				esc_html( $label )
			);
			++$index;
		}

		return $this->markup->group( $field, $context, $controls );
	}

	/**
	 * Sanea el valor recibido en un envío: una lista de opciones, sin repetidas.
	 *
	 * @param Field $field Campo al que pertenece el valor.
	 * @param mixed $raw   Valor tal como llegó en la petición.
	 * @return string|string[]
	 */
	public function sanitize_value( Field $field, mixed $raw ): string|array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$values = array();

		// Como mucho hay tantos valores como opciones; lo que sobre es ruido.
		foreach ( array_slice( $raw, 0, count( $this->options( $field ) ) ) as $item ) {
			if ( is_scalar( $item ) && '' !== (string) $item ) {
				$values[] = $this->clean_choice( $field, (string) $item );
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Valor de ejemplo para probar notificaciones y otras acciones.
	 *
	 * @param Field $field Campo.
	 * @return string|string[]
	 */
	public function sample_value( Field $field ): string|array {
		return array_slice( array_map( 'strval', array_keys( $this->options( $field ) ) ), 0, 1 );
	}
}
