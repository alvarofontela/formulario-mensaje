<?php
/**
 * Captcha de los formularios.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

use Formulario_Mensaje\Forms\Form;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decide si un formulario lleva captcha y comprueba los testigos con el proveedor elegido.
 *
 * El captcha se configura una vez para todo el sitio; cada formulario puede renunciar a él.
 */
final class Captcha_Service {

	/**
	 * Constructor.
	 *
	 * @param Captcha_Config_Repository $config    Ajustes del captcha.
	 * @param Captcha_Provider_Registry $providers Proveedores de captcha.
	 */
	public function __construct(
		private readonly Captcha_Config_Repository $config,
		private readonly Captcha_Provider_Registry $providers
	) {}

	/**
	 * Lo que el navegador necesita para mostrar el captcha en un formulario.
	 *
	 * @param Form $form Formulario.
	 * @return array{provider: string, site_key: string}|null Null si el formulario no lleva captcha.
	 */
	public function client_config( Form $form ): ?array {
		// Lo más barato primero: un formulario que renuncia al captcha no lee los ajustes.
		if ( empty( $form->definition->setting( 'captcha_enabled', true ) ) ) {
			return null;
		}

		$config = $this->config->get();

		// A medio configurar no se activa: un formulario con captcha y sin claves no se podría enviar.
		if ( '' === $config->provider || '' === $config->site_key || '' === $this->config->secret() ) {
			return null;
		}

		return array(
			'provider' => $config->provider,
			'site_key' => $config->site_key,
		);
	}

	/**
	 * Comprueba el testigo de un envío.
	 *
	 * @param string $token     Testigo recibido con el envío.
	 * @param string $remote_ip IP del visitante, o cadena vacía.
	 * @return true|WP_Error
	 */
	public function verify( string $token, string $remote_ip ): bool|WP_Error {
		$config   = $this->config->get();
		$provider = $this->providers->get( $config->provider );

		if ( null === $provider ) {
			return new WP_Error( Siteverify_Client::ERROR_UNAVAILABLE, 'provider-missing' );
		}

		return $provider->verify( $token, $this->config->secret(), $remote_ip, $config );
	}
}
