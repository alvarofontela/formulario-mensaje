<?php
/**
 * Proveedor de la base de datos.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Database;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Declara la conexión a la base de datos y el esquema de las tablas del plugin.
 */
final class Database_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		/*
		 * Único punto del plugin que toca la variable global de WordPress: los repositorios reciben
		 * la conexión por el constructor.
		 */
		$container->set(
			wpdb::class,
			static function (): wpdb {
				global $wpdb;

				return $wpdb;
			}
		);

		$container->set( Schema::class, static fn ( Container $c ): Schema => new Schema( $c->get( wpdb::class ) ) );
	}
}
