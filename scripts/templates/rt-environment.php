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

/*
 * Receptor de webhooks por canal, para los tests que corren en paralelo (tests/matriz): cada test
 * usa su propio canal, así que ni comparten lo recibido ni se pisan al guardarlo. El código de la
 * respuesta va en la propia dirección, no en un ajuste común.
 *
 *   POST|PUT|PATCH /wp-json/rt/v1/sink/<canal>[?status=500]   guarda la petición y responde con ese código
 *   GET            /wp-json/rt/v1/sink/<canal>                devuelve las peticiones guardadas de ese canal
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'rt/v1',
			'/sink/(?P<channel>[a-z0-9-]{1,60})',
			array(
				array(
					'methods'             => 'POST, PUT, PATCH',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						$option = 'rt_sink_' . $request['channel'];

						wp_cache_delete( $option, 'options' );

						$received   = get_option( $option, array() );
						$received   = is_array( $received ) ? $received : array();
						$received[] = array(
							'method'  => $request->get_method(),
							'headers' => array_map( static fn ( $values ) => implode( ', ', (array) $values ), $request->get_headers() ),
							'body'    => $request->get_body(),
						);

						update_option( $option, array_slice( $received, -50 ), false );

						// El código sale de la dirección: el cuerpo de un webhook puede traer su propio «status».
						$status = (int) ( $request->get_query_params()['status'] ?? 200 );

						return new WP_REST_Response( array( 'ok' => true ), max( 200, min( 599, $status ) ) );
					},
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						$option = 'rt_sink_' . $request['channel'];

						wp_cache_delete( $option, 'options' );

						return array_values( (array) get_option( $option, array() ) );
					},
				),
			)
		);
	}
);

/*
 * Doble de los servicios de captcha para los tests: un testigo que empieza por «rt-» no se consulta
 * a Cloudflare, Google ni hCaptcha, sino que se responde aquí. «rt-valido» es bueno; cualquier otro
 * «rt-…», no. Los demás testigos siguen yendo al servicio real, para poder probarlo a mano.
 */
add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		$token = is_array( $args['body'] ?? null ) ? (string) ( $args['body']['response'] ?? '' ) : '';

		if ( ! str_contains( (string) $url, '/siteverify' ) || ! str_starts_with( $token, 'rt-' ) ) {
			return $response;
		}

		$body = 'rt-valido' === $token
			? array(
				'success' => true,
				'score'   => 0.9,
				'action'  => 'formulario_mensaje_submit',
			)
			: array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			);

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $body ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	},
	10,
	3
);

/*
 * Página de Elementor para los tests: guarda en una página los metadatos con los que Elementor la
 * trata como suya. La REST API de WordPress no deja escribir esos metadatos. Público a propósito:
 * solo existe en este entorno.
 *
 *   POST /wp-json/rt/v1/elementor-page   { post_id, elements }
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'rt/v1',
			'/elementor-page',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$post_id = (int) $request['post_id'];

					update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
					update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
					update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '0' );
					update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $request['elements'] ) ) );
					// La hoja de estilos de la página se regenera en la siguiente visita.
					delete_post_meta( $post_id, '_elementor_css' );

					return array(
						'saved'     => true,
						'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
					);
				},
			)
		);
	}
);
