<?php
/**
 * Ajustes generales del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Valor inmutable con los ajustes generales.
 */
final class Settings {

	/**
	 * Constructor.
	 *
	 * @param Trusted_Proxy_Mode $trusted_proxy_mode     De dónde se toma la IP del visitante.
	 * @param string[]           $trusted_proxies        IP o rangos CIDR del proxy propio.
	 * @param string[]           $allowed_redirect_hosts Dominios externos a los que un formulario puede redirigir.
	 */
	public function __construct(
		public readonly Trusted_Proxy_Mode $trusted_proxy_mode = Trusted_Proxy_Mode::None,
		public readonly array $trusted_proxies = array(),
		public readonly array $allowed_redirect_hosts = array()
	) {}

	/**
	 * Representación para guardar en la base de datos o enviar por REST.
	 *
	 * @return array{trusted_proxy_mode: string, trusted_proxies: string[], allowed_redirect_hosts: string[]}
	 */
	public function to_array(): array {
		return array(
			'trusted_proxy_mode'     => $this->trusted_proxy_mode->value,
			'trusted_proxies'        => $this->trusted_proxies,
			'allowed_redirect_hosts' => $this->allowed_redirect_hosts,
		);
	}
}
