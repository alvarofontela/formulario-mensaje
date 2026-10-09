<?php
/**
 * Tipo de campo: desplegable.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Campo desplegable de una sola elección.
 */
final class Select_Field_Type extends Abstract_Choice_Field_Type {

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'select';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Dropdown', 'formulario-mensaje' );
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		$placeholder          = $this->common->placeholder();
		$placeholder['label'] = __( 'Empty option text', 'formulario-mensaje' );

		return array(
			$this->common->label( $this->get_label() ),
			$this->common->required(),
			$this->options_setting(),
			$placeholder,
			$this->common->help(),
			$this->common->autocomplete(),
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
		$id          = $context->control_id( $field );
		$placeholder = $field->text( 'placeholder' );
		$options     = sprintf(
			'<option value="">%s</option>',
			esc_html( '' !== $placeholder ? $placeholder : __( 'Select an option', 'formulario-mensaje' ) )
		);

		foreach ( $this->options( $field ) as $value => $label ) {
			$options .= sprintf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( $label ) );
		}

		$control = sprintf(
			'<select%s>%s</select>',
			$this->markup->attributes(
				array(
					'class'            => 'fm-control',
					'id'               => $id,
					'name'             => $context->control_name( $field ),
					'autocomplete'     => $field->text( 'autocomplete' ),
					'required'         => $field->is_required(),
					'aria-describedby' => $this->markup->described_by( $field, $id ),
				)
			),
			$options
		);

		return $this->markup->field( $field, $context, $control );
	}
}
