<?php
/**
 * Plugin Name: Runtime local
 * Description: Ajustes del WordPress de .runtime. Lo copia scripts/rt.ps1 desde scripts/templates; edítalo allí.
 */

defined( 'ABSPATH' ) || exit;

// Prioridad máxima: ningún plugin puede sacar correo real de este entorno, todo acaba en Mailpit.
add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host        = defined( 'RT_SMTP_HOST' ) ? RT_SMTP_HOST : '127.0.0.1';
		$phpmailer->Port        = defined( 'RT_SMTP_PORT' ) ? RT_SMTP_PORT : 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPAutoTLS = false;
		$phpmailer->SMTPSecure  = '';
	},
	PHP_INT_MAX
);

// El plugin en desarrollo es un enlace al código fuente del proyecto: borrarlo desde WordPress
// recorrería el enlace y eliminaría ese código.
add_action(
	'delete_plugin',
	static function ( $plugin_file ) {
		$dir = wp_normalize_path( WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) );
		if ( '.' !== dirname( $plugin_file ) && wp_normalize_path( (string) realpath( $dir ) ) !== $dir ) {
			wp_die( 'Este plugin es un enlace al código fuente del proyecto y no se puede borrar desde WordPress.' );
		}
	}
);

// El remitente por defecto es wordpress@localhost, que PHPMailer rechaza por no tener TLD.
add_filter(
	'wp_mail_from',
	static function ( $from ) {
		return is_email( $from ) ? $from : 'wordpress@localhost.test';
	},
	1
);
