<?php
/**
 * Arranque de PHPUnit: carga la suite de tests de WordPress y el plugin en desarrollo.
 */

$rt_root = dirname( __DIR__, 2 );

if ( ! is_file( $rt_root . '/.runtime/conf/env.json' ) ) {
	fwrite( STDERR, "Falta .runtime: ejecuta primero 'rt setup'." . PHP_EOL );
	exit( 1 );
}

require_once $rt_root . '/vendor/autoload.php';

$rt_env    = json_decode( file_get_contents( $rt_root . '/.runtime/conf/env.json' ), true );
$tests_dir = $rt_root . '/vendor/wp-phpunit/wp-phpunit';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $rt_env ) {
		// Se carga por su ruta dentro de wp-content/plugins, que es un enlace al código del proyecto,
		// y se registra su ruta real como hace WordPress con los plugins activos: así plugins_url()
		// y plugin_basename() devuelven lo mismo que en el sitio.
		$plugin_main = $rt_env['paths']['www'] . '/wp-content/plugins/' . $rt_env['slug'] . '/' . $rt_env['slug'] . '.php';

		if ( is_file( $plugin_main ) ) {
			wp_register_plugin_realpath( $plugin_main );
			require_once $plugin_main;
		}
	}
);

require $tests_dir . '/includes/bootstrap.php';

// Dobles de prueba y caso base de los tests del plugin; necesitan WP_UnitTestCase ya cargado.
require_once __DIR__ . '/support/dobles.php';
