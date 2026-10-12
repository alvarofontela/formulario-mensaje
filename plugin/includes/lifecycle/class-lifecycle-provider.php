<?php
/**
 * Proveedor del ciclo de vida.
 *
 * @package Formulario_Mensaje
 */

namespace Formulario_Mensaje\Lifecycle;

use Formulario_Mensaje\Container;
use Formulario_Mensaje\Database\Schema;
use Formulario_Mensaje\Service_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Declara las capabilities y el instalador, y actualiza los datos cuando cambia la versión del código.
 */
final class Lifecycle_Provider implements Service_Provider {

	/**
	 * Declara los servicios del módulo y registra sus hooks.
	 *
	 * @param Container $container Contenedor del plugin.
	 */
	public function register( Container $container ): void {
		$container->set( Capabilities::class, static fn (): Capabilities => new Capabilities() );

		$container->set( Maintenance_Schedule::class, static fn (): Maintenance_Schedule => new Maintenance_Schedule() );

		$container->set(
			Installer::class,
			static fn ( Container $c ): Installer => new Installer(
				$c->get( Capabilities::class ),
				$c->get( Schema::class ),
				$c->get( Maintenance_Schedule::class ),
				FORMULARIO_MENSAJE_VERSION
			)
		);

		$container->set(
			Translations::class,
			static fn (): Translations => new Translations( FORMULARIO_MENSAJE_FILE )
		);

		$container->set(
			Locale_Fallback::class,
			static fn ( Container $c ): Locale_Fallback => new Locale_Fallback( $c->get( Translations::class ), WP_LANG_DIR . '/plugins' )
		);

		// Estos dos filtros pasan por las traducciones de todo el sitio: el servicio solo se pide para las del plugin.
		add_filter(
			'load_textdomain_mofile',
			static function ( $mofile, $domain ) use ( $container ) {
				if ( Translations::DOMAIN !== $domain || ! is_string( $mofile ) ) {
					return $mofile;
				}

				return $container->get( Locale_Fallback::class )->mo_file( $mofile );
			},
			10,
			2
		);

		add_filter(
			'load_script_translation_file',
			static function ( $file, $handle, $domain ) use ( $container ) {
				if ( Translations::DOMAIN !== $domain || ! is_string( $file ) ) {
					return $file;
				}

				return $container->get( Locale_Fallback::class )->script_file( $file );
			},
			10,
			3
		);

		// Una actualización por zip no pasa por el hook de activación: se detecta en la administración.
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( Installer::class )->maybe_upgrade();
			}
		);

		add_action(
			'init',
			static function () use ( $container ): void {
				$container->get( Translations::class )->register();
			},
			1
		);
	}
}
