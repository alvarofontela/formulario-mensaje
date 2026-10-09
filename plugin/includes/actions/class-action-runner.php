<?php
/**
 * Ejecución de las acciones de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Actions;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Rules\Rule_Evaluator;
use Formulario_Mensaje\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta, una a una, las acciones activas de un formulario sobre un envío.
 *
 * Cada acción puede llevar una condición sobre los datos del envío: así se resuelve, por ejemplo,
 * que la notificación vaya a un destinatario u otro según lo que se haya respondido.
 */
final class Action_Runner {

	/**
	 * Constructor.
	 *
	 * @param Action_Type_Registry $action_types Tipos de acción.
	 * @param Rule_Evaluator       $rules        Motor de reglas.
	 */
	public function __construct(
		private readonly Action_Type_Registry $action_types,
		private readonly Rule_Evaluator $rules
	) {}

	/**
	 * Ejecuta las acciones activas del formulario. El fallo de una no impide las demás.
	 *
	 * @param Form       $form       Formulario.
	 * @param Submission $submission Envío ya validado.
	 * @return Action_Result[] Un resultado por acción ejecutada.
	 */
	public function run( Form $form, Submission $submission ): array {
		$results = array();
		$values  = array();

		foreach ( $submission->entries as $entry ) {
			$values[ $entry->field_id ] = $entry->value;
		}

		foreach ( $form->definition->actions as $action ) {
			$type = $this->action_types->get( $action['type'] );

			// Una acción desactivada, o de un tipo que ya no está registrado, no se ejecuta.
			if ( null === $type || ! $action['enabled'] ) {
				continue;
			}

			// Tampoco la que tiene una condición que este envío no cumple: no es un fallo, no le toca.
			if ( ! $this->rules->passes( $action['condition'] ?? array(), $values ) ) {
				continue;
			}

			$outcome   = $type->execute( $action['settings'], $submission, $form );
			$results[] = new Action_Result(
				$action['id'],
				$action['type'],
				true === $outcome,
				is_wp_error( $outcome ) ? $outcome->get_error_code() . ': ' . $outcome->get_error_message() : ''
			);
		}

		return $results;
	}
}
