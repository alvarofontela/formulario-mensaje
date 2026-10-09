<?php
/**
 * Comprobación antispam: token y tiempo mínimo de relleno.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exige a los envíos por REST un token válido y emitido con la antelación mínima.
 *
 * El envío como POST normal (sin JavaScript) no puede traer token: ahí solo actúan el campo trampa y
 * el límite por IP.
 */
final class Token_Check implements Spam_Check {

	/**
	 * Constructor.
	 *
	 * @param Token_Service $tokens Emisión y verificación de tokens.
	 */
	public function __construct( private readonly Token_Service $tokens ) {}

	/**
	 * Decide si un envío puede continuar.
	 *
	 * @param Submission_Request $request Envío tal como llegó.
	 * @param Form               $form    Formulario al que se envía.
	 */
	public function check( Submission_Request $request, Form $form ): ?WP_Error {
		if ( Submission_Channel::Rest !== $request->channel ) {
			return null;
		}

		$error = $this->tokens->verify( $request->token, $form->id );

		if ( null === $error ) {
			return null;
		}

		// El código se conserva: con un token caducado el script pide otro y reintenta.
		return new WP_Error(
			$error->get_error_code(),
			__( 'Your message could not be sent. Please reload the page and try again.', 'formulario-mensaje' ),
			array( 'status' => 400 )
		);
	}
}
