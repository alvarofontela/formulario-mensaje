<?php
/**
 * Destinos de redirección permitidos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Submissions;

use Formulario_Mensaje\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Decide a qué direcciones puede redirigir un formulario tras un envío.
 *
 * Las del propio sitio valen siempre. Un dominio externo solo si está en la lista de los ajustes, que
 * gestiona quien tiene la capability de ajustes: así quien solo edita formularios no puede convertir
 * el sitio en un redirector abierto.
 */
final class Redirect_Policy {

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings Ajustes del plugin.
	 */
	public function __construct( private readonly Settings_Repository $settings ) {}

	/**
	 * Indica si se puede redirigir a una dirección.
	 *
	 * @param string $url Dirección absoluta (http o https) o ruta del propio sitio que empieza por `/`.
	 */
	public function is_allowed( string $url ): bool {
		if ( '' === $url || 1 === preg_match( '/[\s\\\\]/', $url ) ) {
			return false;
		}

		// Ruta del propio sitio. `//ejemplo.com` es una dirección externa sin esquema, no una ruta.
		if ( str_starts_with( $url, '/' ) ) {
			return ! str_starts_with( $url, '//' );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ), $this->allowed_hosts(), true );
	}

	/**
	 * Dominios a los que se puede redirigir: el del sitio y los de la lista de los ajustes.
	 *
	 * @return string[] En minúsculas.
	 */
	public function allowed_hosts(): array {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$hosts     = $this->settings->get()->allowed_redirect_hosts;

		if ( is_string( $site_host ) && '' !== $site_host ) {
			$hosts[] = strtolower( $site_host );
		}

		return array_values( array_unique( $hosts ) );
	}
}
