<?php
/**
 * Configuración de la suite de tests de WordPress. Los datos salen de .runtime/conf/env.json,
 * que genera `rt setup` a partir de scripts/runtime.config.psd1.
 */

$rt_env = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/.runtime/conf/env.json' ), true );

define( 'ABSPATH', $rt_env['paths']['www'] . '/' );

// Base de datos exclusiva de los tests: la suite borra sus tablas en cada ejecución.
define( 'DB_NAME', $rt_env['db']['testName'] );
define( 'DB_USER', $rt_env['db']['user'] );
define( 'DB_PASSWORD', $rt_env['db']['password'] );
define( 'DB_HOST', $rt_env['db']['host'] );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Tests' );
define( 'WP_PHP_BINARY', $rt_env['paths']['php'] );
define( 'WP_DEBUG', true );
