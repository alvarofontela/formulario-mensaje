<?php
/**
 * Esquema de las tablas del plugin.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Crea, actualiza y borra las tablas propias del plugin, y lleva la versión del esquema.
 *
 * Los cambios de esquema se hacen aquí, subiendo la versión: `dbDelta()` compara lo que hay con lo
 * que se le pide y aplica la diferencia, así que instalar se puede repetir sin daño.
 */
final class Schema {

	/**
	 * Versión del esquema que crea este código.
	 */
	public const VERSION = 1;

	/**
	 * Opción que guarda la versión del esquema instalado.
	 */
	public const VERSION_OPTION = 'formulario_mensaje_db_version';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Conexión de WordPress a la base de datos.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * Nombre de la tabla de envíos.
	 */
	public function submissions_table(): string {
		$wpdb = $this->wpdb;

		return $wpdb->prefix . 'formulario_mensaje_submissions';
	}

	/**
	 * Nombre de la tabla del registro de entregas: el resultado de cada acción sobre cada envío.
	 */
	public function deliveries_table(): string {
		$wpdb = $this->wpdb;

		return $wpdb->prefix . 'formulario_mensaje_deliveries';
	}

	/**
	 * Nombre de la tabla de los contadores del límite de envíos por IP.
	 */
	public function rate_limits_table(): string {
		$wpdb = $this->wpdb;

		return $wpdb->prefix . 'formulario_mensaje_rate_limits';
	}

	/**
	 * Crea las tablas o las lleva a la versión actual.
	 */
	public function install(): void {
		$wpdb = $this->wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate     = $wpdb->get_charset_collate();
		$submissions = $this->submissions_table();
		$deliveries  = $this->deliveries_table();
		$rate_limits = $this->rate_limits_table();

		/*
		 * `search_text` reúne la referencia y los valores para poder buscar sin leer el JSON de
		 * `entries`. `failed_actions` cuenta las entregas fallidas del envío para filtrar sin unir tablas.
		 */
		dbDelta(
			"CREATE TABLE {$submissions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				reference varchar(20) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				page_url text NOT NULL,
				ip varchar(45) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				entries longtext NOT NULL,
				search_text longtext NOT NULL,
				failed_actions smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY form_created (form_id,created_at),
				KEY created_at (created_at),
				KEY failed_actions (failed_actions)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$deliveries} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
				form_id bigint(20) unsigned NOT NULL,
				action_id varchar(32) NOT NULL DEFAULT '',
				action_type varchar(32) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 1,
				error text NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY submission_id (submission_id),
				KEY status (status),
				KEY form_id (form_id)
			) {$collate};"
		);

		// `bucket` es un resumen de formulario, vía e IP: la IP no se guarda.
		dbDelta(
			"CREATE TABLE {$rate_limits} (
				bucket varchar(64) NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (bucket),
				KEY expires_at (expires_at)
			) {$collate};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Indica si las tablas instaladas son de otra versión del esquema.
	 */
	public function needs_install(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) !== self::VERSION;
	}

	/**
	 * Borra las tablas y la marca de versión. Solo se usa al desinstalar, si el usuario lo ha pedido.
	 */
	public function drop(): void {
		$wpdb = $this->wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Tablas propias; los nombres salen del prefijo de WordPress y van por el marcador %i.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->submissions_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->deliveries_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->rate_limits_table() ) );
		// phpcs:enable

		delete_option( self::VERSION_OPTION );
	}
}
