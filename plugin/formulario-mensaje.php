<?php
/**
 * Plugin Name:       Formulario Mensaje
 * Description:       Lightweight, auditable form builder: forms with their own fields, email notifications, webhooks and built-in spam protection.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Álvaro Fontela
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formulario-mensaje
 * Domain Path:       /languages
 *
 * @package Formulario_Mensaje
 */

defined( 'ABSPATH' ) || exit;

define( 'FORMULARIO_MENSAJE_VERSION', '0.1.0' );
define( 'FORMULARIO_MENSAJE_FILE', __FILE__ );
define( 'FORMULARIO_MENSAJE_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-autoloader.php';

( new Formulario_Mensaje\Autoloader( __DIR__ . '/includes' ) )->register();

/*
 * Activación: se registra en el nivel superior porque WordPress carga el plugin y dispara el hook
 * en la misma petición, antes de `plugins_loaded`. Hace su propia composición, sin contenedor.
 */
register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		$installer = new Formulario_Mensaje\Lifecycle\Installer( new Formulario_Mensaje\Lifecycle\Capabilities(), FORMULARIO_MENSAJE_VERSION );

		( new Formulario_Mensaje\Lifecycle\Activator( $installer ) )->activate( $network_wide );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		( new Formulario_Mensaje\Plugin( new Formulario_Mensaje\Container() ) )->boot();
	}
);
