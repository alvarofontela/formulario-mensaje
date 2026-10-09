<?php
/**
 * Comprobación antispam: campo trampa.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rechaza los envíos que rellenan el campo trampa, invisible para las personas.
 */
final class Honeypot_Check implements Spam_Check {

	/**
	 * Nombre del campo trampa en el formulario. Parece un campo corriente para que un bot lo rellene.
	 */
	public const FIELD_NAME = 'fm_website';

	/**
	 * Decide si un envío puede continuar.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 */
	public function check( Submission_Request $request, Form $form ): ?WP_Error {
		if ( '' === $request->honeypot ) {
			return null;
		}

		return new WP_Error(
			'formulario_mensaje_spam',
			__( 'Your message could not be sent. Please reload the page and try again.', 'formulario-mensaje' ),
			array( 'status' => 400 )
		);
	}
}
