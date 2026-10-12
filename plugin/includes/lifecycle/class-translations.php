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
 * WordPress busca primero las de `wp-content/languages/plugins`, que es donde instala los paquetes de idioma
 * de translate.wordpress.org; las del plugin son las que se usan mientras un idioma no tenga paquete.
 */
final class Translations {

	/**
	 * Text domain del plugin.
	 */
	public const DOMAIN = 'formulario-mensaje';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Ruta del fichero principal del plugin.
	 */
	public function __construct( private readonly string $plugin_file ) {}

	/**
	 * Registra la carpeta de traducciones; los ficheros se cargan cuando se pide el primer texto.
	 */
	public function register(): void {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Sin esta llamada WordPress solo mira en wp-content/languages: el plugin trae sus traducciones para los idiomas que aún no tienen paquete en translate.wordpress.org, y un paquete instalado tiene prioridad sobre ellas.
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}

	/**
	 * Ruta absoluta de la carpeta de traducciones, para los textos de los scripts.
	 */
	public function directory(): string {
		return plugin_dir_path( $this->plugin_file ) . 'languages';
	}
}
