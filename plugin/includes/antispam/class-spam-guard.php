<?php
/**
 * Cadena de defensas antispam.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Aplica, en orden, todas las defensas antispam a un envío.
 */
final class Spam_Guard {

	/**
	 * Constructor.
	 *
	 * @param Spam_Check[]          $checks    Comprobaciones, en el orden en que se aplican.
	 * @param Submission_Recorder[] $recorders Defensas que toman nota de los envíos aceptados.
	 */
	public function __construct(
		private readonly array $checks,
		private readonly array $recorders
	) {}

	/**
	 * Aplica las comprobaciones hasta que una rechaza el envío.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 * @return WP_Error|null Null si el envío puede continuar.
	 */
	public function inspect( Submission_Request $request, Form $form ): ?WP_Error {
		foreach ( $this->checks as $check ) {
			$error = $check->check( $request, $form );

			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Avisa a las defensas de que un envío se ha aceptado.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function record( Submission_Request $request, Form $form ): void {
		foreach ( $this->recorders as $recorder ) {
			$recorder->record( $request, $form );
		}
	}
}
