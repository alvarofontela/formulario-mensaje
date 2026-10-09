<?php
/**
 * Envíos de ejemplo.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrica un envío con datos de ejemplo para probar las acciones de un formulario sin esperar a uno real.
 *
 * Cada tipo de campo aporta su propio valor de ejemplo.
 */
final class Sample_Submission_Factory {

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 * @param Reference_Generator $references  Números de referencia.
	 * @param Clock               $clock       Reloj.
	 */
	public function __construct(
		private readonly Field_Type_Registry $field_types,
		private readonly Reference_Generator $references,
		private readonly Clock $clock
	) {}

	/**
	 * Crea un envío de ejemplo para un formulario.
	 *
	 * @param Form $form Formulario, guardado o no.
	 */
	public function create( Form $form ): Submission {
		$entries = array();

		foreach ( $form->definition->fields as $field ) {
			$type = $this->field_types->get_input( $field->type );

			if ( null === $type ) {
				continue;
			}

			$value                 = $type->sample_value( $field );
			$entries[ $field->id ] = new Submission_Entry( $field->id, $field->type, $field->label(), $value, $type->format_value( $field, $value ) );
		}

		return new Submission( $form->id, $this->references->generate(), $this->clock->now(), home_url( '/' ), $entries );
	}
}
