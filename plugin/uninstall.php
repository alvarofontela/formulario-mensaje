<?php
/**
 * Desinstalación del plugin.
 *
 * WordPress ejecuta este fichero al borrar el plugin, sin haberlo arrancado: hace su propia
 * composición mínima.
 *
 * @package Formulario_Mensaje
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

( new Formulario_Mensaje\Autoloader( __DIR__ . '/includes' ) )->register();

global $wpdb;

( new Formulario_Mensaje\Lifecycle\Uninstaller(
	new Formulario_Mensaje\Lifecycle\Capabilities(),
	new Formulario_Mensaje\Database\Schema( $wpdb ),
	new Formulario_Mensaje\Lifecycle\Maintenance_Schedule()
) )->uninstall();
