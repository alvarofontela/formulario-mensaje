<?php
/**
 * Rangos de IP de Cloudflare.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

defined( 'ABSPATH' ) || exit;

/**
 * Rangos desde los que Cloudflare conecta con el servidor de origen.
 *
 * La cabecera `CF-Connecting-IP` solo es de fiar si la conexión llega desde uno de ellos.
 */
final class Cloudflare_Ranges {

	/**
	 * Rangos publicados en https://www.cloudflare.com/ips/ (cambian muy rara vez).
	 */
	private const RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Rangos de Cloudflare.
	 *
	 * @return string[]
	 */
	public function all(): array {
		/**
		 * Filtra los rangos de IP que se consideran de Cloudflare.
		 *
		 * Sirve para actualizarlos sin esperar a una versión nueva del plugin.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $ranges Rangos CIDR, IPv4 e IPv6.
		 */
		$ranges = apply_filters( 'formulario_mensaje_cloudflare_ip_ranges', self::RANGES );

		return is_array( $ranges ) ? array_values( array_filter( $ranges, 'is_string' ) ) : self::RANGES;
	}
}
