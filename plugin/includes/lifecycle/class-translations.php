<?php
/**
 * Traducciones del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Indica a WordPress dónde están las traducciones que el plugin trae consigo.
 *
 * El plugin no se distribuye por WordPress.org, así que sus traducciones no llegan a `wp-content/languages`.
 */
final class Translations {

	/**
	 * Text domain del plugin.
	 */
	public const DOMAIN = 'formulario-mensaje';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_basename Ruta del fichero principal relativa a la carpeta de plugins.
	 */
	public function __construct( private readonly string $plugin_basename ) {}

	/**
	 * Registra la carpeta de traducciones; los ficheros se cargan cuando se pide el primer texto.
	 */
	public function register(): void {
		load_plugin_textdomain( self::DOMAIN, false, dirname( $this->plugin_basename ) . '/languages' );
	}

	/**
	 * Ruta absoluta de la carpeta de traducciones, para los textos de los scripts.
	 */
	public function directory(): string {
		return WP_PLUGIN_DIR . '/' . dirname( $this->plugin_basename ) . '/languages';
	}
}
