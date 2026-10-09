<?php
/**
 * Tipo de campo: paso.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Fields\Types;

use Formulario_Mensaje\Fields\Common_Settings;
use Formulario_Mensaje\Fields\Render_Context;
use Formulario_Mensaje\Fields\Section_Field_Type;
use Formulario_Mensaje\Forms\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Abre un paso del formulario. No recoge datos: los campos que le siguen, hasta el siguiente paso,
 * se rellenan juntos antes de pasar a los demás.
 *
 * Su condición, si la tiene, decide si el paso entero se muestra.
 */
final class Step_Field_Type implements Section_Field_Type {

	/**
	 * Constructor.
	 *
	 * @param Common_Settings $common Opciones comunes de los campos.
	 */
	public function __construct( private readonly Common_Settings $common ) {}

	/**
	 * Identificador del tipo.
	 */
	public function get_type(): string {
		return 'step';
	}

	/**
	 * Nombre del tipo, ya traducido.
	 */
	public function get_label(): string {
		return __( 'Step', 'formulario-mensaje' );
	}

	/**
	 * Grupo del constructor en el que aparece.
	 */
	public function get_group(): string {
		return 'layout';
	}

	/**
	 * Opciones que admite un campo de este tipo.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_schema(): array {
		$label          = $this->common->label( $this->get_label() );
		$label['label'] = __( 'Step title', 'formulario-mensaje' );

		return array( $label );
	}

	/**
	 * Título del paso.
	 *
	 * @param Field $field Campo que abre el paso.
	 */
	public function section_title( Field $field ): string {
		return $field->label();
	}

	/**
	 * Devuelve el título del paso; su envoltorio, con los campos dentro, lo compone el formulario.
	 *
	 * @param Field          $field   Campo que se pinta.
	 * @param Render_Context $context Formulario e instancia en los que se pinta.
	 */
	public function render( Field $field, Render_Context $context ): string {
		$title = $this->section_title( $field );

		// Recibe el foco al cambiar de paso, para que un lector de pantalla anuncie dónde se está.
		return '' === $title ? '' : sprintf( '<h3 class="fm-step__title" tabindex="-1">%s</h3>', esc_html( $title ) );
	}
}
