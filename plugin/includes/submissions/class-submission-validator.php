<?php
/**
 * Validación de los datos de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Sanea y valida en el servidor los valores recibidos, campo a campo, según su tipo.
 */
final class Submission_Validator {

	/**
	 * Constructor.
	 *
	 * @param Field_Type_Registry $field_types Tipos de campo.
	 */
	public function __construct( private readonly Field_Type_Registry $field_types ) {}

	/**
	 * Sanea y valida los valores de un envío.
	 *
	 * Solo se leen los campos que define el formulario; cualquier otra clave de la petición se ignora.
	 *
	 * @param Form                 $form       Formulario.
	 * @param array<string, mixed> $raw_values Valores tal como llegaron, sin barras de escape.
	 * @return array{entries: array<string, Submission_Entry>, errors: array<string, string>}
	 */
	public function validate( Form $form, array $raw_values ): array {
		$entries = array();
		$errors  = array();

		foreach ( $form->definition->fields as $field ) {
			$type = $this->field_types->get_input( $field->type );

			if ( null === $type ) {
				continue;
			}

			$value = $type->sanitize_value( $field, $raw_values[ $field->id ] ?? null );
			$empty = '' === $value || array() === $value;
			$error = null;

			if ( $empty && $field->is_required() ) {
				$error = __( 'This field is required.', 'formulario-mensaje' );
			} elseif ( ! $empty ) {
				$error = $type->validate( $field, $value );
			}

			if ( null !== $error ) {
				$errors[ $field->id ] = $error;
			}

			$entries[ $field->id ] = new Submission_Entry(
				$field->id,
				$field->type,
				$field->label(),
				$value,
				$empty || null !== $error ? '' : $type->format_value( $field, $value )
			);
		}

		/**
		 * Filtra los errores de validación de un envío.
		 *
		 * Permite añadir reglas propias: devuelve el array con una entrada por campo no válido.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string>           $errors  Mensajes de error, por identificador de campo.
		 * @param array<string, Submission_Entry> $entries Datos saneados, por identificador de campo.
		 * @param Form                            $form    Formulario.
		 */
		$filtered = apply_filters( 'formulario_mensaje_validation_errors', $errors, $entries, $form );

		return array(
			'entries' => $entries,
			'errors'  => $this->clean_errors( $filtered, $entries ),
		);
	}

	/**
	 * Descarta lo que un filtro haya devuelto y no sea un mensaje de texto para un campo del envío.
	 *
	 * @param mixed                           $errors  Errores tras pasar por el filtro.
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
