<?php
/**
 * Saneado de los ajustes generales.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Settings;

use Formulario_Mensaje\Antispam\Ip_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte datos sin validar (de la base de datos o de una petición) en unos ajustes válidos.
 */
final class Settings_Sanitizer {

	/**
	 * Máximo de entradas que se aceptan en cada lista.
	 */
	private const MAX_ITEMS = 100;

	/**
	 * Constructor.
	 *
	 * @param Ip_Matcher $ip_matcher Validador de IP y rangos.
	 */
	public function __construct( private readonly Ip_Matcher $ip_matcher ) {}

	/**
	 * Sanea unos ajustes.
	 *
	 * @param mixed $raw Datos sin validar.
	 */
	public function sanitize( mixed $raw ): Settings {
		$raw  = is_array( $raw ) ? $raw : array();
		$mode = Trusted_Proxy_Mode::tryFrom( is_string( $raw['trusted_proxy_mode'] ?? null ) ? $raw['trusted_proxy_mode'] : '' ) ?? Trusted_Proxy_Mode::None;

		return new Settings(
			$mode,
			$this->sanitize_list( $raw['trusted_proxies'] ?? array(), array( $this->ip_matcher, 'normalize_range' ) ),
			$this->sanitize_list( $raw['allowed_redirect_hosts'] ?? array(), array( $this, 'normalize_host' ) ),
			// Activado salvo que se haya desactivado expresamente: es el comportamiento acordado.
			! array_key_exists( 'accept_no_js', $raw ) || ! empty( $raw['accept_no_js'] ),
			! empty( $raw['delete_data_on_uninstall'] )
		);
	}

	/**
	 * Normaliza un nombre de dominio; acepta también una URL y se queda con su host.
	 *
	 * @param string $value Dominio o URL.
	 * @return string Dominio en minúsculas, o cadena vacía si no es válido.
	 */
	public function normalize_host( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( str_contains( $value, '/' ) ) {
			$host  = wp_parse_url( str_contains( $value, '://' ) ? $value : 'https://' . $value, PHP_URL_HOST );
			$value = is_string( $host ) ? $host : '';
		}

		if ( 1 !== preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanea una lista de textos con un normalizador, descartando los no válidos y los repetidos.
	 *
	 * @param mixed                    $raw        Lista sin validar, o texto con un valor por línea.
	 * @param callable(string): string $normalizer Devuelve el valor normalizado o cadena vacía.
	 * @return string[]
	 */
	private function sanitize_list( mixed $raw, callable $normalizer ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = $normalizer( sanitize_text_field( $item ) );

			if ( '' !== $item ) {
				$clean[ $item ] = true;
			}

			if ( count( $clean ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		return array_keys( $clean );
	}
}
