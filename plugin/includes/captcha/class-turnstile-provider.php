<?php
/**
 * Captcha: Cloudflare Turnstile.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Comprueba los testigos de Cloudflare Turnstile.
 */
final class Turnstile_Provider implements Captcha_Provider {

	public const ID = 'turnstile';

	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- No es un recurso servido desde fuera: es la dirección de la API de Turnstile a la que el servidor envía el testigo para verificarlo (wp_safe_remote_post), y solo si el administrador ha configurado este servicio.
	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

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
		return __( 'Cloudflare Turnstile', 'formulario-mensaje' );
	}

	/**
	 * Comprueba el testigo que ha enviado el navegador.
	 *
	 * @param string         $token     Testigo recibido con el envío.
	 * @param string         $secret    Clave secreta del sitio en el servicio.
	 * @param string         $remote_ip IP del visitante, o cadena vacía.
	 * @param Captcha_Config $config    Ajustes del captcha.
	 * @return true|WP_Error
	 */
	public function verify( string $token, string $secret, string $remote_ip, Captcha_Config $config ): bool|WP_Error {
		$data = $this->client->verify( self::VERIFY_URL, $secret, $token, $remote_ip );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return true === $data['success'] ? true : new WP_Error( 'formulario_mensaje_captcha_rejected', $this->client->error_codes( $data ) );
	}
}
