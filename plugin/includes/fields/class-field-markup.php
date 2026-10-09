<?php
/**
 * HTML común de los campos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields;

use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Construye el envoltorio accesible de un campo: etiqueta, texto de ayuda y hueco para el error.
 *
 * Todo lo que devuelve está escapado. El HTML del control lo aporta cada tipo, también escapado.
 */
final class Field_Markup {

	/**
	 * Envuelve un control que tiene su propia etiqueta (`<label for>`).
	 *
	 * @param Field          $field   Campo.
	 * @param Render_Context $context Contexto de pintado.
	 * @param string         $control HTML del control, ya escapado.
	 */
	public function field( Field $field, Render_Context $context, string $control ): string {
		$id = $context->control_id( $field );

		return sprintf(
			'<div class="%1$s" data-fm-field="%2$s"><label class="fm-label" for="%3$s">%4$s%5$s</label>%6$s%7$s%8$s</div>',
			esc_attr( $this->wrapper_class( $field ) ),
			esc_attr( $field->id ),
			esc_attr( $id ),
			esc_html( $field->label() ),
			$this->required_mark( $field ),
			$control,
			$this->help( $field, $id ),
			$this->error_slot( $id )
		);
	}

	/**
	 * Envuelve un grupo de controles (radios o casillas) en un `fieldset` con su `legend`.
	 *
	 * @param Field          $field    Campo.
	 * @param Render_Context $context  Contexto de pintado.
	 * @param string         $controls HTML de los controles, ya escapado.
	 */
	public function group( Field $field, Render_Context $context, string $controls ): string {
		$id = $context->control_id( $field );

		return sprintf(
			'<fieldset class="%1$s" data-fm-field="%2$s" id="%3$s"%4$s><legend class="fm-label">%5$s%6$s</legend><div class="fm-choices">%7$s</div>%8$s%9$s</fieldset>',
			esc_attr( $this->wrapper_class( $field ) . ' fm-field--group' ),
			esc_attr( $field->id ),
			esc_attr( $id ),
			$this->attributes( array( 'aria-describedby' => $this->described_by( $field, $id ) ) ),
			esc_html( $field->label() ),
			$this->required_mark( $field ),
			$controls,
			$this->help( $field, $id ),
			$this->error_slot( $id )
		);
	}

	/**
	 * Envuelve un control que incluye su propia etiqueta, como la casilla de consentimiento.
	 *
	 * @param Field          $field   Campo.
	 * @param Render_Context $context Contexto de pintado.
	 * @param string         $control HTML del control con su etiqueta, ya escapado.
	 */
	public function bare( Field $field, Render_Context $context, string $control ): string {
		$id = $context->control_id( $field );

		return sprintf(
			'<div class="%1$s" data-fm-field="%2$s">%3$s%4$s%5$s</div>',
			esc_attr( $this->wrapper_class( $field ) ),
			esc_attr( $field->id ),
			$control,
			$this->help( $field, $id ),
			$this->error_slot( $id )
		);
	}

	/**
	 * Envuelve un elemento de presentación, que no recoge datos.
	 *
	 * @param Field  $field   Campo.
	 * @param string $content HTML del elemento, ya escapado.
	 */
	public function presentation( Field $field, string $content ): string {
		return sprintf( '<div class="%1$s">%2$s</div>', esc_attr( $this->wrapper_class( $field ) ), $content );
	}

	/**
	 * Marca visual de campo obligatorio. Los lectores de pantalla ya anuncian el atributo `required`.
	 *
	 * @param Field $field Campo.
	 */
	public function required_mark( Field $field ): string {
		return $field->is_required() ? ' <span class="fm-required-mark" aria-hidden="true">*</span>' : '';
	}

	/**
	 * Valor de `aria-describedby` de un control: el texto de ayuda, si lo hay.
	 *
	 * El identificador del error lo añade el script cuando hay un error que mostrar.
	 *
	 * @param Field  $field      Campo.
	 * @param string $control_id Atributo `id` del control.
	 */
	public function described_by( Field $field, string $control_id ): string {
		return '' !== $field->text( 'help' ) ? $control_id . '-help' : '';
	}

	/**
	 * Convierte una lista de atributos en HTML, escapando los valores.
	 *
	 * Los atributos con valor `true` se imprimen sin valor; los `false`, `null` o cadena vacía se omiten.
	 *
	 * @param array<string, string|int|bool|null> $attributes Atributos.
	 * @return string Atributos precedidos de un espacio, o cadena vacía.
	 */
	public function attributes( array $attributes ): string {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			if ( null === $value || false === $value || '' === $value ) {
				continue;
			}

			$html .= true === $value
				? ' ' . esc_attr( $name )
				: sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
		}

		return $html;
	}

	/**
	 * Clases del envoltorio de un campo.
	 *
	 * @param Field $field Campo.
	 */
	private function wrapper_class( Field $field ): string {
		$width = 100 === $field->width ? '' : ' fm-w-' . $field->width;

		return trim( 'fm-field fm-field--' . sanitize_html_class( $field->type ) . $width . ' ' . $field->text( 'css_class' ) );
	}

	/**
	 * Texto de ayuda del campo.
	 *
	 * @param Field  $field      Campo.
	 * @param string $control_id Atributo `id` del control.
	 */
	private function help( Field $field, string $control_id ): string {
		$help = $field->text( 'help' );

		return '' === $help ? '' : sprintf( '<p class="fm-help" id="%s">%s</p>', esc_attr( $control_id . '-help' ), esc_html( $help ) );
	}

	/**
	 * Hueco donde el script escribe el error del campo.
	 *
	 * @param string $control_id Atributo `id` del control.
	 */
	private function error_slot( string $control_id ): string {
		return sprintf( '<p class="fm-error" id="%s" hidden></p>', esc_attr( $control_id . '-error' ) );
	}
}
