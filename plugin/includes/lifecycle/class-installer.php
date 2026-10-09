<?php
/**
 * Instalación y actualización de los datos del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Deja los datos del plugin en el estado que espera la versión instalada.
 *
 * Todo lo que hace se puede repetir sin daño: se ejecuta al activar y cada vez que cambia la versión.
 */
final class Installer {

	/**
	 * Opción que guarda la versión cuyos datos están instalados.
	 */
	public const VERSION_OPTION = 'formulario_mensaje_version';

	/**
	 * Constructor.
	 *
	 * @param Capabilities $capabilities Capabilities del plugin.
	 * @param string       $version      Versión del código.
	 */
	public function __construct(
		private readonly Capabilities $capabilities,
		private readonly string $version
	) {}

	/**
	 * Instala o actualiza los datos del plugin.
	 */
	public function install(): void {
		$this->capabilities->grant_to_administrator();

		update_option( self::VERSION_OPTION, $this->version );
	}

	/**
	 * Ejecuta la instalación si los datos guardados son de otra versión del código.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== $this->version ) {
			$this->install();
		}
	}
}
