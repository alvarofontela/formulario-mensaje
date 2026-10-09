<?php
/**
 * Validación de un paso.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Fields\Field_Type_Registry;
use Formulario_Mensaje\Fields\Step_Layout;
use Formulario_Mensaje\Forms\Form;

defined( 'ABSPATH' ) || exit;

/**
 * Valida los campos de un paso de un formulario, para dejar avanzar al siguiente.
 *
 * No acepta ni guarda nada: es la misma validación del envío, limitada a un paso. Al enviar el
 * formulario se vuelve a validar entero.
 */
final class Step_Validator {

	/**
	 * Constructor.
	 *
	 * @param Submission_Validator $validator Validación de envíos.
	 * @param Step_Layout          $layout      Pasos de los formularios.
	 * @param Field_Type_Registry  $field_types Tipos de campo.
	 */
	public function __construct(
		private readonly Submission_Validator $validator,
		private readonly Step_Layout $layout,
		private readonly Field_Type_Registry $field_types
	) {}

	/**
	 * Errores de los campos de un paso.
	 *
	 * @param Form                 $form       Formulario.
	 * @param array<string, mixed> $raw_values Valores tal como llegan, de todos los pasos rellenados hasta ahora.
	 * @param int                  $step       Posición del paso, empezando en 0.
	 * @return array<string, string> Mensajes de error, por identificador de campo; vacío si el paso es válido o no existe.
	 */
	public function errors( Form $form, array $raw_values, int $step ): array {
		$steps = $this->layout->steps( $form->definition );

		if ( ! isset( $steps[ $step ] ) ) {
			return array();
		}

		$ids = array();

		foreach ( $steps[ $step ]['fields'] as $field ) {
			// Los ficheros no se suben para validar un paso: se comprueban al enviar el formulario.
			if ( null === $this->field_types->get_upload( $field->type ) ) {
				$ids[] = $field->id;
			}
		}

		// Se valida el envío entero, con sus condiciones, y se devuelven solo los errores de este paso.
		return array_intersect_key( $this->validator->validate( $form, $raw_values )['errors'], array_flip( $ids ) );
	}
}
