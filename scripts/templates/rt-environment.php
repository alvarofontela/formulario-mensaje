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

/*
 * Receptor de webhooks para los tests: guarda lo que recibe y responde con el código que se le pida.
 * Vive en este mismo WordPress porque la HTTP API segura de WordPress, que es la que usa el plugin,
 * rechaza cualquier otra dirección local. Es público a propósito: solo existe en este entorno.
 *
 *   POST|PUT|PATCH /wp-json/rt/v1/sink   guarda la petición
 *   GET            /wp-json/rt/v1/sink   devuelve las peticiones guardadas
 *   DELETE         /wp-json/rt/v1/sink   las borra; ?status=500 fija el código de las siguientes respuestas
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'rt/v1',
			'/sink',
			array(
				array(
					'methods'             => 'POST, PUT, PATCH',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						$received   = get_option( 'rt_webhook_sink', array() );
						$received   = is_array( $received ) ? $received : array();
						$received[] = array(
							'method'  => $request->get_method(),
							'headers' => array_map( static fn ( $values ) => implode( ', ', (array) $values ), $request->get_headers() ),
							'body'    => $request->get_body(),
						);

						update_option( 'rt_webhook_sink', array_slice( $received, -50 ), false );

						return new WP_REST_Response( array( 'ok' => true ), (int) get_option( 'rt_webhook_sink_status', 200 ) );
					},
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						// Sin caché de objetos entre peticiones: se lee siempre lo último guardado.
						wp_cache_delete( 'rt_webhook_sink', 'options' );

						return array_values( (array) get_option( 'rt_webhook_sink', array() ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						update_option( 'rt_webhook_sink', array(), false );
						update_option( 'rt_webhook_sink_status', max( 200, min( 599, (int) ( $request['status'] ?? 200 ) ) ), false );

						return array( 'cleared' => true );
					},
				),
			)
		);
	}
);
