<?php
/**
 * Anonimizado de direcciones IP.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Recorta una dirección IP para que deje de identificar a una persona y siga sirviendo de orientación.
 */
final class Ip_Anonymizer {

	/**
	 * Anonimiza una IP: pone a cero el último octeto de una IPv4 y los últimos 80 bits de una IPv6.
	 *
	 * @param string $ip Dirección IP.
	 * @return string IP anonimizada, o cadena vacía si no era una IP válida.
	 */
	public function anonymize( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$binary = (string) inet_pton( $ip );
		$keep   = 4 === strlen( $binary ) ? 3 : 6;
		$masked = inet_ntop( substr( $binary, 0, $keep ) . str_repeat( "\0", strlen( $binary ) - $keep ) );

		return false === $masked ? '' : $masked;
	}
}
