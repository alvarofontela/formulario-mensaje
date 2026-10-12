<?php
/**
 * Proveedor de las actualizaciones.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Updates;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Conecta el plugin con el sistema de actualizaciones de WordPress, para que las versiones que se
 * publican en GitHub aparezcan en el escritorio como las de cualquier otro plugin.
 *
 * Los dos filtros los dispara WordPress solo cuando busca actualizaciones o abre la ficha de un
 * plugin: una visita a la web o a otra pantalla no construye nada de este módulo.
 */
final class Updates_Provider implements Service_Provider {

	/**
	 * Repositorio de GitHub en el que se publican las versiones. Con el host, es la cabecera `Update URI`.
	 */
	public const REPOSITORY = 'alvarofontela/formulario-mensaje';

	/**
	 * Slug del plugin: el comienzo del nombre del paquete de cada versión.
	 */
	private const SLUG = 'formulario-mensaje';

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set(
			Github_Project::class,
			static fn (): Github_Project => new Github_Project( self::REPOSITORY, self::SLUG )
		);

		$container->set(
			Release_Feed::class,
			static fn ( Container $c ): Release_Feed => new Release_Feed(
				$c->get( Github_Project::class ),
				new Release_Manifest(),
				FORMULARIO_MENSAJE_VERSION
			)
		);

		$container->set(
			Update_Checker::class,
			static fn ( Container $c ): Update_Checker => new Update_Checker(
				$c->get( Release_Feed::class ),
				$c->get( Github_Project::class ),
				plugin_basename( FORMULARIO_MENSAJE_FILE )
			)
		);

		$container->set(
			Plugin_Information::class,
			static fn ( Container $c ): Plugin_Information => new Plugin_Information(
				$c->get( Release_Feed::class ),
				$c->get( Github_Project::class ),
				FORMULARIO_MENSAJE_FILE
			)
		);

		add_filter(
			'update_plugins_' . Github_Project::HOST,
			static function ( $update, $plugin_data, $plugin_file ) use ( $container ) {
				return $container->get( Update_Checker::class )->check(
					is_array( $update ) ? $update : false,
					is_array( $plugin_data ) ? $plugin_data : array(),
					(string) $plugin_file
				);
			},
			10,
			3
		);

		add_filter(
			'plugins_api',
			static function ( $result, $action, $args ) use ( $container ) {
				return $container->get( Plugin_Information::class )->information( $result, (string) $action, $args );
			},
			10,
			3
		);
	}
}
