<?php
/**
 * Script y estilos de la aplicación de administración.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Admin;

use Formulario_Mensaje\Lifecycle\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Encola la aplicación de React, solo en las pantallas del plugin.
 */
final class Admin_Assets {

	/**
	 * Handle del script y de los estilos.
	 */
	private const HANDLE = 'formulario-mensaje-admin';

	/**
	 * Constructor.
	 *
	 * @param Admin_Menu   $menu         Pantallas del plugin.
	 * @param Admin_Data   $data         Datos iniciales de la aplicación.
	 * @param Translations $translations Traducciones del plugin.
	 * @param string       $plugin_file  Ruta del fichero principal del plugin.
	 */
	public function __construct(
		private readonly Admin_Menu $menu,
		private readonly Admin_Data $data,
		private readonly Translations $translations,
		private readonly string $plugin_file
	) {}

	/**
	 * Encola la aplicación si la pantalla actual es del plugin.
	 *
	 * @param string $hook_suffix Identificador de la pantalla actual.
	 */
	public function enqueue( string $hook_suffix ): void {
		$view       = $this->menu->view_for( $hook_suffix );
		$asset_file = dirname( $this->plugin_file ) . '/build/admin/index.asset.php';

		// Sin compilar (un clon recién hecho) no hay aplicación que cargar.
		if ( null === $view || ! is_file( $asset_file ) ) {
			return;
		}

		// Fichero generado por la compilación, dentro del propio plugin: devuelve dependencias y versión.
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/admin/index.js', $this->plugin_file ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'build/admin/index.css', $this->plugin_file ),
			array( 'wp-components' ),
			$asset['version']
		);

		wp_set_script_translations( self::HANDLE, Translations::DOMAIN, $this->translations->directory() );

		wp_add_inline_script(
			self::HANDLE,
			'window.formularioMensajeAdmin = ' . wp_json_encode( $this->data->get( $view ) ) . ';',
			'before'
		);
	}
}
