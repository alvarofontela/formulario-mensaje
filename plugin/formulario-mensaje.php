<?php
/**
 * Plugin Name:       Formulario Mensaje
 * Description:       Lightweight, auditable form builder: forms with their own fields, email notifications, webhooks and built-in spam protection.
 * Version:           0.8.0
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

define( 'FORMULARIO_MENSAJE_VERSION', '0.8.0' );
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
		global $wpdb;

		$installer = new Formulario_Mensaje\Lifecycle\Installer(
			new Formulario_Mensaje\Lifecycle\Capabilities(),
			new Formulario_Mensaje\Database\Schema( $wpdb ),
			new Formulario_Mensaje\Lifecycle\Maintenance_Schedule(),
			FORMULARIO_MENSAJE_VERSION
		);

		( new Formulario_Mensaje\Lifecycle\Activator( $installer ) )->activate( $network_wide );
	}
);

// Desactivación: la tarea diaria deja de programarse. Los datos no se tocan.
register_deactivation_hook(
	__FILE__,
	static function (): void {
		( new Formulario_Mensaje\Lifecycle\Maintenance_Schedule() )->clear();
		( new Formulario_Mensaje\Queue\Queue_Schedule( new Formulario_Mensaje\Support\System_Clock() ) )->clear();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		( new Formulario_Mensaje\Plugin( new Formulario_Mensaje\Container() ) )->boot();
	}
);
