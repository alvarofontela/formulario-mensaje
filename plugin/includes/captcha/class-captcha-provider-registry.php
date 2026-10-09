<?php
/**
 * Registro de proveedores de captcha.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * Proveedores de captcha disponibles. Los del plugin y los de terceros se registran por la misma vía.
 */
final class Captcha_Provider_Registry {

	/**
	 * Proveedores registrados, por identificador.
	 *
	 * @var array<string, Captcha_Provider>
	 */
	private array $providers = array();

	/**
	 * Registra un proveedor; si ya existe uno con el mismo identificador, lo sustituye.
	 *
	 * @param Captcha_Provider $provider Proveedor de captcha.
	 */
	public function register( Captcha_Provider $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Devuelve un proveedor.
	 *
	 * @param string $id Identificador del proveedor.
	 */
	public function get( string $id ): ?Captcha_Provider {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Todos los proveedores registrados, en orden de registro.
	 *
	 * @return Captcha_Provider[]
	 */
	public function all(): array {
		return array_values( $this->providers );
	}
}
