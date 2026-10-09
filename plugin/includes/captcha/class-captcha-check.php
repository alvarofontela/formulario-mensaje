<?php
/**
 * Comprobación antispam: captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use Formulario_Mensaje\Antispam\Spam_Check;
use Formulario_Mensaje\Forms\Form;
use Formulario_Mensaje\Submissions\Submission_Channel;
use Formulario_Mensaje\Submissions\Submission_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exige, en los formularios con captcha, un testigo que el servicio dé por bueno.
 *
 * Si el servicio no responde, el envío se rechaza: aceptar sin comprobar dejaría el captcha sin
 * efecto justo cuando alguien consiga que la comprobación falle.
 */
final class Captcha_Check implements Spam_Check {

	/**
	 * Constructor.
	 *
	 * @param Captcha_Service $captcha Captcha de los formularios.
	 */
	public function __construct( private readonly Captcha_Service $captcha ) {}

	/**
	 * Comprueba el captcha de un envío.
	 *
	 * @param Submission_Request $request Envío.
	 * @param Form               $form    Formulario.
	 * @return WP_Error|null Null si el formulario no lleva captcha o el testigo es válido.
	 */
	public function check( Submission_Request $request, Form $form ): ?WP_Error {
		if ( null === $this->captcha->client_config( $form ) ) {
			return null;
		}

		// El captcha se resuelve con JavaScript: el POST sin él no puede traer testigo, y aceptarlo sería la forma de saltarse el captcha.
		if ( Submission_Channel::Rest !== $request->channel ) {
			return new WP_Error(
				'formulario_mensaje_captcha_requires_js',
				__( 'This form needs JavaScript to be sent. Enable it in your browser and try again.', 'formulario-mensaje' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $request->captcha_token ) {
			return new WP_Error(
				'formulario_mensaje_captcha_missing',
				__( 'Complete the security check before sending the form.', 'formulario-mensaje' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->captcha->verify( $request->captcha_token, $request->client_ip );

		if ( true === $result ) {
			return null;
		}

		if ( is_wp_error( $result ) && Siteverify_Client::ERROR_UNAVAILABLE === $result->get_error_code() ) {
			return new WP_Error(
				'formulario_mensaje_captcha_unavailable',
				__( 'The security check could not be completed. Please try again in a moment.', 'formulario-mensaje' ),
				array( 'status' => 503 )
			);
		}

		return new WP_Error(
			'formulario_mensaje_captcha_failed',
			__( 'The security check failed. Please try again.', 'formulario-mensaje' ),
			array( 'status' => 400 )
		);
	}
}
