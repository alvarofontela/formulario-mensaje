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
	 * @param Spam_Check[] $checks Comprobaciones, en el orden en que se aplican: de la más barata a la más cara.
	 */
	public function __construct( private readonly array $checks ) {}

	/**
	 * Aplica las comprobaciones hasta que una rechaza el envío.
	 *
	 * Si una rechaza, lo que hubieran reservado las anteriores no se devuelve: un envío rechazado por
	 * una defensa es un intento, y tiene que seguir contando para el límite por IP. Así nadie puede
	 * insistir sin tope contra una comprobación cara, como la del captcha, que consulta a otro servicio.
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
	 * Avisa a las defensas de que un envío que habían dado por bueno no llegó a aceptarse.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envió.
	 */
	public function release( Submission_Request $request, Form $form ): void {
		foreach ( $this->checks as $check ) {
			if ( $check instanceof Reserving_Check ) {
				$check->release( $request, $form );
			}
		}
	}
}
