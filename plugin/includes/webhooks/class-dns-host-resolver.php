<?php
/**
 * Resolución de nombres con el DNS del servidor.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve un nombre de servidor con la función de PHP, igual que hace el núcleo al validar una dirección.
 */
final class Dns_Host_Resolver implements Host_Resolver {

	/**
	 * Dirección IPv4 de un nombre de servidor.
	 *
	 * @param string $host Nombre de servidor.
	 * @return string Dirección IPv4, o cadena vacía si el nombre no se puede resolver.
	 */
	public function resolve( string $host ): string {
		$ip = gethostbyname( $host );

		// Cuando no puede resolverlo, gethostbyname() devuelve el nombre tal cual.
		return $ip !== $host && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '';
	}
}
