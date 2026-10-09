<?php
/**
 * Saneado de los ajustes del captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte datos sin validar (de una petición o de la base de datos) en ajustes del captcha válidos.
 */
final class Captcha_Config_Sanitizer {

	/**
	 * Constructor.
	 *
	 * @param Captcha_Provider_Registry $providers Proveedores de captcha.
	 */
	public function __construct( private readonly Captcha_Provider_Registry $providers ) {}

	/**
	 * Sanea los ajustes.
	 *
	 * @param mixed $raw Datos sin validar.
	 */
	public function sanitize( mixed $raw ): Captcha_Config {
		$raw      = is_array( $raw ) ? $raw : array();
		$provider = is_string( $raw['provider'] ?? null ) ? $raw['provider'] : '';
		$site_key = is_string( $raw['site_key'] ?? null ) ? trim( $raw['site_key'] ) : '';

		// La clave pública acaba en un atributo HTML y en la dirección de un script: solo los caracteres que usan los servicios.
		$valid_key = 1 === preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $site_key );
		$threshold = is_numeric( $raw['score_threshold'] ?? null ) ? (float) $raw['score_threshold'] : Captcha_Config::DEFAULT_SCORE_THRESHOLD;

		return new Captcha_Config(
			// Un proveedor que ya no está registrado equivale a no usar captcha.
			null === $this->providers->get( $provider ) ? '' : $provider,
			$valid_key ? $site_key : '',
			round( max( 0.1, min( 0.9, $threshold ) ), 1 )
		);
	}
}
