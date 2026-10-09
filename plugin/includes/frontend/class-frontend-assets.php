<?php
/**
 * Estilos y script del formulario en el frontend.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registra la hoja de estilos y el módulo de JavaScript del formulario, y los encola solo donde hay uno.
 */
final class Frontend_Assets {

	/**
	 * Handle de la hoja de estilos. El bloque la declara en su `block.json` para el editor.
	 */
	public const STYLE_HANDLE = 'formulario-mensaje-form';

	/**
	 * Identificador del módulo de JavaScript.
	 */
	public const MODULE_ID = 'formulario-mensaje-form';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Ruta del fichero principal del plugin.
	 * @param string $version     Versión del plugin, para invalidar cachés.
	 */
	public function __construct(
		private readonly string $plugin_file,
		private readonly string $version
	) {}

	/**
	 * Registra los ficheros, sin encolarlos.
	 */
	public function register(): void {
		wp_register_style( self::STYLE_HANDLE, $this->style_url(), array(), $this->version );
		wp_register_script_module( self::MODULE_ID, plugins_url( 'assets/js/form.js', $this->plugin_file ), array(), $this->version );
	}

	/**
	 * Encola los ficheros. Se llama al pintar un formulario; repetirlo no tiene efecto.
	 */
	public function enqueue(): void {
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script_module( self::MODULE_ID );
	}

	/**
	 * Dirección de la hoja de estilos, para la vista previa del constructor.
	 */
	public function style_url(): string {
		return plugins_url( 'assets/css/form.css', $this->plugin_file );
	}

	/**
	 * Versión de los ficheros.
	 */
	public function version(): string {
		return $this->version;
	}
}
