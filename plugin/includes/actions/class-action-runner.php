<?php
/**
 * Ejecución de las acciones de un envío.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Actions;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Ejecuta, una a una, las acciones activas de un formulario sobre un envío.
 */
final class Action_Runner {

	/**
	 * Constructor.
	 *
	 * @param Action_Type_Registry $action_types Tipos de acción.
	 */
	public function __construct( private readonly Action_Type_Registry $action_types ) {}

	/**
	 * Ejecuta las acciones activas del formulario. El fallo de una no impide las demás.
	 *
	 * @param Form       $form       Formulario.
	 * @param Submission $submission Envío ya validado.
	 * @return Action_Result[] Un resultado por acción ejecutada.
	 */
	public function run( Form $form, Submission $submission ): array {
		$results = array();

		foreach ( $form->definition->actions as $action ) {
			$type = $this->action_types->get( $action['type'] );

			// Una acción desactivada, o de un tipo que ya no está registrado, no se ejecuta.
			if ( null === $type || ! $action['enabled'] ) {
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
