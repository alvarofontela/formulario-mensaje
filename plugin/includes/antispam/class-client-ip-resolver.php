<?php
/**
 * IP del visitante.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Antispam;

use Formulario_Mensaje\Settings\Settings_Repository;
use Formulario_Mensaje\Settings\Trusted_Proxy_Mode;
use Formulario_Mensaje\Support\Server_Vars;

defined( 'ABSPATH' ) || exit;

/**
 * Determina la IP del visitante.
 *
 * Por defecto es la de la conexión (`REMOTE_ADDR`). Las cabeceras que la sustituyen las puede escribir
 * cualquiera, así que solo se aceptan cuando la conexión llega desde un proxy declarado de confianza
 * en los ajustes.
 */
final class Client_Ip_Resolver {

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings   Ajustes del plugin.
	 * @param Server_Vars         $server     Variables del servidor.
	 * @param Ip_Matcher          $matcher    Comparación de IP con rangos.
	 * @param Cloudflare_Ranges   $cloudflare Rangos de Cloudflare.
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly Server_Vars $server,
		private readonly Ip_Matcher $matcher,
		private readonly Cloudflare_Ranges $cloudflare
	) {}

	/**
	 * IP del visitante.
	 *
	 * @return string Dirección IP válida, o cadena vacía si el servidor no entrega ninguna (por ejemplo, si
	 *                recibe las conexiones por un socket Unix). El límite de envíos cuenta ese caso aparte.
	 */
	public function resolve(): string {
		$remote = $this->server->get( 'REMOTE_ADDR' );

		if ( ! $this->matcher->is_ip( $remote ) ) {
			return '';
		}

		$settings = $this->settings->get();

		if ( Trusted_Proxy_Mode::Cloudflare === $settings->trusted_proxy_mode && $this->matcher->matches( $remote, $this->cloudflare->all() ) ) {
			$forwarded = $this->server->get( 'HTTP_CF_CONNECTING_IP' );

			return $this->matcher->is_ip( $forwarded ) ? $forwarded : $remote;
		}

		if ( Trusted_Proxy_Mode::Custom === $settings->trusted_proxy_mode && $this->matcher->matches( $remote, $settings->trusted_proxies ) ) {
			return $this->from_forwarded_for( $this->server->get( 'HTTP_X_FORWARDED_FOR' ), $settings->trusted_proxies ) ?? $remote;
		}

		return $remote;
	}

	/**
	 * Extrae la IP del visitante de `X-Forwarded-For`.
	 *
	 * La cabecera es una lista a la que cada proxy añade por la derecha la dirección de quien le
	 * conecta. Se recorre de derecha a izquierda saltando los proxies de confianza: la primera
	 * dirección que no es uno de ellos es la del visitante. Lo que haya a su izquierda lo pudo
	 * escribir él mismo y no se tiene en cuenta.
	 *
	 * @param string   $header  Valor de la cabecera.
	 * @param string[] $proxies IP o rangos de los proxies de confianza.
	 */
	private function from_forwarded_for( string $header, array $proxies ): ?string {
		$addresses = array_reverse( array_map( 'trim', explode( ',', $header ) ) );

		foreach ( $addresses as $address ) {
			if ( ! $this->matcher->is_ip( $address ) ) {
				return null;
			}

			if ( ! $this->matcher->matches( $address, $proxies ) ) {
				return $address;
			}
		}

		return null;
	}
}
