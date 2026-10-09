<?php
/**
 * Tipo de campo: botones de opción.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo de botones de opción: una sola elección entre varias a la vista.
 */
final class Radio_Field_Type extends Abstract_Choice_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'radio';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Radio buttons', 'formulario-mensaje' );
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
						'type'     => 'radio',
						'id'       => $option_id,
						'name'     => $context->control_name( $field ),
						'value'    => (string) $value,
						// En un grupo de radios basta con marcar uno como obligatorio.
						'required' => $field->is_required() && 0 === $index,
					)
				),
				esc_attr( $option_id ),
				esc_html( $label )
			);
			++$index;
		}

		return $this->markup->group( $field, $context, $controls );
	}
}
