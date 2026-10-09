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
	 * @param Spam_Check[] $checks Comprobaciones, en el orden en que se aplican. Las que reservan algo
	 *                             (Reserving_Check) van al final, para no reservar por un envío que otra rechaza.
	 */
	public function __construct( private readonly array $checks ) {}

	/**
	 * Aplica las comprobaciones hasta que una rechaza el envío.
	 *
	 * Si una rechaza, las anteriores devuelven lo que hubieran reservado.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 * @return WP_Error|null Null si el envío puede continuar.
	 */
	public function inspect( Submission_Request $request, Form $form ): ?WP_Error {
		$passed = array();

		foreach ( $this->checks as $check ) {
			$error = $check->check( $request, $form );

			if ( null !== $error ) {
				$this->release_checks( $passed, $request, $form );

				return $error;
			}

			$passed[] = $check;
		}

		return null;
	}

	/**
	 * Avisa a las defensas de que un envío que habían dado por bueno no llegó a aceptarse.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function release( Submission_Request $request, Form $form ): void {
		$this->release_checks( $this->checks, $request, $form );
	}

	/**
	 * Devuelve lo reservado por las comprobaciones que reservan.
	 *
	 * @param Spam_Check[]       $checks  Comprobaciones.
	 * @param Submission_Request $request Envío.
	 * @param Form               $form    Formulario.
	 */
	private function release_checks( array $checks, Submission_Request $request, Form $form ): void {
		foreach ( $checks as $check ) {
			if ( $check instanceof Reserving_Check ) {
				$check->release( $request, $form );
			}
		}
	}
}
