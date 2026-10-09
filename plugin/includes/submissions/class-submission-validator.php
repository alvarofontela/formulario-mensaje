<?php
/**
 * Validación de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Step_Layout;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Rules\Visibility_Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Sanea y valida los valores de un envío contra los campos del formulario.
 *
 * Solo se leen los valores de los campos que el formulario tiene; lo demás que traiga la petición
 * se ignora. Cada tipo de campo sanea y valida su propio valor.
 *
 * Los campos con condición que no se cumple no forman parte del envío: no se validan, aunque sean
 * obligatorios, y su valor se descarta aunque la petición lo traiga. Es el servidor quien lo decide,
 * con sus propias reglas: lo que el navegador haya mostrado u ocultado no cuenta.
 */
final class Submission_Validator {

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 * @param Visibility_Resolver $visibility  Visibilidad condicional.
	 * @param Step_Layout         $steps       Pasos de los formularios.
	 */
	public function __construct(
		private readonly Field_Type_Registry $field_types,
		private readonly Visibility_Resolver $visibility,
		private readonly Step_Layout $steps
	) {}

	/**
	 * Sanea y valida un envío.
	 *
	 * @param Form                 $form       Formulario.
	 * @param array<string, mixed> $raw_values Valores tal como llegan, por identificador de campo.
	 * @return array{entries: array<string, Submission_Entry>, errors: array<string, string>}
	 */
	public function validate( Form $form, array $raw_values ): array {
		$values     = array();
		$conditions = array();
		$inputs     = array();

		// Primero se sanean todos los valores: las condiciones se evalúan sobre valores ya saneados.
		foreach ( $form->definition->fields as $field ) {
			$type = $this->field_types->get_input( $field->type );

			if ( null === $type ) {
				// Un paso no recoge datos, pero su condición decide si sus campos forman parte del envío.
				if ( null !== $this->field_types->get_section( $field->type ) ) {
					$conditions[ $field->id ] = $field->condition;
				}

				continue;
			}

			$inputs[ $field->id ]     = array( $field, $type );
			$values[ $field->id ]     = $type->sanitize_value( $field, $raw_values[ $field->id ] ?? null );
			$conditions[ $field->id ] = $field->condition;
		}

		$visible = $this->visibility->resolve( $conditions, $values, $this->steps->parents( $form->definition ) );
		$entries = array();
		$errors  = array();

		foreach ( $inputs as $id => list( $field, $type ) ) {
			if ( ! $visible[ $id ] ) {
				continue;
			}

			$value = $values[ $id ];
			$empty = '' === $value || array() === $value;
			$error = null;

			if ( $empty && $field->is_required() ) {
				$error = __( 'This field is required.', 'formulario-mensaje' );
			} elseif ( ! $empty ) {
				$error = $type->validate( $field, $value );
			}

			if ( null !== $error ) {
				$errors[ $id ] = $error;
			}

			$entries[ $id ] = new Submission_Entry(
				$id,
				$field->type,
				$field->label(),
				$value,
				$empty || null !== $error ? '' : $type->format_value( $field, $value )
			);
		}

		/**
		 * Filtra los errores de validación de un envío.
		 *
		 * Permite añadir validaciones propias (por ejemplo, entre varios campos) o retirar errores.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string>           $errors  Mensajes de error, por identificador de campo.
		 * @param array<string, Submission_Entry> $entries Datos del envío ya saneados, solo de los campos que le corresponden.
		 * @param Form                            $form    Formulario.
		 */
		$filtered = apply_filters( 'formulario_mensaje_validation_errors', $errors, $entries, $form );

		return array(
			'entries' => $entries,
			'errors'  => $this->clean_errors( $filtered, $entries ),
		);
	}

	/**
	 * Deja solo los errores bien formados y de campos que existen en el envío.
	 *
	 * @param mixed                           $errors  Errores tras el filtro.
	 * @param array<string, Submission_Entry> $entries Datos del envío.
	 * @return array<string, string>
	 */
	private function clean_errors( mixed $errors, array $entries ): array {
		$clean = array();

		foreach ( is_array( $errors ) ? $errors : array() as $field_id => $message ) {
			if ( is_string( $message ) && '' !== $message && isset( $entries[ $field_id ] ) ) {
				$clean[ (string) $field_id ] = $message;
			}
		}

		return $clean;
	}
}
