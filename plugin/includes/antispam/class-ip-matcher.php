<?php
/**
 * Comparación de IP con rangos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

defined( 'ABSPATH' ) || exit;

/**
 * Valida direcciones IP y rangos CIDR (IPv4 e IPv6) y comprueba si una IP pertenece a un rango.
 */
final class Ip_Matcher {

	/**
	 * Indica si un texto es una dirección IP válida.
	 *
	 * @param string $ip Texto a comprobar.
	 */
	public function is_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Red por la que se cuenta a un cliente, para que sus direcciones no pasen por clientes distintos.
	 *
	 * Un cliente IPv6 dispone de un prefijo entero (lo habitual es un /64) y elige el resto de la
	 * dirección: se le cuenta por ese prefijo. Una IPv4, también la escrita en formato IPv6
	 * (`::ffff:203.0.113.7`), se cuenta por la dirección.
	 *
	 * @param string $ip Dirección IP.
	 * @return string La dirección IPv4, o el prefijo IPv6 con su `/64`; cadena vacía si no es una IP.
	 */
	public function network( string $ip ): string {
		if ( ! $this->is_ip( $ip ) ) {
			return '';
		}

		$address = (string) inet_pton( $ip );

		if ( 4 === strlen( $address ) ) {
			return (string) inet_ntop( $address );
		}

		if ( str_starts_with( $address, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $address, 12 ) );
		}

		return inet_ntop( substr( $address, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Normaliza una IP o un rango CIDR.
	 *
	 * @param string $range IP (`203.0.113.7`) o rango (`203.0.113.0/24`, `2001:db8::/32`).
	 * @return string El mismo valor sin espacios, o cadena vacía si no es válido.
	 */
	public function normalize_range( string $range ): string {
		$range = trim( $range );
		$parts = explode( '/', $range );

		if ( ! $this->is_ip( $parts[0] ) || count( $parts ) > 2 ) {
			return '';
		}

		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}

		$max_bits = str_contains( $parts[0], ':' ) ? 128 : 32;

		if ( 1 !== preg_match( '/^\d{1,3}$/', $parts[1] ) || (int) $parts[1] > $max_bits ) {
			return '';
		}

		return $parts[0] . '/' . (int) $parts[1];
	}

	/**
	 * Indica si una IP pertenece a alguno de los rangos.
	 *
	 * @param string   $ip     Dirección IP.
	 * @param string[] $ranges IP o rangos CIDR.
	 */
	public function matches( string $ip, array $ranges ): bool {
		if ( ! $this->is_ip( $ip ) ) {
			return false;
		}

		$address = (string) inet_pton( $ip );

		foreach ( $ranges as $range ) {
			$range = is_string( $range ) ? $this->normalize_range( $range ) : '';

			if ( '' !== $range && $this->in_range( $address, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Comprueba si una dirección en binario pertenece a un rango ya normalizado.
	 *
	 * @param string $address Dirección en formato binario de inet_pton().
	 * @param string $range   IP o rango CIDR normalizado.
	 */
	private function in_range( string $address, string $range ): bool {
		$parts  = explode( '/', $range );
		$subnet = (string) inet_pton( $parts[0] );

		// Una IPv4 nunca pertenece a un rango IPv6, ni al revés.
		if ( strlen( $subnet ) !== strlen( $address ) ) {
			return false;
		}

		$bits       = isset( $parts[1] ) ? (int) $parts[1] : strlen( $address ) * 8;
		$full_bytes = intdiv( $bits, 8 );
		$rest_bits  = $bits % 8;

		if ( 0 !== strncmp( $address, $subnet, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $rest_bits ) {
			return true;
		}

		$mask = 0xFF << ( 8 - $rest_bits ) & 0xFF;

		return ( ord( $address[ $full_bytes ] ) & $mask ) === ( ord( $subnet[ $full_bytes ] ) & $mask );
	}
}
