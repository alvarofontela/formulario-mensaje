<?php
/**
 * Captcha: Google reCAPTCHA v3.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Comprueba los testigos de Google reCAPTCHA v3.
 *
 * Este servicio no aprueba ni suspende: puntúa de 0 a 1 lo probable que es que haya una persona. El
 * envío se acepta si la puntuación llega al umbral de los ajustes.
 */
final class Recaptcha_Provider implements Captcha_Provider {

	public const ID = 'recaptcha';

	/**
	 * Acción con la que el navegador pide el testigo. Un testigo pedido para otra acción no vale.
	 */
	public const ACTION = 'formulario_mensaje_submit';

	private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Constructor.
	 *
	 * @param Siteverify_Client $client Cliente de las rutas de verificación.
	 */
	public function __construct( private readonly Siteverify_Client $client ) {}

	/**
	 * Identificador del proveedor.
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * Nombre del proveedor.
	 */
	public function get_label(): string {
		return __( 'Google reCAPTCHA v3', 'formulario-mensaje' );
	}

	/**
	 * Comprueba el testigo que ha enviado el navegador.
	 *
	 * @param string         $token     Testigo recibido con el envío.
	 * @param string         $secret    Clave secreta del sitio en el servicio.
	 * @param string         $remote_ip IP del visitante, o cadena vacía.
	 * @param Captcha_Config $config    Ajustes del captcha, con el umbral de puntuación.
	 * @return true|WP_Error
	 */
	public function verify( string $token, string $secret, string $remote_ip, Captcha_Config $config ): bool|WP_Error {
		$data = $this->client->verify( self::VERIFY_URL, $secret, $token, $remote_ip );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( true !== $data['success'] ) {
			return new WP_Error( 'formulario_mensaje_captcha_rejected', $this->client->error_codes( $data ) );
		}

		// Un testigo válido pero conseguido en otra página del sitio, para otra cosa, no sirve aquí.
		if ( self::ACTION !== ( $data['action'] ?? '' ) ) {
			return new WP_Error( 'formulario_mensaje_captcha_rejected', 'action-mismatch' );
		}

		$score = is_numeric( $data['score'] ?? null ) ? (float) $data['score'] : 0.0;

		if ( $score < $config->score_threshold ) {
			return new WP_Error( 'formulario_mensaje_captcha_rejected', sprintf( 'score %.1f', $score ) );
		}

		return true;
	}
}
