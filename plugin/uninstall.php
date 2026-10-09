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

/*
 * Se retiran las capabilities y la marca de versión. Los formularios y los ajustes se conservan: su
 * borrado será opcional y se decidirá con un ajuste del plugin.
 */
( new Formulario_Mensaje\Lifecycle\Capabilities() )->remove_from_all_roles();

delete_option( Formulario_Mensaje\Lifecycle\Installer::VERSION_OPTION );
