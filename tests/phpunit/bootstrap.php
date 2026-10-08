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
		if ( is_file( $rt_env['paths']['pluginMain'] ) ) {
			require_once $rt_env['paths']['pluginMain'];
		}
	}
);

require $tests_dir . '/includes/bootstrap.php';
