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
	new Formulario_Mensaje\Lifecycle\Maintenance_Schedule(),
	new Formulario_Mensaje\Queue\Queue_Schedule( new Formulario_Mensaje\Support\System_Clock() ),
	new Formulario_Mensaje\Uploads\Upload_Directory()
) )->uninstall();
