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
	 * @param Trusted_Proxy_Mode $trusted_proxy_mode       De dónde se toma la IP del visitante.
	 * @param string[]           $trusted_proxies          IP o rangos CIDR del proxy propio.
	 * @param string[]           $allowed_redirect_hosts   Dominios externos a los que un formulario puede redirigir.
	 * @param bool               $accept_no_js             Si se aceptan envíos como POST normal, sin JavaScript ni token antispam.
	 * @param bool               $delete_data_on_uninstall Si al desinstalar se borran formularios, envíos y ajustes.
	 */
	public function __construct(
		public readonly Trusted_Proxy_Mode $trusted_proxy_mode = Trusted_Proxy_Mode::None,
		public readonly array $trusted_proxies = array(),
		public readonly array $allowed_redirect_hosts = array(),
		public readonly bool $accept_no_js = true,
		public readonly bool $delete_data_on_uninstall = false
	) {}

	/**
	 * Representación para guardar en la base de datos o enviar por REST.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'trusted_proxy_mode'       => $this->trusted_proxy_mode->value,
			'trusted_proxies'          => $this->trusted_proxies,
			'allowed_redirect_hosts'   => $this->allowed_redirect_hosts,
			'accept_no_js'             => $this->accept_no_js,
			'delete_data_on_uninstall' => $this->delete_data_on_uninstall,
		);
	}
}
